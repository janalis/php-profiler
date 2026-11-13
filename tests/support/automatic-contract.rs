//! Private lifecycle contracts run inside a genuine initialized Zend request.
use super::*;
unsafe extern "C" {
    #[link_name = "profiler_auto_header"]
    fn real_header(value: *const std::ffi::c_char) -> i32;
    fn profiler_test_config_type(value: i32) -> i32;
    fn profiler_test_config_number() -> i32;
    fn profiler_test_config_set(
        name: *const std::ffi::c_char,
        value: *const std::ffi::c_char,
    ) -> i32;
}
thread_local! { static HEADER_FAILED: Cell<bool> = const { Cell::new(false) }; }
#[unsafe(no_mangle)]
unsafe extern "C" fn profiler_contract_auto_header(value: *const std::ffi::c_char) -> i32 {
    if HEADER_FAILED.get() {
        0
    } else {
        unsafe { real_header(value) }
    }
}
fn set(name: &str, value: &str) {
    let name = CString::new(format!("php_profiler.{name}")).unwrap();
    let value = CString::new(value).unwrap();
    assert_eq!(
        unsafe { profiler_test_config_set(name.as_ptr(), value.as_ptr()) },
        0
    );
}
pub fn probe() {
    assert_eq!(setting("not_registered"), "");
    let number = unsafe { profiler_test_config_number() };
    assert!(number > 0);
    // Exercise both operations against the current request's table on ZTS.
    // Zend routes temporary-module INI teardown there rather than the process table.
    let previous = unsafe { profiler_test_config_type(ext_php_rs::ffi::MODULE_TEMPORARY as i32) };
    unregister(number);
    assert_eq!(register(number), 0);
    unsafe {
        profiler_test_config_type(previous);
    }
    assert_eq!(setting("mode"), "off");
    let directory =
        std::env::temp_dir().join(format!("profiler-auto-contract-{}", std::process::id()));
    std::fs::create_dir(&directory).unwrap();
    set("output_dir", directory.to_str().unwrap());
    set("mode", "trigger");
    set("trigger_secret", "private-contract");
    start(); // CLI ignores even a configured trigger.
    assert!(SESSION.get().is_none());
    set("mode", "always");
    start_with_entropy(std::path::Path::new("/nonexistent-profiler-entropy"));
    assert!(SESSION.get().is_none());
    // A manual record before the automatic one proves ID-based selection.
    let manual = Profiler::start(None, false, 1).unwrap();
    start();
    let id = SESSION.get().unwrap();
    assert_ne!(manual, id);
    finish(); // Early finalization has no shutdown stop facts yet.
    STATE.with(|s| {
        let state = s.borrow();
        let auto = state.sessions.iter().find(|s| s.id == id).unwrap();
        assert_eq!(
            auto.report.as_ref().unwrap().termination_reason.as_str(),
            "request_complete"
        );
        assert!(
            state
                .sessions
                .iter()
                .find(|s| s.id == manual)
                .unwrap()
                .engine
                .is_some()
        );
    });
    Profiler::reset_all();
    SESSION.set(Some(id));
    finish(); // Removed automatic sessions are never resurrected.
    assert!(SESSION.get().is_none());
    // A failed SAPI header operation must preserve application execution.
    HEADER_FAILED.set(true);
    start();
    HEADER_FAILED.set(false);
    assert!(SESSION.get().is_some());
    finish();
    Profiler::reset_all();
    set("mode", "off");
    for path in std::fs::read_dir(&directory).unwrap() {
        std::fs::remove_file(path.unwrap().path()).unwrap();
    }
    std::fs::remove_dir(directory).unwrap();
}
