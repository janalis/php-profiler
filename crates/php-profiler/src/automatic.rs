use super::*;
use std::ffi::CString;
thread_local! { static SESSION: Cell<Option<i64>> = const { Cell::new(None) }; }
unsafe extern "C" {
    fn profiler_config_register(number: i32) -> i32;
    fn profiler_config_unregister(number: i32);
    fn profiler_trigger_snapshot();
    fn profiler_trigger_matches(secret: *const u8, length: usize) -> i32;
    fn profiler_auto_log();
    fn getentropy(buffer: *mut std::ffi::c_void, length: usize) -> i32;
    #[cfg_attr(
        all(profiler_adapter_contract_tests, not(test)),
        link_name = "profiler_contract_auto_header"
    )]
    fn profiler_auto_header(value: *const std::ffi::c_char) -> i32;
}
pub fn register(number: i32) -> i32 {
    unsafe {
        profiler_trigger_snapshot();
        profiler_config_register(number)
    }
}
pub fn unregister(number: i32) {
    unsafe { profiler_config_unregister(number) }
}
/// Exact INI bytes: a lossy conversion would silently change secrets and paths.
fn setting(name: &str) -> Vec<u8> {
    let name = format!("php_profiler.{name}");
    let mut len = 0;
    let ptr = unsafe { profiler_ini(name.as_ptr(), name.len(), &mut len) };
    if ptr.is_null() {
        return Vec::new();
    }
    unsafe { std::slice::from_raw_parts(ptr, len) }.to_vec()
}
/// System entropy without a device node, so chrooted FPM pools still work.
fn system_entropy(buffer: &mut [u8; 16]) -> bool {
    // SAFETY: the buffer is valid for its full length, well under the 256-byte limit.
    unsafe { getentropy(buffer.as_mut_ptr().cast(), buffer.len()) == 0 }
}
pub fn start() {
    start_with_entropy(system_entropy);
}
fn start_with_entropy(entropy: fn(&mut [u8; 16]) -> bool) {
    SESSION.set(None);
    let result = (|| -> Result<(), ()> {
        match setting("mode").as_slice() {
            // Unquoted `Off`, `false`, `no` and `none` reach extensions as "".
            b"off" | b"" => return Ok(()),
            b"always" => {}
            b"trigger" => {
                let secret = setting("trigger_secret");
                // SAFETY: SAPI value is read synchronously, bounded and never retained.
                match unsafe { profiler_trigger_matches(secret.as_ptr(), secret.len()) } {
                    // Without a trigger header nothing was requested, so nothing is logged.
                    -1 => return Ok(()),
                    // The process environment would shadow every request's header.
                    -2 => return Err(()),
                    // A secret no trigger header can ever match is a configuration error.
                    _ if secret.is_empty()
                        || secret.len() > 4096
                        || secret.contains(&b',')
                        || secret.contains(&0) =>
                    {
                        return Err(());
                    }
                    1 => {}
                    _ => return Ok(()),
                }
            }
            _ => return Err(()),
        }
        let dir = String::from_utf8(setting("output_dir")).map_err(|_| ())?;
        let directory = std::path::Path::new(&dir);
        if !directory.is_absolute() || !directory.is_dir() {
            return Err(());
        }
        let boolean = |name| match setting(name).as_slice() {
            b"0" | b"" => Ok(false),
            b"1" => Ok(true),
            _ => Err(()),
        };
        let timeline = boolean("timeline")?;
        let sampling = boolean("sampling")?;
        let events: usize = std::str::from_utf8(&setting("max_events"))
            .map_err(|_| ())?
            .parse()
            .map_err(|_| ())?;
        if !(1..=storage::IDENTITY_CAPACITY).contains(&events) {
            return Err(());
        }
        let mut random = [0u8; 16];
        if !entropy(&mut random) {
            return Err(());
        }
        let id: String = random.iter().map(|b| format!("{b:02x}")).collect();
        let path = directory.join(format!("php-profile-{id}.json"));
        let path = path.to_str().ok_or(())?;
        validated_path(Some(path)).map_err(|_| ())?;
        check_output_access(directory).map_err(|_| ())?;
        let session =
            Profiler::start_config(Some(path), timeline, events, sampling).map_err(|_| ())?;
        SESSION.set(Some(session));
        let header = CString::new(format!("X-Php-Profiler-Id: {id}")).map_err(|_| ())?;
        if unsafe { profiler_auto_header(header.as_ptr()) } == 0 {
            return Err(());
        }
        Ok(())
    })();
    if result.is_err() {
        unsafe { profiler_auto_log() };
    }
}
pub fn finish() {
    if let Some(id) = SESSION.take() {
        let active = STATE.with(|s| {
            let mut state = s.borrow_mut();
            if let Some(session) = state
                .sessions
                .iter_mut()
                .find(|v| v.id == id && v.engine.is_some())
            {
                session.reason = "request_complete";
                if let Some(facts) = &mut session.stop_facts {
                    facts.reason = "request_complete";
                }
                true
            } else {
                false
            }
        });
        if active && Profiler::stop(Some(id)).is_err() {
            unsafe { profiler_auto_log() };
        }
    }
}

#[cfg(all(profiler_adapter_contract_tests, not(test)))]
#[path = "../../../tests/support/automatic-contract.rs"]
pub(super) mod contract;
