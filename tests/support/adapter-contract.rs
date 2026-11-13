//! Private adapter contracts exercised after real Zend module/request startup.
//! This module is compiled only into an explicitly opted-in debug test extension.
use super::*;
thread_local! {
    static BOUNDARY_READS: Cell<usize> = const { Cell::new(0) };
    static CORRUPT_RESOLVER: Cell<bool> = const { Cell::new(false) };
    static OVERSIZED_METADATA: Cell<bool> = const { Cell::new(false) };
}
pub(super) fn reset_boundary_reads() {
    BOUNDARY_READS.set(0);
}
pub(super) fn boundary_reads() -> usize {
    BOUNDARY_READS.get()
}
const OVERSIZED_IDENTITY: [u8; 130] = {
    let mut bytes = [b'm'; 130];
    bytes[129] = 0;
    bytes
};
unsafe extern "C" {
    #[link_name = "profiler_php_version"]
    fn real_php_version() -> *const std::ffi::c_char;
    #[link_name = "profiler_sapi"]
    fn real_sapi() -> *const std::ffi::c_char;
    #[link_name = "profiler_resolve_path"]
    fn real_resolve_path(path: *const std::ffi::c_char, output: *mut u8, capacity: usize) -> i32;
}
#[unsafe(no_mangle)]
extern "C" fn profiler_contract_php_version() -> *const std::ffi::c_char {
    BOUNDARY_READS.set(BOUNDARY_READS.get() + 1);
    if OVERSIZED_METADATA.get() {
        OVERSIZED_IDENTITY.as_ptr().cast()
    } else {
        // SAFETY: real engine-owned module-lifetime identity.
        unsafe { real_php_version() }
    }
}
#[unsafe(no_mangle)]
extern "C" fn profiler_contract_sapi() -> *const std::ffi::c_char {
    BOUNDARY_READS.set(BOUNDARY_READS.get() + 1);
    if OVERSIZED_METADATA.get() {
        OVERSIZED_IDENTITY.as_ptr().cast()
    } else {
        // SAFETY: real engine-owned module-lifetime identity.
        unsafe { real_sapi() }
    }
}
// Isolated dependency transport: ordinary probes delegate to the real C guard.
#[unsafe(no_mangle)]
unsafe extern "C" fn profiler_contract_resolve_path(
    path: *const std::ffi::c_char,
    output: *mut u8,
    capacity: usize,
) -> i32 {
    if CORRUPT_RESOLVER.get() {
        // SAFETY: the real Rust caller owns exactly this writable output span.
        unsafe { std::slice::from_raw_parts_mut(output, capacity) }.fill(b'x');
        1
    } else {
        // SAFETY: preserve the real C function's pointer/capacity contract.
        unsafe { real_resolve_path(path, output, capacity) }
    }
}

fn expect_error<T>(result: PhpResult<T>) -> PhpException {
    match result {
        Err(error) => error,
        Ok(_) => panic!("private adapter boundary unexpectedly accepted invalid input"),
    }
}

fn reentrant_observer_callbacks_fail_closed_without_changing_borrowed_state() {
    super::STATE.with(|state| {
        let guard = state.borrow_mut();
        let name = b"reentrant_unit_callback";
        for callback in 0..5 {
            FAILED.set(false);
            match callback {
                // SAFETY: static bytes stay valid; the zero-length scope is unused.
                0 => unsafe {
                    super::profiler_enter(
                        1,
                        name.as_ptr(),
                        name.len(),
                        std::ptr::null(),
                        0,
                        0,
                        0,
                        0,
                        0,
                        0,
                    )
                },
                1 => super::profiler_exit(1, 0, 0, 0, 0),
                2 => super::profiler_fiber_switch(1, 0, 0, 0),
                3 => super::profiler_fiber_destroy(1, 0, 0, 0),
                _ => super::profiler_fiber_cleanup(1),
            }
            assert!(
                FAILED.get(),
                "callback {callback} must disclose its missed observation"
            );
            assert_eq!(guard.frames.len(), 0);
            assert_eq!(guard.contexts.len(), 0);
        }
    });
    super::Profiler::reset_all();
    assert!(
        !FAILED.get(),
        "resetting the last session must clear the failure state"
    );
    super::STATE.with(|state| assert!(state.borrow().sessions.is_empty()));
}

