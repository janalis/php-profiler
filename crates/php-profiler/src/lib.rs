//! Runtime-controlled PHP profiling. Zend pointers never escape a callback.
#[cfg(all(profiler_adapter_contract_tests, not(test)))]
#[path = "../../../tests/support/adapter-contract.rs"]
mod adapter_contract_tests;
use ext_php_rs::prelude::*;
use ext_php_rs::types::{ZendHashTable, Zval};
use profiler_core::{
    Budget, BudgetError, BudgetText, DEFAULT_BUDGET_BYTES, Engine, JsonBuffer, Report,
};
mod automatic;
mod sampling;
mod storage;
use std::fmt::Write as _;
use std::{
    cell::{Cell, RefCell},
    sync::atomic::{AtomicU64, Ordering},
};
use storage::{
    FixedPath, GeneratedNames, INPUT_PATH_BYTES, Identities, OUTPUT_PATH_BYTES, SESSION_CAPACITY,
    SYMBOL_BUFFER_BYTES, Slots, SymbolBuffer,
};

const METADATA_INPUT_BYTES: usize = 128;
const DEFAULT_CAPTURE_RECORDS: usize = 512;
// A larger per-session frame pool cannot exceed the request's identity table.
const MAX_CAPTURE_RECORDS: i64 = storage::IDENTITY_CAPACITY as i64;
const METADATA_DISPLAY_BYTES: usize = b"{bytes:".len() + 2 * METADATA_INPUT_BYTES + b"}".len();
const _: () = assert!(METADATA_DISPLAY_BYTES <= SYMBOL_BUFFER_BYTES);

