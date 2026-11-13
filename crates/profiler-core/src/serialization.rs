//! Budgeted JSON encoding and private atomic filesystem publication.
use crate::{Budget, BudgetError, BudgetVec, Report, Reservation};
use std::{
    fmt,
    fs::{self, OpenOptions},
    io::{self, Write},
    ops::Deref,
    path::{Path, PathBuf},
    sync::atomic::{AtomicU64, Ordering},
};

const JSON_LIMIT: usize = 16 * 1024 * 1024;
#[derive(Debug)]
pub enum JsonError {
    TooLarge,
    Budget(BudgetError),
    Serialization(serde_json::Error),
}
impl fmt::Display for JsonError {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        match self {
            Self::TooLarge => {
                f.write_str("report exceeds 16 MiB PHP accessor limit; use local report file")
            }
            Self::Budget(error) => error.fmt(f),
            Self::Serialization(error) => error.fmt(f),
        }
    }
}
impl std::error::Error for JsonError {
    fn source(&self) -> Option<&(dyn std::error::Error + 'static)> {
        match self {
            Self::TooLarge => None,
            Self::Budget(e) => Some(e),
            Self::Serialization(e) => Some(e),
        }
    }
}
/// Charged JSON storage includes the trailing NUL needed by Zend's parser.
/// The reservation remains live while either native PHP conversion copies it.
pub struct JsonBuffer {
    text: String,
    _reservation: Reservation,
}
impl JsonBuffer {
    pub fn as_str(&self) -> &str {
        &self.text[..self.text.len() - 1]
    }
    pub fn as_nul_terminated_bytes(&self) -> &[u8] {
        self.text.as_bytes()
    }
}
impl Deref for JsonBuffer {
    type Target = str;
    fn deref(&self) -> &str {
        self.as_str()
    }
}
impl AsRef<str> for JsonBuffer {
    fn as_ref(&self) -> &str {
        self.as_str()
    }
}
impl fmt::Debug for JsonBuffer {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("JsonBuffer")
            .field("len", &self.as_str().len())
            .finish()
    }
}
impl Report {
    pub fn json(&self) -> Result<JsonBuffer, JsonError> {
        // Counting never returns a writer error: a known size limit must not
        // cause serde_json to allocate an error box at an exhausted budget.
        let mut count = CountingWriter {
            len: 0,
            exceeded: false,
        };
        serde_json::to_writer(&mut count, self).map_err(JsonError::Serialization)?;
        if count.exceeded {
            return Err(JsonError::TooLarge);
        }
        let mut writer = JsonWriter {
            bytes: BudgetVec::try_with_capacity(count.len + 1, &self.budget())
                .map_err(JsonError::Budget)?,
        };
        serde_json::to_writer(&mut writer, self).map_err(JsonError::Serialization)?;
        writer.bytes.push(0).map_err(JsonError::Budget)?;
        let (bytes, reservation) = writer.bytes.into_parts();
        let text = String::from_utf8(bytes).expect("JSON serializer emitted invalid UTF-8");
        Ok(JsonBuffer {
            text,
            _reservation: reservation,
        })
    }
    /// Stream to a private temporary inode, publish without replacing any report.
    pub fn write_atomic(&self, path: &Path) -> io::Result<()> {
        static NEXT: AtomicU64 = AtomicU64::new(0);
        let directory = path.parent().unwrap_or(Path::new("."));
        let (temporary, mut file) = reserve_temporary(directory, &NEXT, &self.budget())?;
        let result = (|| {
            write_report(self, &mut file)?;
            file.sync_all()?;
            fs::hard_link(&temporary, path)?;
            Ok(())
        })();
        let _ = fs::remove_file(temporary);
        result
    }
}
struct CountingWriter {
    len: usize,
    exceeded: bool,
}