pub fn register(module: ModuleBuilder) -> ModuleBuilder {
    module
        .function(wrap_function!(profiler_adapter_contract_probe))
        .function(wrap_function!(profiler_adapter_sealed_control))
}

#[php_function]
pub fn profiler_adapter_contract_probe(mode: &str) -> bool {
    match mode {
        "automatic" => automatic::contract::probe(),
        "disable-shared-boundary" => {
            let first = Profiler::start(None, false, 1).unwrap();
            let second = Profiler::start(None, false, 1).unwrap();
            assert_ne!(first, second);
            Profiler::disable().unwrap();
            STATE.with(|state| {
                let state = state.borrow();
                assert_eq!(state.sessions.len(), 2);
                let first = state.sessions.first().unwrap().stop_facts.unwrap();
                let second = state.sessions.get(1).unwrap().stop_facts.unwrap();
                assert_eq!(first.boundary, second.boundary);
                for session in state.sessions.iter() {
                    assert_eq!(session.stop_facts.unwrap().reason, "disabled");
                    let report = session.report.as_ref().unwrap();
                    assert_eq!(report.status.as_str(), "complete");
                    assert_eq!(report.termination_reason.as_str(), "disabled");
                }
            });
        }
        "native-identity-failure" => {
            native_identity_failure_marks_capture_without_accessing_borrowed_state();
        }
        "reentrant-request-start" => {
            reentrant_observer_callbacks_fail_closed_without_changing_borrowed_state();
        }
        "identity-max" => {
            for next_id in [-1, i64::MIN, i64::MAX] {
                STATE.with(|state| state.borrow_mut().next_id = next_id);
                let error = expect_error(Profiler::start(None, false, 1));
                assert!(format!("{error:?}").contains("session identity space exhausted"));
                STATE.with(|state| {
                    let state = state.borrow();
                    assert_eq!(state.next_id, next_id);
                    assert_eq!(state.sessions.len(), 0);
                    assert!(!state.enabled);
                });
            }
            STATE.with(|state| {
                let mut state = state.borrow_mut();
                prepare_capture(&mut state).unwrap();
                state.next_context = u64::MAX;
                let entries = state.contexts.len();
                let error = expect_error(context_identity(&mut state, 7654321));
                assert!(format!("{error:?}").contains("context identity space exhausted"));
                assert_eq!(state.next_context, u64::MAX);
                assert_eq!(state.contexts.len(), entries);
            });
        }
        "nul-paths" => {
            use std::os::unix::ffi::OsStrExt;
            let path = std::path::Path::new(std::ffi::OsStr::from_bytes(b"invalid\0path"));
            assert!(
                format!("{:?}", expect_error(check_output_access(path)))
                    .contains("Invalid output path")
            );
            assert!(
                format!("{:?}", expect_error(resolve_output_path(path)))
                    .contains("Invalid output path")
            );
            let too_long = "x".repeat(OUTPUT_PATH_BYTES + 1);
            let mut buffer = [0u8; OUTPUT_PATH_BYTES + 1];
            assert!(
                format!(
                    "{:?}",
                    expect_error(c_path(std::path::Path::new(&too_long), &mut buffer))
                )
                .contains("Invalid output path")
            );
            let boundary = "x".repeat(OUTPUT_PATH_BYTES);
            let pointer = c_path(std::path::Path::new(&boundary), &mut buffer).unwrap();
            // SAFETY: c_path owns this bounded stack buffer and wrote its NUL.
            assert_eq!(
                unsafe { std::ffi::CStr::from_ptr(pointer) }.to_bytes(),
                boundary.as_bytes()
            );
        }
        "reentrant-callbacks" => {
            let session = Profiler::start(None, false, 1).unwrap();
            STATE.with(|state| {
                let guard = state.borrow_mut();
                let before = (guard.frames.len(), guard.contexts.len());
                let name = b"contract_function";
                FAILED.set(false);
                // SAFETY: static name bytes stay valid; zero-length scope is unused.
                unsafe {
                    profiler_enter(
                        1,
                        name.as_ptr(),
                        name.len(),
                        std::ptr::null(),
                        0,
                        0,
                        0,
                        0,
                        0,
                        0,
                    );
                }
                assert!(FAILED.get());
                FAILED.set(false);
                profiler_exit(1, 0, 0, 0, 0);
                assert!(FAILED.get());
                FAILED.set(false);
                profiler_fiber_switch(1, 0, 0, 0);
                assert!(FAILED.get());
                FAILED.set(false);
                profiler_fiber_destroy(1, 0, 0, 0);
                assert!(FAILED.get());
                FAILED.set(false);
                profiler_fiber_cleanup(1);
                assert!(FAILED.get());
                assert_eq!((guard.frames.len(), guard.contexts.len()), before);
            });
            Profiler::stop(Some(session)).unwrap();
            STATE.with(|state| {
                assert_eq!(
                    state.borrow().sessions[0]
                        .report
                        .as_ref()
                        .unwrap()
                        .status
                        .as_str(),
                    "incomplete"
                )
            });
            Profiler::reset_all();
            assert!(!FAILED.get());
            let healthy = Profiler::start(None, false, 1).unwrap();
            Profiler::stop(Some(healthy)).unwrap();
            STATE.with(|state| {
                assert_eq!(
                    state.borrow().sessions[0]
                        .report
                        .as_ref()
                        .unwrap()
                        .status
                        .as_str(),
                    "complete"
                )
            });
        }
        "native-error-causes" => {
            // Preserve distinct diagnostics for each valid lower-level error
            // cause; allocation failure itself has separate denying-allocator tests.
            for (cause, message) in [
                (
                    BudgetError::Limit,
                    "Native profiler memory budget exhausted",
                ),
                (
                    BudgetError::Overflow,
                    "Native profiler allocation size overflow",
                ),
                (BudgetError::Allocation, "Native profiler allocation failed"),
            ] {
                let exception = budget_error(cause);
                assert!(format!("{exception:?}").contains(message));
            }
        }
        "uninitialized-storage" => {
            STATE.with(|state| {
                *state.borrow_mut() = State::default();
                let result = context_identity(&mut state.borrow_mut(), 1);
                assert!(
                    format!("{:?}", expect_error(result))
                        .contains("Fiber context capacity reached")
                );
                assert_eq!(state.borrow().contexts.len(), 0);
            });
            for name in [&b"ordinary_contract"[..], &b"{closure:contract}"[..]] {
                Profiler::reset_all();
                FAILED.set(false);
                // SAFETY: static bytes identify this callback; the opaque frame
                // key is never dereferenced, and zero-length scope is unused.
                unsafe {
                    profiler_enter(
                        1,
                        name.as_ptr(),
                        name.len(),
                        std::ptr::null(),
                        0,
                        0,
                        0,
                        0,
                        0,
                        0,
                    );
                }
                assert!(FAILED.get());
                STATE.with(|state| assert_eq!(state.borrow().frames.len(), 0));
            }
        }
        "capture-initialization-quota" => {
            // Exercise each real preallocation boundary after initializing the
            // preceding owners. A held reservation leaves only one byte free.
            for stage in 0..5 {
                let budget = Budget::try_new(DEFAULT_BUDGET_BYTES).unwrap();
                STATE.with(|state| {
                    let mut state = state.borrow_mut();
                    *state = State::default();
                    state.budget = Some(budget.clone());
                    if stage > 0 {
                        state
                            .sessions
                            .initialize(SESSION_CAPACITY, &budget)
                            .unwrap();
                    }
                    if stage > 1 {
                        state.frames.initialize(&budget).unwrap();
                    }
                    if stage > 2 {
                        state.contexts.initialize(&budget).unwrap();
                    }
                    if stage > 3 {
                        state.generated_functions.initialize(&budget).unwrap();
                    }
                });
                let held = budget.try_reserve(budget.available() - 1).unwrap();
                let error = expect_error(Profiler::start(None, false, 1));
                assert!(format!("{error:?}").contains("Native profiler memory budget exhausted"));
                STATE.with(|state| {
                    let state = state.borrow();
                    assert_eq!(state.sessions.len(), 0);
                    assert!(!state.enabled);
                });
                assert!(budget.used() <= budget.limit());
                drop(held);
            }
        }
        "metadata-omission" => {
            let mut report = Engine::new(1).finish(0, 0, 0);
            OVERSIZED_METADATA.set(true);
            for pointer in [profiler_contract_php_version(), profiler_contract_sapi()] {
                // SAFETY: test providers return the static NUL-terminated identity.
                assert_eq!(
                    unsafe { std::ffi::CStr::from_ptr(pointer) }
                        .to_bytes()
                        .len(),
                    129
                );
            }
            let facts = StopFacts::capture("stopped");
            let result = decorate(&mut report, &facts);
            OVERSIZED_METADATA.set(false);
            result.unwrap();
            assert_eq!(
                report.runtime_metadata["execution_metadata_complete"].as_str(),
                "false"
            );
            for field in ["php_version", "sapi"] {
                assert_eq!(
                    report.runtime_metadata[field].as_str(),
                    "<omitted: value exceeds 128 bytes>"
                );
            }
        }
        "unterminated-resolver" => {
            CORRUPT_RESOLVER.set(true);
            let mut canary = [0x5a; 18];
            // SAFETY: this exact writable inner span is sixteen bytes long.
            assert_eq!(
                unsafe {
                    profiler_contract_resolve_path(
                        c"/contract".as_ptr(),
                        canary[1..17].as_mut_ptr(),
                        16,
                    )
                },
                1
            );
            assert_eq!(canary[0], 0x5a);
            assert_eq!(canary[17], 0x5a);
            assert_eq!(canary[1..17], [b'x'; 16]);
            let result = resolve_output_path(std::path::Path::new("/contract-test"));
            CORRUPT_RESOLVER.set(false);
            assert!(format!("{:?}", expect_error(result)).contains("Invalid resolved output path"));
        }
        "serialization-limit" => {
            let budget = STATE.with(|state| prepare_capture(&mut state.borrow_mut()).unwrap());
            let mut report = Engine::try_with_timeline(1, false, budget.clone())
                .unwrap()
                .finish(0, 0, 0);
            report
                .runtime_metadata
                .insert(
                    "test_payload".into(),
                    BudgetText::try_copy(&"x".repeat(16 * 1024 * 1024 + 1), &budget).unwrap(),
                )
                .unwrap();
            STATE
                .with(|state| {
                    state.borrow_mut().sessions.push(SessionRecord {
                        id: 1,
                        engine: None,
                        report: Some(report),
                        output: None,
                        written: None,
                        context: 0,
                        reason: "stopped",
                        frame_floor: 0,
                        stop_facts: None,
                        sampling: None,
                    })
                })
                .unwrap();
            let error = expect_error(Profiler::get_report_json(Some(1)));
            assert!(format!("{error:?}").contains("16 MiB PHP accessor limit"));
        }
        _ => panic!("unknown adapter contract probe"),
    }
    STATE.with(|state| *state.borrow_mut() = State::default());
    FAILED.set(false);
    unsafe {
        profiler_set_active(0);
    }
    true
}