unsafe extern "C" {
    fn profiler_register() -> i32;
    fn profiler_set_active(active: i32);
    fn profiler_set_context_tracking(tracking: i32);
    fn profiler_reset_clocks();
    fn profiler_register_fibers();
    fn profiler_context() -> u64;
    fn profiler_json_decode(text: *const u8, len: usize, out: *mut Zval) -> i32;
    fn profiler_string(text: *const u8, len: usize, out: *mut Zval);
    fn profiler_clock_failed() -> i32;
    #[cfg_attr(
        all(profiler_adapter_contract_tests, not(test)),
        link_name = "profiler_contract_php_version"
    )]
    fn profiler_php_version() -> *const std::ffi::c_char;
    fn profiler_path_allowed(path: *const std::ffi::c_char) -> i32;
    #[cfg_attr(
        all(profiler_adapter_contract_tests, not(test)),
        link_name = "profiler_contract_resolve_path"
    )]
    fn profiler_resolve_path(
        path: *const std::ffi::c_char,
        output: *mut u8,
        capacity: usize,
    ) -> i32;
    #[cfg_attr(
        all(profiler_adapter_contract_tests, not(test)),
        link_name = "profiler_contract_sapi"
    )]
    fn profiler_sapi() -> *const std::ffi::c_char;
    fn strnlen(value: *const std::ffi::c_char, maximum: usize) -> usize;
    fn profiler_is_zts() -> i32;
    fn profiler_ini(name: *const u8, length: usize, value_length: *mut usize) -> *const u8;
    fn profiler_wall() -> u64;
    fn profiler_cpu() -> u64;
    fn profiler_memory() -> u64;
    fn profiler_request_peak() -> u64;
    fn profiler_has_zend_mm() -> i32;
}
fn snapshot() -> (u64, u64, u64) {
    // SAFETY: these read OS clocks and current-thread Zend counters without allocation.
    unsafe { (profiler_wall(), profiler_cpu(), profiler_memory()) }
}
#[derive(Clone, Copy)]
struct MetadataBytes {
    bytes: [u8; METADATA_INPUT_BYTES],
    length: usize,
}
impl MetadataBytes {
    fn copy(bytes: &[u8]) -> Self {
        assert!(
            bytes.len() <= METADATA_INPUT_BYTES,
            "metadata length checked before copying"
        );
        let mut value = Self {
            bytes: [0; METADATA_INPUT_BYTES],
            length: bytes.len(),
        };
        value.bytes[..bytes.len()].copy_from_slice(bytes);
        value
    }
    fn display(&self) -> SymbolBuffer {
        let mut value = SymbolBuffer::new();
        value
            .identifier(&self.bytes[..self.length])
            .expect("bounded metadata fits symbol buffer");
        value
    }
}
#[derive(Clone, Copy)]
enum MetadataFact {
    Missing,
    Omitted,
    Present(MetadataBytes),
}
const INI_METADATA: [(&str, &str); 4] = [
    ("opcache.enable", "opcache.enable_configured"),
    ("opcache.enable_cli", "opcache.enable_cli_configured"),
    ("opcache.jit", "opcache.jit_configured"),
    (
        "opcache.jit_buffer_size",
        "opcache.jit_buffer_size_configured",
    ),
];
#[derive(Clone, Copy)]
struct StopFacts {
    boundary: (u64, u64, u64),
    observer_failed: bool,
    clock_failed: bool,
    request_peak: Option<u64>,
    zts: bool,
    reason: &'static str,
    metadata: [MetadataFact; 6],
}
impl StopFacts {
    /// All facts live inline in the already admitted session slot; sealing allocates nothing.
    fn capture(reason: &'static str) -> Self {
        let boundary = snapshot();
        // SAFETY: these providers read native request state without allocating or invoking PHP.
        let request_peak = if unsafe { profiler_has_zend_mm() } != 0 {
            Some(unsafe { profiler_request_peak() })
        } else {
            None
        };
        let mut metadata = [MetadataFact::Missing; 6];
        for (index, pointer) in [unsafe { profiler_php_version() }, unsafe {
            profiler_sapi()
        }]
        .into_iter()
        .enumerate()
        {
            // SAFETY: each native provider returns a valid module-owned C string.
            metadata[index] = match unsafe { engine_identity(pointer) } {
                Some(value) => MetadataFact::Present(value),
                None => MetadataFact::Omitted,
            };
        }
        for (index, (name, _)) in INI_METADATA.iter().enumerate() {
            let mut length = 0;
            let pointer = unsafe { profiler_ini(name.as_ptr(), name.len(), &mut length) };
            if !pointer.is_null() {
                metadata[index + 2] = if length > METADATA_INPUT_BYTES {
                    MetadataFact::Omitted
                } else {
                    // SAFETY: native lookup returns the valid exact byte range, already bounded.
                    MetadataFact::Present(MetadataBytes::copy(unsafe {
                        std::slice::from_raw_parts(pointer, length)
                    }))
                };
            }
        }
        Self {
            boundary,
            observer_failed: FAILED.get(),
            clock_failed: unsafe { profiler_clock_failed() } != 0,
            request_peak,
            zts: unsafe { profiler_is_zts() } != 0,
            reason,
            metadata,
        }
    }
}
struct SessionRecord {
    id: i64,
    engine: Option<Engine>,
    report: Option<Report>,
    output: Option<FixedPath<INPUT_PATH_BYTES>>,
    written: Option<FixedPath<OUTPUT_PATH_BYTES>>,
    context: u64,
    reason: &'static str,
    frame_floor: u64,
    stop_facts: Option<StopFacts>,
    sampling: Option<sampling::Tracker>,
}
#[derive(Default)]
struct State {
    enabled: bool,
    budget: Option<Budget>,
    next_id: i64,
    sessions: Slots<SessionRecord>,
    frames: Identities,
    next_frame: u64,
    contexts: Identities,
    default_output: Option<FixedPath<INPUT_PATH_BYTES>>,
    sampling: Option<sampling::Adapter>,
    next_context: u64,
    generated_functions: GeneratedNames,
    generated_classes: GeneratedNames,
}
thread_local! { static FAILED: Cell<bool> = const {Cell::new(false)}; static STATE: RefCell<State> = RefCell::new(State::default()); }
fn budget_error(cause: BudgetError) -> PhpException {
    error(match cause {
        BudgetError::Limit => "Native profiler memory budget exhausted",
        BudgetError::Overflow => "Native profiler allocation size overflow",
        BudgetError::Allocation => "Native profiler allocation failed",
    })
}
fn prepare_capture(state: &mut State) -> Result<Budget, BudgetError> {
    let budget = match &state.budget {
        Some(budget) => budget.clone(),
        None => {
            let budget = Budget::try_new(DEFAULT_BUDGET_BYTES)?;
            state.budget = Some(budget.clone());
            budget
        }
    };
    state.sessions.initialize(SESSION_CAPACITY, &budget)?;
    state.frames.initialize(&budget)?;
    state.contexts.initialize(&budget)?;
    state.generated_functions.initialize(&budget)?;
    state.generated_classes.initialize(&budget)?;
    Ok(budget)
}
/// Never unwind through a Zend C callback; a caught panic invalidates active reports.
fn ffi_guard(callback: impl FnOnce() + std::panic::UnwindSafe) {
    if std::panic::catch_unwind(callback).is_err() {
        FAILED.set(true);
    }
}
#[cfg(test)]
mod guard_tests {
    use super::{FAILED, ffi_guard};
    fn identifier_display(bytes: &[u8]) -> String {
        let mut value = super::SymbolBuffer::new();
        value.identifier(bytes).unwrap();
        value.as_str().to_owned()
    }
    #[test]
    fn maximum_output_basename_and_native_directory_fit_published_capacity() {
        use super::{
            FixedPath, INPUT_PATH_BYTES, OUTPUT_BASENAME_BYTES, OUTPUT_PATH_BYTES,
            directory_output_path,
        };
        let basename = "php-profile-4294967295--9223372036854775808-18446744073709551615-18446744073709551615.json";
        assert_eq!(basename.len(), OUTPUT_BASENAME_BYTES);
        for length in [INPUT_PATH_BYTES - 1, INPUT_PATH_BYTES] {
            let directory = FixedPath::<INPUT_PATH_BYTES>::copy(&vec![b'd'; length]).unwrap();
            let result = directory_output_path(&directory, u32::MAX, i64::MIN, u64::MAX, u64::MAX);
            assert_eq!(result.bytes().len(), length + 1 + OUTPUT_BASENAME_BYTES);
            assert!(result.bytes().len() <= OUTPUT_PATH_BYTES);
            assert_eq!(&result.bytes()[..length], directory.bytes());
            assert_eq!(result.bytes()[length], b'/');
            assert!(result.bytes().ends_with(basename.as_bytes()));
        }
        let directory = FixedPath::<INPUT_PATH_BYTES>::copy(b"/already/").unwrap();
        let result = directory_output_path(&directory, u32::MAX, i64::MIN, u64::MAX, u64::MAX);
        assert_eq!(
            result.bytes().len(),
            directory.bytes().len() + OUTPUT_BASENAME_BYTES
        );
        assert!(result.bytes().starts_with(b"/already/php-profile-"));
    }
    #[test]
    fn byte_identifiers_and_reserved_prefix_are_lossless_and_distinct() {
        assert_eq!(identifier_display(b"normal"), "normal");
        assert_eq!(identifier_display(b"raw_\x80"), "{bytes:7261775f80}");
        assert_eq!(identifier_display(b"raw_\x81"), "{bytes:7261775f81}");
        assert_ne!(
            identifier_display(b"{bytes:80}"),
            identifier_display(b"\x80")
        );
        for literal in [
            "anonymous-class#1",
            "sample-anonymous-class#1",
            "{sample-closure#1}",
        ] {
            let expected = format!(
                "{{bytes:{}}}",
                literal
                    .as_bytes()
                    .iter()
                    .map(|byte| format!("{byte:02x}"))
                    .collect::<String>()
            );
            assert_eq!(
                identifier_display(literal.as_bytes()),
                expected,
                "literal identifiers must not impersonate generated identities"
            );
        }
    }
    #[test]
    fn engine_identity_preserves_bounded_values_and_omits_large_inputs() {
        for length in [0, 128, 129, 8192] {
            let input = std::ffi::CString::new(vec![b's'; length]).unwrap();
            // SAFETY: CString owns a valid NUL-terminated allocation for this call.
            let value = unsafe { super::engine_identity(input.as_ptr()) }
                .map(|value| value.display().as_str().to_owned());
            if length <= 128 {
                assert_eq!(value, Some("s".repeat(length)));
            } else {
                assert_eq!(value, None);
            }
        }
        for (bytes, expected) in [
            (vec![0x80], "{bytes:80}"),
            (b"{bytes:80}".to_vec(), "{bytes:7b62797465733a38307d}"),
        ] {
            let input = std::ffi::CString::new(bytes).unwrap();
            // SAFETY: CString remains alive and owns its terminating NUL.
            assert_eq!(
                unsafe { super::engine_identity(input.as_ptr()) }
                    .map(|value| value.display().as_str().to_owned()),
                Some(expected.into())
            );
        }
    }
    #[test]
    fn maximum_metadata_byte_encodings_fit_the_reserved_stack_capacity() {
        let mut reserved = vec![b'a'; super::METADATA_INPUT_BYTES];
        reserved[..b"{bytes:".len()].copy_from_slice(b"{bytes:");
        for (bytes, expected) in [
            (
                vec![0x80; super::METADATA_INPUT_BYTES],
                format!("{{bytes:{}}}", "80".repeat(super::METADATA_INPUT_BYTES)),
            ),
            (
                reserved,
                format!(
                    "{{bytes:7b62797465733a{}}}",
                    "61".repeat(super::METADATA_INPUT_BYTES - 7)
                ),
            ),
        ] {
            let input = std::ffi::CString::new(bytes).unwrap();
            // SAFETY: input owns the complete valid NUL-terminated byte range.
            let value = unsafe { super::engine_identity(input.as_ptr()) }.unwrap();
            assert_eq!(value.display().as_str(), expected);
            assert_eq!(
                value.display().as_str().len(),
                super::METADATA_DISPLAY_BYTES
            );
        }
    }
    #[test]
    fn callback_panic_is_contained_and_marks_measurements_invalid() {
        FAILED.set(false);
        ffi_guard(|| panic!("synthetic observer failure"));
        assert!(FAILED.get());
    }
    #[test]
    fn one_reusable_callback_preserves_success_and_contains_later_panic() {
        FAILED.set(false);
        let panic_now = std::cell::Cell::new(false);
        let calls = std::cell::Cell::new(0);
        let mut callback = || {
            calls.set(calls.get() + 1);
            assert!(!panic_now.get(), "later observer panic");
        };
        ffi_guard(std::panic::AssertUnwindSafe(&mut callback));
        assert_eq!(calls.get(), 1);
        assert!(!FAILED.get());
        panic_now.set(true);
        ffi_guard(std::panic::AssertUnwindSafe(&mut callback));
        assert_eq!(calls.get(), 2);
        assert!(FAILED.get());
        FAILED.set(false);
    }