// Preserve the first actual I/O error without asking serde_json to allocate
// an error box for an already-known writer failure. No output is published.
struct DeferredWriter<'a, W> {
    inner: &'a mut W,
    first_error: Option<io::Error>,
}
impl<W: Write> Write for DeferredWriter<'_, W> {
    fn write(&mut self, bytes: &[u8]) -> io::Result<usize> {
        if self.first_error.is_none() {
            self.first_error = self.inner.write_all(bytes).err();
        }
        Ok(bytes.len())
    }
    fn flush(&mut self) -> io::Result<()> {
        Ok(())
    }
}
// Keep serializer token writes off the filesystem without another heap owner.
// A failed stream is discarded; this type never flushes implicitly on Drop.
struct StackWriter<'a, W> {
    inner: &'a mut W,
    bytes: [u8; 8192],
    len: usize,
}
impl<W: Write> StackWriter<'_, W> {
    fn flush_buffer(&mut self) -> io::Result<()> {
        self.inner.write_all(&self.bytes[..self.len])?;
        self.len = 0;
        Ok(())
    }
}
impl<W: Write> Write for StackWriter<'_, W> {
    fn write(&mut self, bytes: &[u8]) -> io::Result<usize> {
        if self.len == self.bytes.len() {
            self.flush_buffer()?;
        }
        let count = bytes.len().min(self.bytes.len() - self.len);
        self.bytes[self.len..self.len + count].copy_from_slice(&bytes[..count]);
        self.len += count;
        Ok(count)
    }
    fn flush(&mut self) -> io::Result<()> {
        self.flush_buffer()?;
        self.inner.flush()
    }
}
fn write_report(report: &Report, file: &mut impl Write) -> io::Result<()> {
    let mut file = StackWriter {
        inner: file,
        bytes: [0; 8192],
        len: 0,
    };
    let mut writer = DeferredWriter {
        inner: &mut file,
        first_error: None,
    };
    serde_json::to_writer(&mut writer, report).map_err(io::Error::other)?;
    if let Some(error) = writer.first_error {
        return Err(error);
    }
    file.flush()
}
impl Write for CountingWriter {
    fn write(&mut self, bytes: &[u8]) -> io::Result<usize> {
        match self.len.checked_add(bytes.len()) {
            Some(len) => {
                self.len = len;
                self.exceeded |= len > JSON_LIMIT;
            }
            None => {
                self.exceeded = true;
            }
        }
        Ok(bytes.len())
    }
    fn flush(&mut self) -> io::Result<()> {
        Ok(())
    }
}
struct JsonWriter {
    bytes: BudgetVec<u8>,
}
impl Write for JsonWriter {
    fn write(&mut self, bytes: &[u8]) -> io::Result<usize> {
        self.bytes.extend_bytes(bytes).map_err(budget_io_error)?;
        Ok(bytes.len())
    }
    fn flush(&mut self) -> io::Result<()> {
        Ok(())
    }
}
fn budget_io_error(error: BudgetError) -> io::Error {
    io::Error::from(match error {
        BudgetError::Overflow => io::ErrorKind::InvalidInput,
        BudgetError::Limit | BudgetError::Allocation => io::ErrorKind::OutOfMemory,
    })
}
struct TemporaryPath {
    path: PathBuf,
    _reservation: Reservation,
}
impl AsRef<Path> for TemporaryPath {
    fn as_ref(&self) -> &Path {
        &self.path
    }
}
impl Deref for TemporaryPath {
    type Target = Path;
    fn deref(&self) -> &Path {
        &self.path
    }
}
struct NameBuffer {
    bytes: [u8; 96],
    len: usize,
}
impl fmt::Write for NameBuffer {
    fn write_str(&mut self, text: &str) -> fmt::Result {
        let end = self.len.checked_add(text.len()).ok_or(fmt::Error)?;
        let output = self.bytes.get_mut(self.len..end).ok_or(fmt::Error)?;
        output.copy_from_slice(text.as_bytes());
        self.len = end;
        Ok(())
    }
}
fn reserve_temporary(
    directory: &Path,
    next: &AtomicU64,
    budget: &Budget,
) -> io::Result<(TemporaryPath, fs::File)> {
    use std::os::unix::{ffi::OsStringExt, fs::OpenOptionsExt};
    let mut options = OpenOptions::new();
    options.write(true).create_new(true).mode(0o600);
    for _ in 0..128 {
        let mut name = NameBuffer {
            bytes: [0; 96],
            len: 0,
        };
        fmt::write(
            &mut name,
            format_args!(
                ".php-profiler-{}-{}.tmp",
                std::process::id(),
                next.fetch_add(1, Ordering::Relaxed)
            ),
        )
        .expect("fixed-width temporary basename fits");
        let directory_bytes = directory.as_os_str().as_encoded_bytes();
        let separator = !directory_bytes.is_empty() && !directory_bytes.ends_with(b"/");
        // A valid byte slice is at most isize::MAX bytes. The fixed 96-byte
        // basename leaves ample usize headroom on the supported 64-bit targets.
        let capacity = directory_bytes.len() + usize::from(separator) + name.len;
        let mut bytes = BudgetVec::try_with_capacity(capacity, budget).map_err(budget_io_error)?;
        bytes
            .extend_bytes(directory_bytes)
            .map_err(budget_io_error)?;
        if separator {
            bytes.push(b'/').map_err(budget_io_error)?;
        }
        bytes
            .extend_bytes(&name.bytes[..name.len])
            .map_err(budget_io_error)?;
        let (bytes, reservation) = bytes.into_parts();
        let temporary = TemporaryPath {
            path: PathBuf::from(std::ffi::OsString::from_vec(bytes)),
            _reservation: reservation,
        };
        match options.open(&temporary) {
            Ok(file) => return Ok((temporary, file)),
            Err(error) if error.kind() == io::ErrorKind::AlreadyExists => continue,
            Err(error) => return Err(error),
        }
    }
    Err(io::Error::from(io::ErrorKind::AlreadyExists))
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::{BudgetText, Engine};

    fn private_directory(label: &str) -> PathBuf {
        static NEXT_DIRECTORY: AtomicU64 = AtomicU64::new(0);
        private_directory_in(&std::env::temp_dir(), label, &NEXT_DIRECTORY)
    }
    fn private_directory_in(root: &Path, label: &str, next: &AtomicU64) -> PathBuf {
        loop {
            let sequence = next.fetch_add(1, Ordering::Relaxed);
            let directory = root.join(format!(
                "profiler-core-{label}-{}-{sequence}",
                std::process::id()
            ));
            match fs::create_dir(&directory) {
                Ok(()) => return directory,
                Err(error) if error.kind() == io::ErrorKind::AlreadyExists => continue,
                Err(error) => panic!("cannot create private test directory: {error}"),
            }
        }
    }

    #[test]
    fn private_test_directories_retry_collisions_and_report_filesystem_failures() {
        let root = private_directory("fixture-contract");
        let next = AtomicU64::new(0);
        let occupied = root.join(format!("profiler-core-retry-{}-0", std::process::id()));
        fs::create_dir(&occupied).unwrap();
        let created = private_directory_in(&root, "retry", &next);
        assert_ne!(created, occupied);
        assert!(created.ends_with(format!("profiler-core-retry-{}-1", std::process::id())));
        assert_eq!(next.load(Ordering::Relaxed), 2);
        assert_eq!(fs::read_dir(&root).unwrap().count(), 2);
        let failure = std::panic::catch_unwind(|| {
            private_directory_in(&root.join("missing"), "failure", &AtomicU64::new(0))
        })
        .unwrap_err();
        assert!(failure
            .downcast_ref::<String>()
            .unwrap()
            .starts_with("cannot create private test directory: "));
        assert!(!root.join("missing").exists());
        fs::remove_dir_all(root).unwrap();
    }

    #[test]
    fn json_ownership_quota_and_nul_sentinel_are_exact() {
        let report = Engine::new(0).finish(0, 0, 0);
        let budget = report.budget();
        let before = budget.used();
        let json = report.json().unwrap();
        assert_eq!(json.as_nul_terminated_bytes().last(), Some(&0));
        assert_eq!(json.as_nul_terminated_bytes().len(), json.len() + 1);
        assert!(!json.as_str().as_bytes().contains(&0));
        assert_eq!(AsRef::<str>::as_ref(&json), json.as_str());
        assert!(format!("{json:?}").starts_with("JsonBuffer { len: "));
        assert_eq!(budget.used(), before + json.len() + 1);
        drop(json);
        assert_eq!(budget.used(), before);
        let blocker = budget.try_reserve(budget.available()).unwrap();
        assert!(matches!(
            report.json(),
            Err(JsonError::Budget(BudgetError::Limit))
        ));
        drop(blocker);
        assert!(report.json().is_ok());
    }
    #[test]
    fn exact_sixteen_mebibyte_json_is_accepted_and_next_byte_requires_streaming() {
        let mut report = Engine::new(0).finish(0, 0, 0);
        report.limitations[0] = "".into();
        let overhead = report.json().unwrap().len();
        let payload_len = JSON_LIMIT - overhead;
        report.limitations[0] =
            BudgetText::try_copy(&"x".repeat(payload_len), &report.budget()).unwrap();
        let json = report.json().unwrap();
        assert_eq!(json.len(), JSON_LIMIT);
        assert_eq!(json.as_nul_terminated_bytes().len(), JSON_LIMIT + 1);
        drop(json);
        report.limitations[0] =
            BudgetText::try_copy(&"x".repeat(payload_len + 1), &report.budget()).unwrap();
        assert!(matches!(report.json(), Err(JsonError::TooLarge)));
        let directory = private_directory("large-stream");
        let path = directory.join("large.json");
        report.write_atomic(&path).unwrap();
        assert_eq!(fs::metadata(&path).unwrap().len(), (JSON_LIMIT + 1) as u64);
        fs::remove_dir_all(directory).unwrap();
    }
    #[test]
    fn temporary_names_support_relative_and_trailing_separator_directories() {
        let budget = Budget::new(crate::DEFAULT_BUDGET_BYTES);
        let (relative, file) =
            reserve_temporary(Path::new(""), &AtomicU64::new(u64::MAX), &budget).unwrap();
        drop(file);
        assert_eq!(relative.parent(), Some(Path::new("")));
        fs::remove_file(relative).unwrap();
        let dir = private_directory("separators");
        let trailing = dir.join("");
        assert!(trailing.as_os_str().as_encoded_bytes().ends_with(b"/"));
        let (path, file) = reserve_temporary(&trailing, &AtomicU64::new(0), &budget).unwrap();
        drop(file);
        assert_eq!(path.parent(), Some(dir.as_path()));
        fs::remove_file(path).unwrap();
        fs::remove_dir_all(dir).unwrap();
        assert_eq!(budget.used(), Budget::control_bytes());
    }
    #[test]
    fn size_counters_and_filename_buffers_reject_overflow_without_partial_mutation() {
        let mut count = CountingWriter {
            len: JSON_LIMIT,
            exceeded: false,
        };
        assert_eq!(count.write(b"x").unwrap(), 1);
        assert!(count.exceeded);
        count.flush().unwrap();
        count.len = usize::MAX;
        assert_eq!(count.write(b"x").unwrap(), 1);
        assert!(count.exceeded);
        assert_eq!(count.len, usize::MAX);
        let mut name = NameBuffer {
            bytes: [0; 96],
            len: 0,
        };
        fmt::Write::write_str(&mut name, &"x".repeat(96)).unwrap();
        assert!(fmt::Write::write_str(&mut name, "y").is_err());
        assert_eq!(name.len, 96);
        assert_eq!(name.bytes, [b'x'; 96]);
        name.len = usize::MAX;
        assert!(fmt::Write::write_str(&mut name, "y").is_err());
    }
    #[test]
    fn explicit_json_and_io_errors_preserve_their_causes() {
        use std::error::Error;
        let limit = JsonError::TooLarge;
        assert!(limit.to_string().contains("16 MiB"));
        assert!(limit.source().is_none());
        let allocation = JsonError::Budget(BudgetError::Allocation);
        assert_eq!(allocation.to_string(), "native profiler allocation failed");
        assert!(allocation.source().is_some());
        let invalid = serde_json::from_str::<serde_json::Value>("{").unwrap_err();
        let invalid = JsonError::Serialization(invalid);
        assert!(invalid.to_string().contains("EOF"));
        assert!(invalid.source().is_some());
        for (cause, kind) in [
            (BudgetError::Limit, io::ErrorKind::OutOfMemory),
            (BudgetError::Allocation, io::ErrorKind::OutOfMemory),
            (BudgetError::Overflow, io::ErrorKind::InvalidInput),
        ] {
            assert_eq!(budget_io_error(cause).kind(), kind);
        }
    }
    #[test]
    fn report_stream_coalesces_tokens_and_preserves_short_write_and_flush_errors() {
        struct Sink {
            bytes: Vec<u8>,
            calls: usize,
            interrupt: bool,
            zero: bool,
            flush_error: bool,
            max_write: usize,
            largest_input: usize,
        }
        impl Write for Sink {
            fn write(&mut self, bytes: &[u8]) -> io::Result<usize> {
                self.calls += 1;
                self.largest_input = self.largest_input.max(bytes.len());
                if std::mem::take(&mut self.interrupt) {
                    return Err(io::ErrorKind::Interrupted.into());
                }
                if self.zero {
                    return Ok(0);
                }
                let count = bytes.len().min(self.max_write);
                self.bytes.extend_from_slice(&bytes[..count]);
                Ok(count)
            }
            fn flush(&mut self) -> io::Result<()> {
                if self.flush_error {
                    Err(io::ErrorKind::PermissionDenied.into())
                } else {
                    Ok(())
                }
            }
        }
        let report = Engine::new(0).finish(0, 0, 0);
        let expected = report.json().unwrap();
        let mut sink = Sink {
            bytes: Vec::new(),
            calls: 0,
            interrupt: true,
            zero: false,
            flush_error: false,
            max_write: 17,
            largest_input: 0,
        };
        write_report(&report, &mut sink).unwrap();
        assert_eq!(sink.bytes, expected.as_str().as_bytes());
        assert_eq!(sink.calls, expected.len().div_ceil(17) + 1);
        let mut engine = Engine::new(100);
        engine.start(0, 0, 0);
        for index in 0..100 {
            let name = format!("stream_{index:04}_{}", "é".repeat(30));
            engine.enter(index, &name, index * 2 + 1, index * 2 + 1, 0);
            engine.exit(index, index * 2 + 2, index * 2 + 2, 0);
        }
        let large = engine.finish(201, 201, 0);
        let large_json = large.json().unwrap();
        sink.bytes.clear();
        sink.calls = 0;
        sink.max_write = usize::MAX;
        serde_json::to_writer(&mut sink, &large).unwrap();
        assert_eq!(sink.bytes, large_json.as_str().as_bytes());
        let direct_calls = sink.calls;
        sink.bytes.clear();
        sink.calls = 0;
        sink.largest_input = 0;
        write_report(&large, &mut sink).unwrap();
        assert_eq!(sink.bytes, large_json.as_str().as_bytes());
        assert_eq!(sink.calls, large_json.len().div_ceil(8192));
        assert_eq!(sink.largest_input, 8192);
        assert!(sink.calls < direct_calls);
        println!(
            "{} identical JSON bytes: {direct_calls} direct writes, {} buffered writes",
            large_json.len(),
            sink.calls
        );
        sink.zero = true;
        assert_eq!(
            write_report(&large, &mut sink).unwrap_err().kind(),
            io::ErrorKind::WriteZero
        );
        assert_eq!(
            write_report(&report, &mut sink).unwrap_err().kind(),
            io::ErrorKind::WriteZero
        );
        sink.zero = false;
        sink.flush_error = true;
        assert_eq!(
            write_report(&report, &mut sink).unwrap_err().kind(),
            io::ErrorKind::PermissionDenied
        );
    }

    #[test]
    fn stream_errors_and_temporary_path_quota_do_not_publish_or_mutate_existing_data() {
        let dir = private_directory("io-errors");
        let source = dir.join("readonly");
        fs::write(&source, b"keep").unwrap();
        let report = Engine::new(0).finish(0, 0, 0);
        let mut file = fs::File::open(&source).unwrap();
        assert!(write_report(&report, &mut file).is_err());
        assert_eq!(fs::read(&source).unwrap(), b"keep");
        let mut sink = Vec::new();
        let mut writer = DeferredWriter {
            inner: &mut sink,
            first_error: None,
        };
        writer.write_all(b"ok").unwrap();
        writer.flush().unwrap();
        assert!(writer.first_error.is_none());
        let budget = report.budget();
        let used = budget.used();
        let block = budget.try_reserve(budget.available()).unwrap();
        let destination = dir.join("report.json");
        assert_eq!(
            report.write_atomic(&destination).unwrap_err().kind(),
            io::ErrorKind::OutOfMemory
        );
        assert!(!destination.exists());
        assert_eq!(fs::read_dir(&dir).unwrap().count(), 1);
        drop(block);
        report.write_atomic(&destination).unwrap();
        assert_eq!(budget.used(), used);
        fs::remove_dir_all(dir).unwrap();
    }
    #[test]
    fn json_and_atomic_persistence_never_replace_existing_report() {
        let dir = private_directory("test");
        let path = dir.join("report.json");
        let r = Engine::new(0).finish(0, 0, 0);
        let value: serde_json::Value = serde_json::from_str(&r.json().unwrap()).unwrap();
        assert_eq!(value["schema_version"], 1);
        r.write_atomic(&path).unwrap();
        let old = fs::read(&path).unwrap();
        assert_eq!(
            r.write_atomic(&path).unwrap_err().kind(),
            io::ErrorKind::AlreadyExists
        );
        assert_eq!(fs::read(&path).unwrap(), old);
        assert_eq!(
            r.write_atomic(&dir.join("missing/report.json"))
                .unwrap_err()
                .kind(),
            io::ErrorKind::NotFound
        );
        assert_eq!(fs::read_dir(&dir).unwrap().count(), 1);
        fs::remove_dir_all(dir).unwrap();
    }
    #[test]
    fn temporary_collisions_are_retried_and_exhaustion_is_reported() {
        let dir = private_directory("collisions");
        for id in 0..128 {
            fs::write(
                dir.join(format!(".php-profiler-{}-{id}.tmp", std::process::id())),
                b"occupied",
            )
            .unwrap();
        }
        assert_eq!(
            reserve_temporary(
                &dir,
                &AtomicU64::new(0),
                &Budget::new(crate::DEFAULT_BUDGET_BYTES)
            )
            .err()
            .expect("all temporary names are occupied")
            .kind(),
            io::ErrorKind::AlreadyExists
        );
        let (path, file) = reserve_temporary(
            &dir,
            &AtomicU64::new(127),
            &Budget::new(crate::DEFAULT_BUDGET_BYTES),
        )
        .unwrap();
        drop(file);
        assert!(path.ends_with(format!(".php-profiler-{}-128.tmp", std::process::id())));
        fs::remove_dir_all(dir).unwrap();
    }
    #[test]
    fn concurrent_publication_has_exactly_one_winner() {
        let dir = private_directory("race");
        let path = dir.join("report.json");
        let handles: Vec<_> = (0..8)
            .map(|_| {
                let path = path.clone();
                std::thread::spawn(move || Engine::new(0).finish(0, 0, 0).write_atomic(&path))
            })
            .collect();
        let mut winners = 0;
        for handle in handles {
            match handle.join().unwrap() {
                Ok(()) => winners += 1,
                Err(e) => assert_eq!(e.kind(), io::ErrorKind::AlreadyExists),
            }
        }
        assert_eq!(winners, 1);
        let _: serde_json::Value = serde_json::from_slice(&fs::read(path).unwrap()).unwrap();
        assert_eq!(fs::read_dir(&dir).unwrap().count(), 1);
        fs::remove_dir_all(dir).unwrap();
    }
    #[test]
    fn accessor_writer_caps_output_without_partial_acceptance() {
        let budget = Budget::new(crate::DEFAULT_BUDGET_BYTES);
        let mut writer = JsonWriter {
            bytes: BudgetVec::try_with_capacity(3, &budget).unwrap(),
        };
        writer.write_all(b"abc").unwrap();
        assert!(writer.write_all(b"d").is_err());
        assert_eq!(&*writer.bytes, b"abc");
        writer.flush().unwrap();
        let mut r = Engine::new(1).finish(0, 0, 0);
        r.limitations[0] =
            BudgetText::try_copy(&"x".repeat(16 * 1024 * 1024), &r.budget()).unwrap();
        assert!(r.json().is_err());
    }
    #[test]
    fn json_accessor_accepts_reports_below_sixteen_mebibytes() {
        let mut r = Engine::new(0).finish(0, 0, 0);
        r.limitations[0] = BudgetText::try_copy(&"x".repeat(2_000_000), &r.budget()).unwrap();
        assert!(r.json().unwrap().len() > 2_000_000);
    }
    #[test]
    fn schema_contract_declares_all_report_metric_and_event_fields() {
        let schema: serde_json::Value =
            serde_json::from_str(include_str!("../../../schema/report-v1.json")).unwrap();
        let mut e = Engine::with_timeline(1, true);
        e.enter(1, "example", 0, 0, 0);
        e.exit_exception(1, 1, 1, 1);
        let value = serde_json::to_value(e.finish(2, 2, 2)).unwrap();
        for (instance, definition) in [
            (&value, &schema),
            (&value["functions"]["example"], &schema["$defs"]["metrics"]),
            (
                &value["timeline"][0],
                &schema["properties"]["timeline"]["items"],
            ),
        ] {
            let actual: std::collections::BTreeSet<_> = instance
                .as_object()
                .unwrap()
                .keys()
                .map(String::as_str)
                .collect();
            let required: std::collections::BTreeSet<_> = definition["required"]
                .as_array()
                .unwrap()
                .iter()
                .map(|k| k.as_str().unwrap())
                .collect();
            let declared: std::collections::BTreeSet<_> = definition["properties"]
                .as_object()
                .unwrap()
                .keys()
                .map(String::as_str)
                .collect();
            assert_eq!(actual, required);
            assert_eq!(actual, declared);
        }
    }
}