#[derive(Clone, Copy)]
struct SealedCheckpoint {
    facts: StopFacts,
    next_frame: u64,
    generated_functions: usize,
    generated_classes: usize,
}
thread_local! {
    static QUOTA_HOLD: RefCell<Option<profiler_core::Reservation>> = const { RefCell::new(None) };
    static SEALED_CHECKPOINT: Cell<Option<SealedCheckpoint>> = const { Cell::new(None) };
}

#[php_function]
pub fn profiler_adapter_sealed_control(mode: &str) -> bool {
    match mode {
        "hold" => {
            let budget = STATE.with(|state| state.borrow().budget.as_ref().unwrap().clone());
            QUOTA_HOLD.with(|held| {
                *held.borrow_mut() = Some(budget.try_reserve(budget.available()).unwrap())
            });
        }
        "checkpoint" => STATE.with(|state| {
            let state = state.borrow();
            let session = state.sessions.iter().last().unwrap();
            assert!(!session.engine.as_ref().unwrap().is_recording());
            assert!(!has_recording_session(&state));
            let facts = session.stop_facts.unwrap();
            assert!(!facts.observer_failed);
            assert!(!facts.clock_failed);
            assert_eq!(facts.reason, "stopped");
            SEALED_CHECKPOINT.set(Some(SealedCheckpoint {
                facts,
                next_frame: state.next_frame,
                generated_functions: state.generated_functions.len(),
                generated_classes: state.generated_classes.len(),
            }));
        }),
        "poison" => {
            profiler_observer_failed();
            OVERSIZED_METADATA.set(true);
        }
        "unchanged" => STATE.with(|state| {
            let state = state.borrow();
            let checkpoint = SEALED_CHECKPOINT.get().unwrap();
            assert_eq!(state.next_frame, checkpoint.next_frame);
            assert_eq!(
                state.generated_functions.len(),
                checkpoint.generated_functions
            );
            assert_eq!(state.generated_classes.len(), checkpoint.generated_classes);
            let facts = state.sessions.iter().last().unwrap().stop_facts.unwrap();
            assert_eq!(facts.boundary, checkpoint.facts.boundary);
            assert_eq!(facts.request_peak, checkpoint.facts.request_peak);
            assert_eq!(facts.observer_failed, checkpoint.facts.observer_failed);
            assert_eq!(facts.clock_failed, checkpoint.facts.clock_failed);
            for (left, right) in facts.metadata.iter().zip(checkpoint.facts.metadata.iter()) {
                match (left, right) {
                    (MetadataFact::Missing, MetadataFact::Missing)
                    | (MetadataFact::Omitted, MetadataFact::Omitted) => (),
                    (MetadataFact::Present(left), MetadataFact::Present(right)) => {
                        assert_eq!(left.length, right.length);
                        assert_eq!(left.bytes, right.bytes);
                    }
                    _ => panic!("sealed metadata changed"),
                }
            }
        }),
        "release" => QUOTA_HOLD.with(|held| *held.borrow_mut() = None),
        "report" => STATE.with(|state| {
            let state = state.borrow();
            let report = state
                .sessions
                .iter()
                .last()
                .unwrap()
                .report
                .as_ref()
                .unwrap();
            let facts = SEALED_CHECKPOINT.get().unwrap().facts;
            assert_eq!(report.status.as_str(), "complete");
            assert_eq!(report.termination_reason.as_str(), facts.reason);
            assert_eq!(report.request_peak_bytes, facts.request_peak);
            for (index, key) in ["php_version", "sapi"].iter().enumerate() {
                if let MetadataFact::Present(value) = facts.metadata[index] {
                    assert_eq!(
                        report.runtime_metadata[*key].as_str(),
                        value.display().as_str()
                    );
                }
            }
            for (index, (_, key)) in INI_METADATA.iter().enumerate() {
                if let MetadataFact::Present(value) = facts.metadata[index + 2] {
                    assert_eq!(
                        report.runtime_metadata[*key].as_str(),
                        value.display().as_str()
                    );
                }
            }
        }),
        "frame-max" => STATE.with(|state| state.borrow_mut().next_frame = u64::MAX),
        "frame-max-check" => STATE.with(|state| {
            let state = state.borrow();
            assert_eq!(state.next_frame, u64::MAX);
            assert!(FAILED.get());
            assert!(!state.enabled);
        }),
        "cleanup" => {
            OVERSIZED_METADATA.set(false);
            QUOTA_HOLD.with(|held| *held.borrow_mut() = None);
            SEALED_CHECKPOINT.set(None);
            Profiler::reset_all();
            assert!(!FAILED.get());
        }
        _ => panic!("unknown sealed adapter control"),
    }
    true
}

fn native_identity_failure_marks_capture_without_accessing_borrowed_state() {
    STATE.with(|state| {
        let guard = state.borrow_mut();
        FAILED.set(false);
        profiler_observer_failed();
        assert!(FAILED.get());
        assert_eq!(guard.frames.len(), 0);
        assert_eq!(guard.contexts.len(), 0);
    });
    Profiler::reset_all();
    assert!(!FAILED.get());
}