    #[test]
    fn successful_callback_runs_without_invalidating_measurements() {
        FAILED.set(false);
        let observed = std::cell::Cell::new(false);
        ffi_guard(std::panic::AssertUnwindSafe(|| observed.set(true)));
        assert!(observed.get());
        assert!(!FAILED.get());
    }
}
fn has_recording_session(state: &State) -> bool {
    state
        .sessions
        .iter()
        .any(|session| session.engine.as_ref().is_some_and(Engine::is_recording))
}
fn update_active(state: &State) {
    // SAFETY: request-thread-local scalar gate used only by observer callbacks.
    unsafe { profiler_set_active(i32::from(state.enabled && has_recording_session(state))) };
    update_context_tracking(state);
    sampling::synchronize(state);
}
fn update_context_tracking(state: &State) {
    // SAFETY: a request-thread-local interest flag; it never enables capture.
    unsafe { profiler_set_context_tracking(i32::from(state.contexts.len() != 0)) };
}
/// Release callback identities once no engine can consume them. Retained reports
/// still need owner-context and generated-name identities for default selection.
fn clean_capture_state(state: &mut State) {
    if !has_recording_session(state) {
        state.frames.clear();
    }
    sampling::clear_if_idle(state);
    if state.sessions.is_empty() {
        state.contexts.clear();
        state.generated_functions.clear();
        state.generated_classes.clear();
        FAILED.set(false);
        sampling::reset_failure();
        if state.sampling.is_some() {
            sampling::reset_backend();
        }
        // SAFETY: no session remains to depend on the request-local fault state.
        unsafe {
            profiler_reset_clocks();
        }
    }
}
fn context_identity(state: &mut State, pointer: u64) -> PhpResult<u64> {
    if let Some(id) = state.contexts.get(&pointer) {
        return Ok(*id);
    }
    if state.contexts.len() >= 8192 {
        return Err(error("Fiber context capacity reached"));
    }
    let id = state.next_context;
    let next = id
        .checked_add(1)
        .ok_or_else(|| error("Fiber context identity space exhausted"))?;
    if !state.contexts.insert(pointer, id) {
        return Err(error("Fiber context capacity reached"));
    }
    state.next_context = next;
    Ok(id)
}
fn error(message: impl Into<String>) -> PhpException {
    PhpException::from_class::<StateException>(message.into())
}
fn config_error(message: impl Into<String>) -> PhpException {
    PhpException::from_class::<ConfigurationException>(message.into())
}
#[php_class]
#[php(name="PhpProfiler\\StateException",extends(ce=ext_php_rs::zend::ce::exception,stub="Exception"))]
pub struct StateException;
#[php_class]
#[php(name="PhpProfiler\\ConfigurationException",extends(ce=ext_php_rs::zend::ce::exception,stub="Exception"))]
pub struct ConfigurationException;
#[php_class]
#[php(name="PhpProfiler\\PersistenceException",extends(ce=ext_php_rs::zend::ce::exception,stub="Exception"))]
pub struct PersistenceException;
fn find_session(state: &mut State, id: Option<i64>) -> PhpResult<&mut SessionRecord> {
    match id {
        Some(id) => state.sessions.iter_mut().find(|s| s.id == id),
        None => {
            let pointer = unsafe { profiler_context() };
            let current = state.contexts.get(&pointer).copied();
            let index = state
                .sessions
                .iter()
                .rposition(|s| Some(s.context) == current && s.engine.is_some())
                .or_else(|| {
                    state
                        .sessions
                        .iter()
                        .rposition(|s| Some(s.context) == current)
                });
            index.map(|i| &mut state.sessions[i])
        }
    }
    .ok_or_else(|| error("Unknown profiling session"))
}
/// # Safety
/// `pointer` must identify a valid, NUL-terminated engine-owned C string.
/// libc scans at most 129 bytes, so an oversized identity is never cloned.
unsafe fn engine_identity(pointer: *const std::ffi::c_char) -> Option<MetadataBytes> {
    let length = unsafe { strnlen(pointer, METADATA_INPUT_BYTES + 1) };
    if length > METADATA_INPUT_BYTES {
        return None;
    }
    // SAFETY: strnlen stopped at NUL within this valid C string's allocation.
    let bytes = unsafe { std::slice::from_raw_parts(pointer.cast::<u8>(), length) };
    Some(MetadataBytes::copy(bytes))
}
fn decorate(report: &mut Report, facts: &StopFacts) -> Result<(), BudgetError> {
    let budget = report.budget();
    if let Some(peak) = facts.request_peak {
        report.request_peak_bytes = Some(peak);
    } else {
        report.mark_memory_unavailable();
    }
    if facts.clock_failed {
        report.mark_clock_failure();
    }
    for (index, name) in ["php_version", "sapi"].into_iter().enumerate() {
        let value = match facts.metadata[index] {
            MetadataFact::Present(value) => {
                BudgetText::try_copy(value.display().as_str(), &budget)?
            }
            MetadataFact::Omitted | MetadataFact::Missing => {
                report
                    .runtime_metadata
                    .insert("execution_metadata_complete".into(), "false".into())?;
                "<omitted: value exceeds 128 bytes>".into()
            }
        };
        report.runtime_metadata.insert(name.into(), value)?;
    }
    for (name, value) in [
        ("os", std::env::consts::OS),
        ("architecture", std::env::consts::ARCH),
        ("profiler_version", env!("CARGO_PKG_VERSION")),
    ] {
        report.runtime_metadata.insert(name.into(), value.into())?;
    }
    report
        .runtime_metadata
        .insert("zts".into(), BudgetText::from(facts.zts))?;
    for (index, (_, key)) in INI_METADATA.iter().enumerate() {
        let value = match facts.metadata[index + 2] {
            MetadataFact::Missing => continue,
            MetadataFact::Omitted => {
                report
                    .runtime_metadata
                    .insert("configuration_metadata_complete".into(), "false".into())?;
                "<omitted: configured value exceeds 128 bytes>".into()
            }
            MetadataFact::Present(value) => {
                BudgetText::try_copy(value.display().as_str(), &budget)?
            }
        };
        report.runtime_metadata.insert((*key).into(), value)?;
    }
    Ok(())
}
fn c_path(
    path: &std::path::Path,
    buffer: &mut [u8; OUTPUT_PATH_BYTES + 1],
) -> PhpResult<*const std::ffi::c_char> {
    let bytes = path.as_os_str().as_encoded_bytes();
    if bytes.len() > OUTPUT_PATH_BYTES || bytes.contains(&0) {
        return Err(config_error("Invalid output path"));
    }
    buffer[..bytes.len()].copy_from_slice(bytes);
    buffer[bytes.len()] = 0;
    Ok(buffer.as_ptr().cast())
}
fn check_output_access(path: &std::path::Path) -> PhpResult<()> {
    let mut buffer = [0u8; OUTPUT_PATH_BYTES + 1];
    let pointer = c_path(path, &mut buffer)?;
    // SAFETY: bounded NUL-terminated stack buffer remains alive throughout the C guard.
    if unsafe { profiler_path_allowed(pointer) } == 0 {
        return Err(PhpException::from_class::<PersistenceException>(
            "Output path is blocked by PHP open_basedir".into(),
        ));
    }
    Ok(())
}
fn resolve_output_path(path: &std::path::Path) -> PhpResult<FixedPath<INPUT_PATH_BYTES>> {
    let mut input = [0u8; OUTPUT_PATH_BYTES + 1];
    let pointer = c_path(path, &mut input)?;
    let mut output = [0u8; INPUT_PATH_BYTES];
    // SAFETY: both buffers remain alive; C checks capacity and catches bailout.
    if unsafe { profiler_resolve_path(pointer, output.as_mut_ptr(), output.len()) } == 0 {
        return Err(PhpException::from_class::<PersistenceException>(
            "Cannot resolve output path in PHP working directory".into(),
        ));
    }
    let text = std::ffi::CStr::from_bytes_until_nul(&output)
        .map_err(|_| error("Invalid resolved output path"))?;
    Ok(FixedPath::copy(text.to_bytes()).expect("resolved C string fits the native output buffer"))
}
// Literal bytes plus the maximum decimal widths of u32, i64 and two u64s.
const OUTPUT_BASENAME_BYTES: usize =
    b"php-profile-".len() + 10 + 1 + 20 + 1 + 20 + 1 + 20 + b".json".len();
