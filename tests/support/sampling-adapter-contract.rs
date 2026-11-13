//! Private provider-boundary contracts, not performance samples or forged Zend frames.
//! Static byte descriptors and controlled counter inputs exercise fail-closed behavior.
use super::*;
use ext_php_rs::prelude::*;
use std::num::NonZeroU64;

thread_local! {
    static UNSUPPORTED: Cell<bool> = const {Cell::new(false)};
    static STATUS_READS: Cell<usize> = const {Cell::new(0)};
    static ZERO_SNAPSHOT: Cell<bool> = const {Cell::new(false)};
}
unsafe extern "C" {
    #[link_name = "profiler_sampling_supported"]
    fn actual_supported() -> i32;
    #[link_name = "profiler_sampling_status"]
    fn actual_status(status: *mut Status);
}
#[unsafe(no_mangle)]
extern "C" fn profiler_contract_sampling_supported() -> i32 {
    if UNSUPPORTED.get() {
        0
    } else {
        // SAFETY: real platform capability getter; no PHP or allocations.
        unsafe { actual_supported() }
    }
}
#[unsafe(no_mangle)]
unsafe extern "C" fn profiler_contract_sampling_status(status: *mut Status) {
    STATUS_READS.set(STATUS_READS.get() + 1);
    // SAFETY: delegate the caller-owned initialized ABI output to real C first.
    unsafe { actual_status(status) };
    if ZERO_SNAPSHOT.get() {
        // Only the explicit private dependency-failure contract changes this
        // primitive field; this is not an observed backend clock failure.
        unsafe { (*status).snapshot_ns = 0 };
    }
}
fn start_boundary_contract() {
    if supported() {
        ZERO_SNAPSHOT.set(true);
        let result = start();
        ZERO_SNAPSHOT.set(false);
        assert_eq!(
            result.err(),
            Some("Wall safepoint sampler could not snapshot its start boundary")
        );
        assert_eq!(status().running, 0);
        assert!(!CALLBACK_FAILED.get());
        assert!(!crate::FAILED.get());
    }
}
pub fn register(module: ModuleBuilder) -> ModuleBuilder {
    module
        .function(wrap_function!(profiler_sampling_contract_probe))
        .function(wrap_function!(profiler_sampling_map_lifetime_probe))
}
fn descriptor(name: &[u8], scope: &[u8], identity: u64) -> Frame {
    Frame {
        name: name.as_ptr(),
        name_length: name.len(),
        scope: if scope.is_empty() {
            std::ptr::null()
        } else {
            scope.as_ptr()
        },
        scope_length: scope.len(),
        closure_identity: identity,
        internal: 0,
    }
}
// Genuine initialized Zend APIs, real worker start/stop on supported Linux,
// and actual quota refusal exercise boundary-read isolation.
fn freeze_dependency_contract() {
    crate::Profiler::reset_all();
    reset_failure();
    crate::FAILED.set(false);
    // Reach ordinary-unsealed state through the real start API, not fabricated
    // SessionRecord Option combinations. Disable must never query the optional
    // sampler for an ordinary-only graph, and must capture metadata exactly once.
    let id = crate::Profiler::start(None, false, 4).unwrap();
    STATE.with(|state| assert!(state.borrow().sampling.is_none()));
    crate::adapter_contract_tests::reset_boundary_reads();
    STATUS_READS.set(0);
    crate::Profiler::disable().unwrap();
    assert_eq!(STATUS_READS.get(), 0);
    assert_eq!(crate::adapter_contract_tests::boundary_reads(), 2);
    STATE.with(|state| {
        let state = state.borrow();
        let session = state.sessions.iter().find(|s| s.id == id).unwrap();
        assert!(session.engine.is_none());
        assert_eq!(session.report.as_ref().unwrap().status, "complete");
        assert_eq!(session.stop_facts.unwrap().reason, "disabled");
    });
    crate::Profiler::reset_all();
    if !supported() {
        return;
    }

    // Reach sealed-SomeEngine/SomeStopFacts by an ACTUAL failed materialization.
    // Capture admission succeeded and the real sampler is stopped before report
    // allocation fails. No manually invalid state and no fake Zend frame exists.
    let id = crate::Profiler::start_config(None, false, 4, true).unwrap();
    let budget = STATE.with(|state| state.borrow().budget.as_ref().unwrap().clone());
    let held = budget.try_reserve(budget.available()).unwrap();
    let first_error =
        crate::Profiler::stop(Some(id)).expect_err("quota must deny report allocation");
    assert!(format!("{first_error:?}").contains("memory budget exhausted"));
    let first = STATE.with(|state| {
        let state = state.borrow();
        let session = state.sessions.iter().find(|s| s.id == id).unwrap();
        assert!(!session.engine.as_ref().unwrap().is_recording());
        assert!(session.report.is_none());
        assert!(!session.sampling.as_ref().unwrap().is_recording());
        session.stop_facts.unwrap()
    });
    crate::adapter_contract_tests::reset_boundary_reads();
    STATUS_READS.set(0);
    // Both freeze_all and complete->freeze_one must reject resnapshotting.
    assert!(crate::Profiler::disable().is_err());
    assert_eq!(STATUS_READS.get(), 0);
    assert_eq!(crate::adapter_contract_tests::boundary_reads(), 0);
    STATE.with(|state| {
        let state = state.borrow();
        let facts = state
            .sessions
            .iter()
            .find(|s| s.id == id)
            .unwrap()
            .stop_facts
            .unwrap();
        assert_eq!(facts.boundary, first.boundary);
        assert_eq!(facts.reason, first.reason);
    });
    drop(held);
    crate::Profiler::stop(Some(id)).unwrap();
    // Successful retry also must retain the first boundary and avoid reads.
    assert_eq!(STATUS_READS.get(), 0);
    assert_eq!(crate::adapter_contract_tests::boundary_reads(), 0);
    STATE.with(|state| {
        let state = state.borrow();
        let session = state.sessions.iter().find(|s| s.id == id).unwrap();
        assert_eq!(session.stop_facts.unwrap().boundary, first.boundary);
        assert!(session.engine.is_none());
        assert!(session.report.is_some());
    });
    crate::Profiler::reset_all();
}

