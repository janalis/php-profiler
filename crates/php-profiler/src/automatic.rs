use super::*;
use std::ffi::CString;
thread_local! { static SESSION: Cell<Option<i64>> = const { Cell::new(None) }; }
unsafe extern "C" {
    fn profiler_config_register(number: i32) -> i32;
    fn profiler_config_unregister(number: i32);
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
    unsafe { profiler_config_register(number) }
}
pub fn unregister(number: i32) {
    unsafe { profiler_config_unregister(number) }
}
fn setting(name: &str) -> String {
    let name = format!("php_profiler.{name}");
    let mut len = 0;
    let ptr = unsafe { profiler_ini(name.as_ptr(), name.len(), &mut len) };
    if ptr.is_null() {
        return String::new();
    }
    String::from_utf8_lossy(unsafe { std::slice::from_raw_parts(ptr, len) }).into_owned()
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
        match setting("mode").as_str() {
            // Unquoted `Off`, `false`, `no` and `none` reach extensions as "".
            "off" | "" => return Ok(()),
            "always" => {}
            "trigger" => {
                let secret = setting("trigger_secret");
                // A secret no trigger header can ever match is a configuration error.
                if secret.is_empty() || secret.len() > 4096 || secret.contains(',') {
                    return Err(());
                }
                // SAFETY: SAPI value is read synchronously, bounded and never retained.
                let valid = unsafe { profiler_trigger_matches(secret.as_ptr(), secret.len()) } != 0;
                if !valid {
                    return Ok(());
                }
            }
            _ => return Err(()),
        }
        let dir = setting("output_dir");
        let directory = std::path::Path::new(&dir);
        if !directory.is_absolute() || !directory.is_dir() {
            return Err(());
        }
        let boolean = |name| match setting(name).as_str() {
            "0" | "" => Ok(false),
            "1" => Ok(true),
            _ => Err(()),
        };
        let timeline = boolean("timeline")?;
        let sampling = boolean("sampling")?;
        let events: usize = setting("max_events").parse().map_err(|_| ())?;
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