const _: () = assert!(OUTPUT_BASENAME_BYTES <= SYMBOL_BUFFER_BYTES);
const _: () = assert!(INPUT_PATH_BYTES + 1 + OUTPUT_BASENAME_BYTES <= OUTPUT_PATH_BYTES);
fn directory_output_path(
    directory: &FixedPath<INPUT_PATH_BYTES>,
    pid: u32,
    session: i64,
    wall: u64,
    sequence: u64,
) -> FixedPath<OUTPUT_PATH_BYTES> {
    let mut basename = SymbolBuffer::new();
    write!(
        basename,
        "php-profile-{pid}-{session}-{wall}-{sequence}.json"
    )
    .expect("fixed-width output basename fits symbol buffer");
    let mut target =
        FixedPath::copy(directory.bytes()).expect("native directory fits published path capacity");
    if !target.bytes().ends_with(b"/") {
        assert!(
            target.append(b"/"),
            "published path reserves its directory separator"
        );
    }
    assert!(
        target.append(basename.as_str().as_bytes()),
        "published path reserves the maximum basename width"
    );
    target
}
fn freeze_session(session: &mut SessionRecord, facts: StopFacts, native: Option<sampling::Status>) {
    if session.engine.is_none() || session.stop_facts.is_some() {
        return;
    }
    if let (Some(engine), Some(tracker)) = (&mut session.engine, &mut session.sampling) {
        tracker.finish(
            engine,
            native.expect("sampled stop includes a native counter snapshot"),
        );
    }
    session.stop_facts = Some(facts);
}
fn freeze_all(state: &mut State, reason: &'static str) {
    if !state
        .sessions
        .iter()
        .any(|s| s.engine.is_some() && s.stop_facts.is_none())
    {
        return;
    }
    let native = state
        .sessions
        .iter()
        .any(|s| s.engine.is_some() && s.stop_facts.is_none() && s.sampling.is_some())
        .then(sampling::status);
    let facts = StopFacts::capture(reason);
    for session in &mut state.sessions {
        freeze_session(session, facts, native);
    }
}
fn freeze_one(session: &mut SessionRecord) {
    if session.engine.is_some() && session.stop_facts.is_none() {
        // Snapshot sampler counters before the execution boundary. Joining a
        // timer or serializing another report must not extend this measurement.
        let sampler = session.sampling.as_ref().map(|_| sampling::status());
        freeze_session(session, StopFacts::capture(session.reason), sampler);
    }
}
fn complete(session: &mut SessionRecord) -> PhpResult<()> {
    freeze_one(session);
    if let Some(engine) = session.engine.as_mut() {
        let facts = session
            .stop_facts
            .as_ref()
            .expect("stop facts captured before sealing");
        let (wall, cpu, memory) = facts.boundary;
        let mut report = engine.try_finish(wall, cpu, memory).map_err(budget_error)?;
        decorate(&mut report, facts).map_err(budget_error)?;
        if let Some(tracker) = &session.sampling {
            tracker.decorate(&mut report).map_err(budget_error)?;
        }
        report.termination_reason = facts.reason.into();
        if facts.observer_failed {
            report.status = "incomplete".into();
            report
                .limitations
                .push("Capture interrupted by observer failure or adapter capacity limit".into())
                .map_err(budget_error)?;
        }
        if facts.reason == "request_shutdown" {
            report.status = "incomplete".into();
            report
                .limitations
                .push("Session unfinished at request shutdown".into())
                .map_err(budget_error)?;
        }
        session.engine = None;
        session.report = Some(report);
    }
    if session.written.is_none()
        && let (Some(report), Some(path)) = (&session.report, &session.output)
    {
        check_output_access(path.path())?;
        let resolved = resolve_output_path(path.path())?;
        check_output_access(resolved.path())?;
        let target = if resolved.path().is_dir() {
            static NEXT_OUTPUT: AtomicU64 = AtomicU64::new(0);
            directory_output_path(
                &resolved,
                std::process::id(),
                session.id,
                // Publishing a sealed report must not read unrelated clocks or
                // mutate measurement failure flags. Every native report retains
                // the first stop facts from which it was materialized.
                session
                    .stop_facts
                    .as_ref()
                    .expect("a materialized report retains its stop facts")
                    .boundary
                    .0,
                NEXT_OUTPUT.fetch_add(1, Ordering::Relaxed),
            )
        } else {
            FixedPath::copy(resolved.bytes())
                .expect("resolved native path fits published path capacity")
        };
        check_output_access(
            target
                .path()
                .parent()
                .filter(|path| !path.as_os_str().is_empty())
                .unwrap_or(std::path::Path::new(".")),
        )?;
        check_output_access(target.path())?;
        report.write_atomic(target.path()).map_err(|cause| {
            PhpException::from_class::<PersistenceException>(format!(
                "Cannot persist profile: {cause}"
            ))
        })?;
        session.written = Some(target);
    }
    Ok(())
}
pub struct Profiler;
impl Profiler {
    pub fn enable() {
        STATE.with(|s| {
            let mut s = s.borrow_mut();
            s.enabled = true;
            update_active(&s);
        });
    }
    pub fn disable() -> PhpResult<()> {
        STATE.with(|s| {
            let mut s = s.borrow_mut();
            s.enabled = false;
            update_active(&s);
            freeze_all(&mut s, "disabled");
            sampling::prepare_reports(&mut s);
            let mut failure = None;
            for session in &mut s.sessions {
                if session.engine.is_some() {
                    session.reason = "disabled";
                    if let Err(e) = complete(session) {
                        failure = Some(e);
                    }
                }
            }
            clean_capture_state(&mut s);
            update_active(&s);
            failure.map_or(Ok(()), Err)
        })
    }
    pub fn start(path: Option<&str>, timeline: bool, max_events: usize) -> PhpResult<i64> {
        Self::start_config(path, timeline, max_events, false)
    }
    fn start_config(
        path: Option<&str>,
        timeline: bool,
        max_events: usize,
        sampled: bool,
    ) -> PhpResult<i64> {
        if sampled && !sampling::supported() {
            return Err(config_error(
                "Wall safepoint sampling requires Linux glibc 2.32 or later",
            ));
        }
        STATE.with(|s| {
            let mut s = s.borrow_mut();
            if s.sessions.len() >= SESSION_CAPACITY {
                return Err(error("Session limit reached; reset completed sessions"));
            }
            let id = s
                .next_id
                .checked_add(1)
                .filter(|id| *id > 0)
                .ok_or_else(|| error("Profiling session identity space exhausted"))?;
            let budget = prepare_capture(&mut s).map_err(budget_error)?;
            if sampled && s.sampling.is_none() {
                s.sampling = Some(sampling::Adapter::try_new(&budget).map_err(budget_error)?);
            }
            let mut engine = if sampled {
                Engine::try_with_sampling(
                    max_events,
                    timeline,
                    budget,
                    std::num::NonZeroU64::new(sampling::PERIOD_NS).expect("nonzero sampler period"),
                )
            } else {
                Engine::try_with_timeline(max_events, timeline, budget)
            }
            .map_err(budget_error)?;
            // Complete fallible admission before creating an optional worker.
            let context = context_identity(&mut s, unsafe { profiler_context() })?;
            let native = if sampled {
                Some(sampling::start().map_err(error)?)
            } else {
                None
            };
            let (wall, cpu, memory) = if let Some(native) = native {
                // The producer-locked counter snapshot is also this sampled
                // session's wall start: old pending ticks cannot acquire a new
                // owner. CPU/heap remain separate genuine boundary observations.
                (native.snapshot_ns, unsafe { profiler_cpu() }, unsafe {
                    profiler_memory()
                })
            } else {
                snapshot()
            };
            engine.start(wall, cpu, memory);
            engine.switch_context(context, wall, cpu, memory);
            let output = path
                .map(|path| FixedPath::copy(path.as_bytes()).expect("validated input path"))
                .or_else(|| s.default_output.clone());
            let frame_floor = s.next_frame;
            let insertion = s.sessions.push(SessionRecord {
                id,
                engine: Some(engine),
                report: None,
                output,
                context,
                reason: "stopped",
                frame_floor,
                stop_facts: None,
                written: None,
                sampling: native.map(|status| sampling::Tracker::new(wall, status)),
            });
            if let Err(cause) = insertion {
                sampling::synchronize(&s);
                return Err(budget_error(cause));
            }
            s.next_id = id;
            s.enabled = true;
            update_active(&s);
            Ok(id)
        })
    }
    pub fn stop(id: Option<i64>) -> PhpResult<()> {
        STATE.with(|s| {
            let mut s = s.borrow_mut();
            let pointer = unsafe { profiler_context() };
            let current = s.contexts.get(&pointer).copied();
            let session = if let Some(id) = id {
                find_session(&mut s, Some(id))?
            } else {
                s.sessions
                    .iter_mut()
                    .rev()
                    .find(|session| session.engine.is_some() && Some(session.context) == current)
                    .ok_or_else(|| error("No active session"))?
            };
            freeze_one(session);
            let id = session.id;
            sampling::prepare_reports(&mut s);
            let result = complete(find_session(&mut s, Some(id))?);
            clean_capture_state(&mut s);
            update_active(&s);
            result
        })
    }
    pub fn reset(id: Option<i64>) -> PhpResult<()> {
        STATE.with(|s| {
            let mut s = s.borrow_mut();
            let id = find_session(&mut s, id)?.id;
            s.sessions.retain(|session| session.id != id);
            clean_capture_state(&mut s);
            update_active(&s);
            Ok(())
        })
    }
    pub fn reset_all() {
        STATE.with(|s| {
            let mut s = s.borrow_mut();
            s.sessions.clear();
            clean_capture_state(&mut s);
            update_active(&s);
        });
    }
    pub fn set_output_path(path: Option<&str>, id: Option<i64>) -> PhpResult<()> {
        STATE.with(|s| {
            let mut s = s.borrow_mut();
            if id.is_some() {
                let session = find_session(&mut s, id)?;
                if session.written.is_some() {
                    return Err(error("Report already persisted"));
                }
                session.output = path
                    .map(|path| FixedPath::copy(path.as_bytes()).expect("validated input path"));
            } else {
                s.default_output = path
                    .map(|path| FixedPath::copy(path.as_bytes()).expect("validated input path"));
            }
            Ok(())
        })
    }
    pub fn get_report_json(id: Option<i64>) -> PhpResult<JsonBuffer> {
        STATE.with(|s| {
            let mut s = s.borrow_mut();
            let session = find_session(&mut s, id)?;
            session
                .report
                .as_ref()
                .ok_or_else(|| error("Stop the session before retrieving its report"))?
                .json()
                .map_err(|e| error(e.to_string()))
        })
    }
    pub fn get_report(id: Option<i64>) -> PhpResult<Zval> {
        let json = Self::get_report_json(id)?;
        json_zval(json)
    }
    pub fn get_report_path(id: Option<i64>) -> PhpResult<Option<FixedPath<OUTPUT_PATH_BYTES>>> {
        STATE.with(|s| Ok(find_session(&mut s.borrow_mut(), id)?.written.clone()))
    }
}
fn json_zval(json: JsonBuffer) -> PhpResult<Zval> {
    let bytes = json.as_nul_terminated_bytes();
    let length = json.as_str().len();
    let mut output = Zval::new();
    // SAFETY: C contains Zend allocation and catches bailout before returning to Rust.
    if unsafe { profiler_json_decode(bytes.as_ptr(), length, &mut output) } != 0 {
        return Err(error("PHP report conversion failed"));
    }
    Ok(output)
}
fn php_string(bytes: &[u8]) -> Zval {
    let mut output = Zval::new();
    // SAFETY: native C catches bailout before returning; bytes stay alive throughout.
    unsafe { profiler_string(bytes.as_ptr(), bytes.len(), &mut output) };
    output
}
fn json_string(json: JsonBuffer) -> Zval {
    php_string(json.as_str().as_bytes())
}
fn path_zval(path: Option<FixedPath<OUTPUT_PATH_BYTES>>) -> Zval {
    if let Some(path) = path {
        php_string(path.bytes())
    } else {
        let mut value = Zval::new();
        value.set_null();
        value
    }
}
fn validated_path(path: Option<&str>) -> PhpResult<Option<&str>> {
    if path.is_some_and(|path| path.is_empty() || path.len() > 4096 || path.contains('\0')) {
        return Err(config_error(
            "Output path must contain 1..4096 bytes and no NUL",
        ));
    }
    Ok(path)
}
#[php_class]
#[php(name = "PhpProfiler\\Profiler",flags=ext_php_rs::flags::ClassFlags::Final)]
pub struct PhpProfiler;
#[php_impl]
impl PhpProfiler {
    pub fn start(path: Option<&str>, options: Option<&ZendHashTable>) -> PhpResult<SessionHandle> {
        let mut timeline = false;
        let mut sampled = false;
        let mut max_events = DEFAULT_CAPTURE_RECORDS;
        let mut known = 0;
        if let Some(options) = options {
            if let Some(value) = options.get("timeline") {
                known += 1;
                timeline = value
                    .bool()
                    .ok_or_else(|| config_error("timeline must be bool"))?;
            }
            if let Some(value) = options.get("sampling") {
                known += 1;
                sampled = value
                    .bool()
                    .ok_or_else(|| config_error("sampling must be bool"))?;
            }
            if let Some(value) = options.get("max_events") {
                known += 1;
                let value = value
                    .long()
                    .ok_or_else(|| config_error("max_events must be int"))?;
                if !(1..=MAX_CAPTURE_RECORDS).contains(&value) {
                    return Err(config_error(format!(
                        "max_events must be between 1 and {MAX_CAPTURE_RECORDS}"
                    )));
                }
                max_events = value as usize;
            }
            if options.len() != known {
                return Err(config_error("Unknown profiler option"));
            }
        }
        Ok(SessionHandle {
            id: if sampled {
                Profiler::start_config(validated_path(path)?, timeline, max_events, true)?
            } else {
                Profiler::start(validated_path(path)?, timeline, max_events)?
            },
        })
    }
    pub fn is_sampling_supported() -> bool {
        sampling::supported()
    }
    pub fn is_enabled() -> bool {
        STATE.with(|s| s.borrow().enabled)
    }
    pub fn enable() {
        Profiler::enable()
    }
    pub fn disable() -> PhpResult<()> {
        Profiler::disable()
    }
    pub fn stop(session: Option<&SessionHandle>) -> PhpResult<()> {
        Profiler::stop(session.map(|s| s.id))
    }
    pub fn reset_all() {
        Profiler::reset_all();
    }
    pub fn reset(session: Option<&SessionHandle>) -> PhpResult<()> {
        Profiler::reset(session.map(|s| s.id))
    }
    pub fn get_report(session: Option<&SessionHandle>) -> PhpResult<Zval> {
        Profiler::get_report(session.map(|s| s.id))
    }
    pub fn get_report_json(session: Option<&SessionHandle>) -> PhpResult<Zval> {
        Ok(json_string(Profiler::get_report_json(
            session.map(|s| s.id),
        )?))
    }
    pub fn get_report_path(session: Option<&SessionHandle>) -> PhpResult<Zval> {
        Ok(path_zval(Profiler::get_report_path(session.map(|s| s.id))?))
    }
    pub fn set_output_path(path: Option<&str>, session: Option<&SessionHandle>) -> PhpResult<()> {
        Profiler::set_output_path(validated_path(path)?, session.map(|s| s.id))
    }
}
#[php_class]
#[php(name = "PhpProfiler\\Session",flags=ext_php_rs::flags::ClassFlags::Final)]
pub struct SessionHandle {
    id: i64,
}
#[php_impl]
impl SessionHandle {
    pub fn __construct() -> PhpResult<Self> {
        Err(error("Create sessions with PhpProfiler\\Profiler::start()"))
    }
    pub fn get_id(&self) -> i64 {
        self.id
    }
    pub fn stop(&self) -> PhpResult<()> {
        Profiler::stop(Some(self.id))
    }
    pub fn reset(&self) -> PhpResult<()> {
        Profiler::reset(Some(self.id))
    }
    pub fn get_report(&self) -> PhpResult<Zval> {
        Profiler::get_report(Some(self.id))
    }
    pub fn get_report_json(&self) -> PhpResult<Zval> {
        Ok(json_string(Profiler::get_report_json(Some(self.id))?))
    }
    pub fn get_report_path(&self) -> PhpResult<Zval> {
        Ok(path_zval(Profiler::get_report_path(Some(self.id))?))
    }
    pub fn set_output_path(&self, path: Option<&str>) -> PhpResult<()> {
        Profiler::set_output_path(validated_path(path)?, Some(self.id))
    }
}
/// A native observation could not be identified; disclose loss without PHP allocation.
#[unsafe(no_mangle)]
pub extern "C" fn profiler_observer_failed() {
    FAILED.set(true);
}
#[unsafe(no_mangle)]
/// # Safety
/// Name/scope bytes must remain valid for the callback and lengths must match.
pub unsafe extern "C" fn profiler_enter(
    frame: u64,
    name: *const u8,
    len: usize,
    scope: *const u8,
    scope_len: usize,
    wall: u64,
    cpu: u64,
    memory: u64,
    internal: i32,
    closure_identity: u64,
) {
    ffi_guard(|| {
        // SAFETY: Zend owns these strings throughout this synchronous callback.
        STATE.with(|s| {
            if let Ok(mut s) = s.try_borrow_mut() {
                let symbol = if len
                    .saturating_add(scope_len)
                    .saturating_add(if scope_len > 0 { 2 } else { 0 })
                    > 1024
                {
                    SymbolBuffer::oversized()
                } else {
                    // SAFETY: Zend keeps both borrowed name byte ranges valid for this callback.
                    let raw_name = unsafe { std::slice::from_raw_parts(name, len) };
                    let mut symbol = SymbolBuffer::new();
                    let mut render = || -> Result<(), std::fmt::Error> {
                        if scope_len > 0 {
                            let raw_scope = unsafe { std::slice::from_raw_parts(scope, scope_len) };
                            if raw_scope.starts_with(b"class@anonymous") {
                                let ordinal = s
                                    .generated_classes
                                    .identity(raw_scope, 0)
                                    .ok_or(std::fmt::Error)?;
                                write!(symbol, "anonymous-class#{ordinal}")?;
                            } else {
                                symbol.identifier(raw_scope)?;
                            }
                            symbol.write_str("::")?;
                        }
                        if closure_identity != 0 || raw_name.starts_with(b"{closure") {
                            let ordinal = s
                                .generated_functions
                                .identity(raw_name, closure_identity)
                                .ok_or(std::fmt::Error)?;
                            write!(symbol, "{{closure#{ordinal}}}")?;
                        } else {
                            symbol.identifier(raw_name)?;
                        }
                        Ok(())
                    };
                    if render().is_err() {
                        FAILED.set(true);
                        s.enabled = false;
                        update_active(&s);
                        return;
                    }
                    symbol
                };
                if s.frames.len() >= 8192 {
                    FAILED.set(true);
                    s.enabled = false;
                    update_active(&s);
                    return;
                }
                let Some(identity) = s.next_frame.checked_add(1) else {
                    FAILED.set(true);
                    s.enabled = false;
                    update_active(&s);
                    return;
                };
                if !s.frames.insert(frame, identity) {
                    FAILED.set(true);
                    s.enabled = false;
                    update_active(&s);
                    return;
                }
                s.next_frame = identity;
                for session in &mut s.sessions {
                    if let Some(engine) = &mut session.engine {
                        if internal != 0 {
                            engine.enter_internal(identity, symbol.as_str(), wall, cpu, memory);
                        } else {
                            engine.enter(identity, symbol.as_str(), wall, cpu, memory);
                        }
                    }
                }
            } else {
                FAILED.set(true);
            }
        });
    });
}
#[unsafe(no_mangle)]
pub extern "C" fn profiler_exit(frame: u64, wall: u64, cpu: u64, memory: u64, exception: i32) {
    ffi_guard(|| {
        STATE.with(|s| {
            if let Ok(mut s) = s.try_borrow_mut() {
                let Some(identity) = s.frames.remove(&frame) else {
                    return;
                };
                for session in &mut s.sessions {
                    if identity > session.frame_floor
                        && let Some(engine) = &mut session.engine
                    {
                        if exception != 0 {
                            engine.exit_exception(identity, wall, cpu, memory);
                        } else {
                            engine.exit(identity, wall, cpu, memory);
                        }
                    }
                }
            } else {
                FAILED.set(true);
            }
        })
    });
}
#[unsafe(no_mangle)]
pub extern "C" fn profiler_fiber_switch(context: u64, wall: u64, cpu: u64, memory: u64) {
    ffi_guard(|| {
        STATE.with(|s| {
            if let Ok(mut s) = s.try_borrow_mut() {
                let Ok(context) = context_identity(&mut s, context) else {
                    FAILED.set(true);
                    s.enabled = false;
                    update_active(&s);
                    return;
                };
                for session in &mut s.sessions {
                    if let Some(engine) = &mut session.engine {
                        engine.switch_context(context, wall, cpu, memory);
                    }
                }
            } else {
                FAILED.set(true);
            }
        })
    });
}
#[unsafe(no_mangle)]
pub extern "C" fn profiler_fiber_destroy(pointer: u64, wall: u64, cpu: u64, memory: u64) {
    ffi_guard(|| remove_fiber_context(pointer, Some((wall, cpu, memory))));
}
#[unsafe(no_mangle)]
pub extern "C" fn profiler_fiber_cleanup(pointer: u64) {
    ffi_guard(|| remove_fiber_context(pointer, None));
}
fn remove_fiber_context(pointer: u64, observation: Option<(u64, u64, u64)>) {
    STATE.with(|s| {
        if let Ok(mut s) = s.try_borrow_mut() {
            if let Some(context) = s.contexts.remove(&pointer) {
                // Completed/sealed records need identity cleanup only. An interrupted
                // but still unsealed capture needs a genuine final observation;
                // zero placeholders must never become fabricated timing boundaries.
                if has_recording_session(&s) {
                    let (wall, cpu, memory) = observation.unwrap_or_else(snapshot);
                    for session in &mut s.sessions {
                        if let Some(engine) = &mut session.engine {
                            engine.destroy_context(context, wall, cpu, memory);
                        }
                    }
                }
                update_context_tracking(&s);
            }
        } else {
            FAILED.set(true);
        }
    });
}
unsafe extern "C" fn startup(module_type: i32, module_number: i32) -> i32 {
    // The ext-php-rs generated startup wrapper invokes this callback before
    // registering classes. Zend observer registration is valid only at process
    // startup; dl() invokes a temporary module startup during active execution.
    if module_type != ext_php_rs::ffi::MODULE_PERSISTENT as i32 {
        return ext_php_rs::ffi::ZEND_RESULT_CODE_FAILURE;
    }
    if automatic::register(module_number) != 0 {
        return ext_php_rs::ffi::ZEND_RESULT_CODE_FAILURE;
    }
    // SAFETY: permanent module startup is the required registration phase.
    unsafe {
        if profiler_register() != 0 {
            return ext_php_rs::ffi::ZEND_RESULT_CODE_FAILURE;
        }
        profiler_register_fibers();
        if sampling::profiler_sampling_register() == 0 {
            return ext_php_rs::ffi::ZEND_RESULT_CODE_FAILURE;
        }
    };
    0
}
unsafe extern "C" fn request_startup(_: i32, _: i32) -> i32 {
    FAILED.set(false);
    unsafe {
        profiler_set_active(0);
        profiler_set_context_tracking(0);
        profiler_reset_clocks();
        sampling::profiler_sampling_request_init();
    }
    sampling::reset_failure();
    STATE.with(|s| *s.borrow_mut() = State::default());
    automatic::start();
    0
}
unsafe extern "C" fn request_shutdown(_: i32, _: i32) -> i32 {
    // Freeze every recording session before serializing the automatic report:
    // persistence work must not extend overlapping manual measurements.
    STATE.with(|s| {
        let mut s = s.borrow_mut();
        s.enabled = false;
        update_active(&s);
        freeze_all(&mut s, "request_shutdown");
    });
    automatic::finish();
    STATE.with(|s| {
        let mut s = s.borrow_mut();
        s.enabled = false;
        update_active(&s);
        freeze_all(&mut s, "request_shutdown");
        sampling::prepare_reports(&mut s);
        for session in &mut s.sessions {
            if session.engine.is_some() {
                session.reason = "request_shutdown";
                let _ = complete(session);
            }
        }
    });
    // SAFETY: retire the worker before releasing request state and executor data.
    unsafe { sampling::profiler_sampling_request_shutdown() };
    STATE.with(|s| *s.borrow_mut() = State::default());
    // SAFETY: all request-owned identity records were discarded above.
    unsafe { profiler_set_context_tracking(0) };
    0
}
unsafe extern "C" fn module_shutdown(_: i32, number: i32) -> i32 {
    automatic::unregister(number);
    // SAFETY: unregister TSRM resources and join workers before this DSO unloads.
    unsafe { sampling::profiler_sampling_module_shutdown() };
    0
}
#[php_module]
pub fn get_module(module: ModuleBuilder) -> ModuleBuilder {
    #[cfg(all(profiler_adapter_contract_tests, not(test)))]
    let module = adapter_contract_tests::register(module);
    #[cfg(all(profiler_adapter_contract_tests, not(test)))]
    let module = sampling::register_contracts(module);
    module
        .name("php_profiler")
        .class::<StateException>()
        .class::<ConfigurationException>()
        .class::<PersistenceException>()
        .class::<SessionHandle>()
        .class::<PhpProfiler>()
        .startup_function(startup)
        .shutdown_function(module_shutdown)
        .request_startup_function(request_startup)
        .request_shutdown_function(request_shutdown)
}