fn fixture_engine(budget: &Budget) -> Engine {
    let mut engine = Engine::try_with_sampling(
        4,
        false,
        budget.clone(),
        NonZeroU64::new(PERIOD_NS).unwrap(),
    )
    .unwrap();
    engine.start(10, 0, 0);
    engine.enter(1, "contract_graph", 10, 0, 0);
    engine.exit(1, 20, 1, 0);
    engine
}
fn native() -> Status {
    Status {
        snapshot_ns: 20,
        worker_stack_bytes: 1234,
        worker_guard_bytes: 56,
        control_bytes: 78,
        ..Status::default()
    }
}
fn observation(frames: &[Frame], ticks: &[Tick], status: Status) -> Observation {
    Observation {
        frames: frames.as_ptr(),
        frame_count: frames.len(),
        ticks: ticks.as_ptr(),
        tick_count: ticks.len(),
        service_ns: 15,
        status,
        stack_truncated: 0,
    }
}
fn rendered(adapter: &mut Adapter, frame: &Frame) -> Option<String> {
    // SAFETY: fixtures supply static/live byte ranges, or checked null/oversize
    // cases that return before any invalid descriptor span can be dereferenced.
    unsafe { adapter.render(frame) }.map(|name| name.as_str().to_owned())
}
fn renderer_contract() {
    let budget = Budget::new(4 * 1024 * 1024);
    let mut adapter = Adapter::try_new(&budget).unwrap();
    assert_eq!(
        rendered(&mut adapter, &descriptor(b"leaf", b"Fixture", 0)).as_deref(),
        Some("Fixture::leaf")
    );
    assert_eq!(
        rendered(&mut adapter, &descriptor(&[0x80], b"", 0)).as_deref(),
        Some("{bytes:80}")
    );
    assert!(
        rendered(
            &mut adapter,
            &descriptor(b"start", b"PhpProfiler\\Profiler", 0)
        )
        .is_none()
    );
    assert!(
        rendered(
            &mut adapter,
            &descriptor(b"stop", b"PhpProfiler\\Session", 0)
        )
        .is_none()
    );
    let main = Frame {
        name: std::ptr::null(),
        name_length: 0,
        scope: std::ptr::null(),
        scope_length: 0,
        closure_identity: 0,
        internal: 0,
    };
    assert_eq!(rendered(&mut adapter, &main).as_deref(), Some("{main}"));
    for bad in [
        Frame {
            name_length: 1,
            ..main
        },
        Frame {
            scope_length: 1,
            ..main
        },
    ] {
        reset_failure();
        assert_eq!(rendered(&mut adapter, &bad).unwrap().len(), 1025);
        assert!(CALLBACK_FAILED.get());
    }
    reset_failure();
    let huge = Frame {
        name: b"x".as_ptr(),
        name_length: usize::MAX,
        ..main
    };
    assert_eq!(rendered(&mut adapter, &huge).unwrap().len(), 1025);
    assert!(!CALLBACK_FAILED.get());
    let encoded = vec![0x80; 600];
    assert!(
        rendered(&mut adapter, &descriptor(&encoded, b"", 0))
            .unwrap()
            .len()
            > 1024
    );
    assert!(!CALLBACK_FAILED.get());
    let closure = descriptor(b"{closure}\0PRIVATE_FUNCTION_PATH", b"", 1);
    assert_eq!(
        rendered(&mut adapter, &closure).as_deref(),
        Some("{sample-closure#1}")
    );
    let anonymous = descriptor(b"leaf", b"class@anonymous\0PRIVATE_CLASS_PATH", 0);
    assert_eq!(
        rendered(&mut adapter, &anonymous).as_deref(),
        Some("sample-anonymous-class#1::leaf")
    );
    assert_eq!(
        rendered(
            &mut adapter,
            &descriptor(b"{closure}\0PRIVATE_FUNCTION_PATH", b"", 0)
        )
        .as_deref(),
        Some("{sample-closure#2}")
    );
    adapter.clear();
    for identity in 1..=GENERATED_LIMIT as u64 {
        let result = rendered(&mut adapter, &descriptor(b"{closure}", b"", identity)).unwrap();
        assert_eq!(result, format!("{{sample-closure#{identity}}}"));
    }
    assert_eq!(
        rendered(&mut adapter, &descriptor(b"{closure}", b"", 513))
            .unwrap()
            .len(),
        1025
    );
    assert_eq!(
        rendered(&mut adapter, &descriptor(b"{closure}", b"", 1)).as_deref(),
        Some("{sample-closure#1}")
    );
    assert!(!CALLBACK_FAILED.get());
    adapter.clear();
    for index in 0..GENERATED_LIMIT {
        let scope = format!("class@anonymous\0PRIVATE_CLASS_{index}");
        assert_eq!(
            rendered(&mut adapter, &descriptor(b"leaf", scope.as_bytes(), 0)).unwrap(),
            format!("sample-anonymous-class#{}::leaf", index + 1)
        );
    }
    assert_eq!(
        rendered(
            &mut adapter,
            &descriptor(b"leaf", b"class@anonymous\0PRIVATE_CLASS_EXTRA", 0)
        )
        .unwrap()
        .len(),
        1025
    );
    assert!(!CALLBACK_FAILED.get());
}
fn mapper_admission_contract() {
    let budget = Budget::new(4 * 1024 * 1024);
    let before = budget.used();
    let mut first = GeneratedNames::default();
    first
        .initialize_with_capacity(GENERATED_LIMIT, &budget)
        .unwrap();
    let one = budget.used() - before;
    drop(first);
    assert_eq!(budget.used(), before);
    for available in [0, one] {
        let held = budget.try_reserve(budget.available() - available).unwrap();
        let checkpoint = budget.used();
        assert!(matches!(Adapter::try_new(&budget), Err(BudgetError::Limit)));
        assert_eq!(budget.used(), checkpoint);
        drop(held);
    }
    let adapter = Adapter::try_new(&budget).unwrap();
    assert_eq!(budget.used(), before + 2 * one);
    drop(adapter);
    assert_eq!(budget.used(), before);
}
fn finish_fault(baseline: Status, final_status: Status, serviced: u64) {
    reset_failure();
    let budget = Budget::new(4 * 1024 * 1024);
    let mut engine = fixture_engine(&budget);
    let mut tracker = Tracker::new(10, baseline);
    tracker.serviced = serviced;
    tracker.finish(&mut engine, final_status);
    let report = engine.finish(30, 2, 0);
    assert_eq!(report.status, "complete");
    assert_eq!(report.functions["contract_graph"].calls, 1);
    let sample = report.sampling.unwrap();
    assert_eq!(sample.failure_reason, Some("counter_failure"));
    assert!(!sample.counters_valid);
    assert!(!sample.final_counters_available);
    assert!(!crate::FAILED.get());
}
fn tracker_fault_contract() {
    finish_fault(
        Status {
            wakes: 10,
            ..native()
        },
        Status {
            wakes: 9,
            ..native()
        },
        0,
    );
    finish_fault(
        Status {
            dropped_ticks: 5,
            ..native()
        },
        Status {
            wakes: 10,
            dropped_ticks: 4,
            ..native()
        },
        0,
    );
    finish_fault(
        native(),
        Status {
            wakes: 2,
            dropped_ticks: 3,
            ..native()
        },
        0,
    );
    finish_fault(
        native(),
        Status {
            wakes: 3,
            dropped_ticks: 1,
            ..native()
        },
        3,
    );
    let budget = Budget::new(4 * 1024 * 1024);
    let mut adapter = Adapter::try_new(&budget).unwrap();
    let frames = [descriptor(b"leaf", b"", 0)];
    let mut engine = fixture_engine(&budget);
    let mut tracker = Tracker::new(
        10,
        Status {
            dropped_ticks: 1,
            ..native()
        },
    );
    let data = observation(
        &frames,
        &[],
        Status {
            wakes: 10,
            dropped_ticks: 5,
            ..native()
        },
    );
    tracker.record(&mut engine, &data, &[], &frames, 7, &mut adapter);
    tracker.finish(
        &mut engine,
        Status {
            wakes: 10,
            dropped_ticks: 4,
            ..native()
        },
    );
    assert_eq!(
        engine.finish(30, 2, 0).sampling.unwrap().failure_reason,
        Some("counter_failure")
    );
    for overflow in [false, true] {
        let mut engine = fixture_engine(&budget);
        let baseline = Status {
            dropped_ticks: 3,
            ..native()
        };
        let mut tracker = Tracker::new(10, baseline);
        if overflow {
            tracker.serviced = u64::MAX;
        }
        let ticks = [Tick {
            due_ns: 11,
            wake_ns: 11,
        }];
        let status = Status {
            dropped_ticks: if overflow { 3 } else { 2 },
            ..native()
        };
        let data = observation(&frames, &ticks, status);
        tracker.record(&mut engine, &data, &ticks, &frames, 7, &mut adapter);
        tracker.finish(
            &mut engine,
            Status {
                wakes: 10,
                dropped_ticks: 3,
                ..native()
            },
        );
        let r = engine.finish(30, 2, 0);
        assert_eq!(r.status, "complete");
        assert_eq!(r.sampling.unwrap().failure_reason, Some("counter_failure"));
    }
    for (error, expected) in [
        (0, SamplingTermination::Stopped),
        (2, SamplingTermination::Capacity),
        (3, SamplingTermination::ClockFailure),
        (4, SamplingTermination::ClockFailure),
        (9, SamplingTermination::IdentityFailure),
        (10, SamplingTermination::CounterFailure),
        (8, SamplingTermination::ThreadFailure),
    ] {
        assert_eq!(reason(Status { error, ..native() }), expected);
    }
    assert_eq!(
        reason(Status {
            termination: 2,
            ..native()
        }),
        SamplingTermination::Fork
    );
    CALLBACK_FAILED.set(true);
    assert_eq!(
        reason(Status {
            termination: 2,
            ..native()
        }),
        SamplingTermination::CallbackFailure
    );
    reset_failure();
}
fn callback_guard_contract() {
    reset_failure();
    let count = Cell::new(0);
    let panic_now = Cell::new(false);
    let mut callback = || {
        count.set(count.get() + 1);
        assert!(!panic_now.get(), "private sampler callback failure");
    };
    guard(&mut callback);
    assert_eq!(count.get(), 1);
    assert!(!CALLBACK_FAILED.get());
    panic_now.set(true);
    guard(&mut callback);
    assert_eq!(count.get(), 2);
    assert!(CALLBACK_FAILED.get());
    panic_now.set(false);
    guard(&mut callback);
    assert_eq!(count.get(), 3);
    assert!(CALLBACK_FAILED.get());
    assert!(!crate::FAILED.get());
    reset_failure();
}
fn empty_observation() -> Observation {
    Observation {
        frames: std::ptr::null(),
        frame_count: 0,
        ticks: std::ptr::null(),
        tick_count: 0,
        service_ns: 15,
        status: native(),
        stack_truncated: 0,
    }
}
fn observer_defense_contract() {
    reset_failure();
    // SAFETY: null and bounded invalid-count descriptors are deliberately tested
    // at guards that reject them before accessing any pointed-to memory.
    unsafe { profiler_sampling_observe(std::ptr::null()) };
    assert!(CALLBACK_FAILED.get());
    for data in [
        Observation {
            frame_count: 65,
            ..empty_observation()
        },
        Observation {
            tick_count: 65,
            ..empty_observation()
        },
        Observation {
            frame_count: 1,
            ..empty_observation()
        },
        Observation {
            tick_count: 1,
            ..empty_observation()
        },
    ] {
        reset_failure();
        unsafe { profiler_sampling_observe(&data) };
        assert!(CALLBACK_FAILED.get());
        assert!(!crate::FAILED.get());
    }
    let data = empty_observation();
    reset_failure();
    STATE.with(|state| {
        let held = state.borrow_mut();
        unsafe { profiler_sampling_observe(&data) };
        assert!(CALLBACK_FAILED.get());
        assert!(held.sessions.is_empty());
    });
    reset_failure();
    unsafe { profiler_sampling_observe(&data) };
    assert!(CALLBACK_FAILED.get());
    let budget = Budget::new(4 * 1024 * 1024);
    STATE.with(|state| {
        let mut state = state.borrow_mut();
        state.contexts.initialize(&budget).unwrap();
        assert!(state.contexts.insert(unsafe { profiler_context() }, 7));
    });
    reset_failure();
    unsafe { profiler_sampling_observe(&data) };
    assert!(CALLBACK_FAILED.get());
    STATE.with(|state| state.borrow_mut().sampling = Some(Adapter::try_new(&budget).unwrap()));
    reset_failure();
    unsafe { profiler_sampling_observe(&data) };
    assert!(!CALLBACK_FAILED.get());
    let sampled = fixture_engine(&budget);
    let tracker = Tracker::new(10, native());
    let frames = [descriptor(b"leaf", b"Fixture", 0)];
    let ticks = [Tick {
        due_ns: 11,
        wake_ns: 11,
    }];
    let data = observation(
        &frames,
        &ticks,
        Status {
            wakes: 1,
            ..native()
        },
    );
    // A real request-local context is used; descriptors are byte strings, never
    // pointers pretending to be Zend execute_data or function structures.
    STATE.with(|state| {
        let mut state = state.borrow_mut();
        state.sampling = Some(Adapter::try_new(&budget).unwrap());
        state.sessions.initialize(3, &budget).unwrap();
        state
            .sessions
            .push(record(Some(sampled), Some(tracker), 1))
            .unwrap();
        state.sessions.push(record(None, None, 2)).unwrap();
        let mut sealed = fixture_engine(&budget);
        drop(sealed.try_finish(30, 2, 0).unwrap());
        state
            .sessions
            .push(record(Some(sealed), Some(Tracker::new(10, native())), 3))
            .unwrap();
    });
    reset_failure();
    unsafe { profiler_sampling_observe(&data) };
    assert!(!CALLBACK_FAILED.get());
    STATE.with(|state| {
        let mut state = state.borrow_mut();
        let entry = &mut state.sessions[0];
        let engine = entry.engine.as_mut().unwrap();
        entry.sampling.as_mut().unwrap().finish(
            engine,
            Status {
                wakes: 1,
                ..native()
            },
        );
        let r = engine.try_finish(30, 2, 0).unwrap();
        assert_eq!(r.status, "complete");
        assert_eq!(r.functions["contract_graph"].calls, 1);
        let sample = r.sampling.unwrap();
        assert_eq!(sample.status, "complete");
        assert_eq!(sample.counters.serviced_wakes, 1);
        assert_eq!(sample.counters.unserved_wakes, 0);
        assert_eq!(sample.samples.len(), 1);
        assert_eq!(sample.samples[0].context_id, 7);
        assert_eq!(sample.symbols[0], "Fixture::leaf");
        let sealed = state.sessions[2]
            .engine
            .as_mut()
            .unwrap()
            .try_finish(100, 100, 0)
            .unwrap();
        assert_eq!(sealed.elapsed_wall_ns, 20);
        assert!(sealed.sampling.unwrap().samples.is_empty());
        assert_eq!(state.sessions[2].sampling.as_ref().unwrap().serviced, 0);
    });
}
fn record(engine: Option<Engine>, tracker: Option<Tracker>, id: i64) -> crate::SessionRecord {
    crate::SessionRecord {
        id,
        engine,
        report: None,
        output: None,
        written: None,
        context: 7,
        reason: "stopped",
        frame_floor: 0,
        stop_facts: None,
        sampling: tracker,
    }
}
fn retry_metadata_contract() {
    let budget = Budget::new(4 * 1024 * 1024);
    let mut adapter = Adapter::try_new(&budget).unwrap();
    let mut engine = fixture_engine(&budget);
    let mut tracker = Tracker::new(
        10,
        Status {
            wakes: 5,
            dropped_ticks: 1,
            ..native()
        },
    );
    let frames = [descriptor(b"leaf", b"", 0)];
    let ticks = [
        Tick {
            due_ns: 9,
            wake_ns: 9,
        },
        Tick {
            due_ns: 11,
            wake_ns: 11,
        },
        Tick {
            due_ns: 12,
            wake_ns: 12,
        },
    ];
    let batch = observation(
        &frames,
        &ticks,
        Status {
            wakes: 9,
            dropped_ticks: 2,
            ..native()
        },
    );
    tracker.record(&mut engine, &batch, &ticks, &frames, 7, &mut adapter);
    tracker.finish(
        &mut engine,
        Status {
            wakes: 10,
            dropped_ticks: 3,
            ..native()
        },
    );
    tracker.finish(
        &mut engine,
        Status {
            wakes: u64::MAX,
            dropped_ticks: u64::MAX,
            snapshot_ns: 99,
            worker_stack_bytes: 999,
            ..native()
        },
    );
    tracker.record(&mut engine, &batch, &ticks, &frames, 7, &mut adapter);
    let held = budget.try_reserve(budget.available()).unwrap();
    assert!(matches!(
        engine.try_finish(30, 2, 0),
        Err(BudgetError::Limit)
    ));
    drop(held);
    let mut report = engine.try_finish(100, 100, 100).unwrap();
    assert_eq!(report.status, "complete");
    assert_eq!(report.elapsed_wall_ns, 20);
    let sample = report.sampling.as_ref().unwrap();
    assert_eq!(sample.final_counter_snapshot_ns, Some(20));
    assert_eq!(sample.end_ns, Some(30));
    assert_eq!(sample.samples.len(), 1);
    assert_eq!(sample.counters.serviced_wakes, 2);
    assert_eq!(sample.counters.dropped_ticks, 2);
    assert_eq!(sample.counters.unserved_wakes, 1);
    tracker.decorate(&mut report).unwrap();
    assert_eq!(
        report.runtime_metadata["sampling.worker_stack_requested_bytes"],
        "1234"
    );
    assert_eq!(
        report.runtime_metadata["sampling.cleanup_deferred"],
        "unavailable"
    );
    tracker.cleanup = Some(Cleanup::Deferred);
    tracker.decorate(&mut report).unwrap();
    assert_eq!(report.runtime_metadata["sampling.cleanup_deferred"], "true");
    tracker.cleanup = Some(Cleanup::Joined {
        kind: 7,
        system: -22,
    });
    tracker.decorate(&mut report).unwrap();
    assert_eq!(
        report.runtime_metadata["sampling.cleanup_deferred"],
        "false"
    );
    assert_eq!(report.runtime_metadata["sampling.cleanup_error_code"], "7");
    assert_eq!(
        report.runtime_metadata["sampling.cleanup_system_error_code"],
        "-22"
    );
    let held = budget.try_reserve(budget.available()).unwrap();
    assert_eq!(
        tracker.decorate(&mut report).err(),
        Some(BudgetError::Limit)
    );
    drop(held);
}
fn freeze_boundaries_contract() {
    let budget = Budget::new(8 * 1024 * 1024);
    let mut state = State {
        sampling: Some(Adapter::try_new(&budget).unwrap()),
        ..State::default()
    };
    state.sessions.initialize(4, &budget).unwrap();
    let (wall, cpu, memory) = crate::snapshot();
    let mut e = Engine::try_with_timeline(1, false, budget.clone()).unwrap();
    e.start(wall, cpu, memory);
    let mut first = record(Some(e), None, 1);
    let frozen = crate::StopFacts::capture("private-first");
    first.stop_facts = Some(frozen);
    state.sessions.push(first).unwrap();
    let mut second = record(None, None, 2);
    crate::freeze_session(&mut second, crate::StopFacts::capture("ignored"), None);
    assert!(second.stop_facts.is_none());
    second.engine = Some(Engine::try_with_timeline(1, false, budget.clone()).unwrap());
    second.engine.as_mut().unwrap().start(wall, cpu, memory);
    state.sessions.push(second).unwrap();
    crate::freeze_all(&mut state, "later");
    assert_eq!(
        state.sessions[0].stop_facts.unwrap().reason,
        "private-first"
    );
    assert_eq!(
        state.sessions[0].stop_facts.unwrap().boundary,
        frozen.boundary
    );
    assert_eq!(state.sessions[1].stop_facts.unwrap().reason, "later");
    let boundary = state.sessions[1].stop_facts.unwrap().boundary;
    crate::freeze_all(&mut state, "must-not-replace");
    assert_eq!(state.sessions[1].stop_facts.unwrap().boundary, boundary);
}
fn admission_rollback_contract() {
    let sampled = supported();
    let budget = Budget::new(crate::DEFAULT_BUDGET_BYTES);
    STATE.with(|state| {
        let mut state = state.borrow_mut();
        state.budget = Some(budget.clone());
        state.sessions.initialize(0, &budget).unwrap();
    });
    let failure = crate::Profiler::start_config(None, false, 1, sampled)
        .expect_err("zero-capacity admitted slots must reject insertion");
    assert!(format!("{failure:?}").contains("Native profiler memory budget exhausted"));
    STATE.with(|state| assert!(state.borrow().sessions.is_empty()));
    assert_eq!(status().running, 0);
    assert!(!crate::FAILED.get());
}
#[php_function]
pub fn profiler_sampling_contract_probe(mode: &str) -> PhpResult<bool> {
    crate::Profiler::reset_all();
    reset_failure();
    crate::FAILED.set(false);
    match mode {
        "freeze-dependencies" => freeze_dependency_contract(),
        "start-boundary" => start_boundary_contract(),
        "renderer" => renderer_contract(),
        "mapper-admission" => mapper_admission_contract(),
        "tracker-faults" => tracker_fault_contract(),
        "callback-guard" => callback_guard_contract(),
        "observer-defense" => observer_defense_contract(),
        "retry-metadata" => retry_metadata_contract(),
        "freeze-boundaries" => freeze_boundaries_contract(),
        "admission-rollback" => admission_rollback_contract(),
        "unsupported-config" => {
            let actual = supported();
            assert_eq!(crate::PhpProfiler::is_sampling_supported(), actual);
            UNSUPPORTED.set(true);
            // Both actual façade and real start validation consume the unavailable
            // provider value. This catches returning constant true on Linux without
            // excluding the unavailable-platform behavior from project quality gates.
            assert!(!crate::PhpProfiler::is_sampling_supported());
            let result = crate::Profiler::start_config(None, false, 1, true);
            UNSUPPORTED.set(false);
            assert_eq!(crate::PhpProfiler::is_sampling_supported(), actual);
            STATE.with(|state| {
                let state = state.borrow();
                assert!(state.sessions.is_empty());
                assert!(state.sampling.is_none());
            });
            return match result {
                Err(error) => Err(error),
                Ok(_) => panic!("unsupported sampling provider accepted configuration"),
            };
        }
        _ => panic!("unknown private sampling contract"),
    }
    crate::Profiler::reset_all();
    STATE.with(|state| *state.borrow_mut() = State::default());
    reset_failure();
    Ok(true)
}

// Read-only introspection after a real PHP fixture has populated maps through
// actual producer ticks and live VM stacks.
// No state mutation, forged frames, manufactured identities or samples.
#[php_function]
pub fn profiler_sampling_map_lifetime_probe(mode: &str) -> bool {
    STATE.with(|state| {
        let state = state.borrow();
        let adapter = state
            .sampling
            .as_ref()
            .expect("genuine sampled admission created adapter");
        match mode {
            "active-populated" => {
                assert!(has_subscribers(&state));
                assert_eq!(adapter.functions.len(), 1);
                assert_eq!(adapter.classes.len(), 1);
            }
            "idle-cleared" => {
                assert!(!has_subscribers(&state));
                assert!(
                    state.sessions.iter().any(|s| s.sampling.is_none()
                        && s.engine.as_ref().is_some_and(Engine::is_recording))
                );
                assert_eq!(adapter.functions.len(), 0);
                assert_eq!(adapter.classes.len(), 0);
            }
            _ => panic!("unknown private identity lifetime probe"),
        }
    });
    true
}
