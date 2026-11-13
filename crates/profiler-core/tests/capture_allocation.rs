//! Separate process: measure actual requested allocation layouts, including
//! overlap between capture, materialized report and JSON output.
use profiler_core::{
    Budget, BudgetError, BudgetText, BudgetVec, Engine, SampleObservation, SamplingFinish,
    SamplingTermination,
};
use std::{
    alloc::{GlobalAlloc, Layout, System},
    cell::Cell,
};

thread_local! {
    static TRACK:Cell<bool>=const {Cell::new(false)};
    static ALLOCATIONS:Cell<usize>=const {Cell::new(0)};
    static LIVE:Cell<usize>=const {Cell::new(0)};
    static PEAK:Cell<usize>=const {Cell::new(0)};
}
fn record_allocation(bytes: usize) {
    if TRACK.get() {
        ALLOCATIONS.set(ALLOCATIONS.get() + 1);
        LIVE.set(LIVE.get() + bytes);
        PEAK.set(PEAK.get().max(LIVE.get()));
    }
}
fn record_free(bytes: usize) {
    if TRACK.get() {
        LIVE.set(LIVE.get().saturating_sub(bytes));
    }
}
struct CountingAllocator;
// SAFETY: unchanged System layout/pointer forwarding. Native TLS counters never
// allocate or unwind; only this test's thread enables layout tracking.
unsafe impl GlobalAlloc for CountingAllocator {
    unsafe fn alloc(&self, layout: Layout) -> *mut u8 {
        let result = unsafe { System.alloc(layout) };
        if !result.is_null() {
            record_allocation(layout.size());
        }
        result
    }
    unsafe fn alloc_zeroed(&self, layout: Layout) -> *mut u8 {
        let result = unsafe { System.alloc_zeroed(layout) };
        if !result.is_null() {
            record_allocation(layout.size());
        }
        result
    }
    unsafe fn realloc(&self, ptr: *mut u8, layout: Layout, size: usize) -> *mut u8 {
        let result = unsafe { System.realloc(ptr, layout, size) };
        if !result.is_null() {
            record_free(layout.size());
            record_allocation(size);
        }
        result
    }
    unsafe fn dealloc(&self, ptr: *mut u8, layout: Layout) {
        record_free(layout.size());
        unsafe { System.dealloc(ptr, layout) }
    }
}
#[global_allocator]
static ALLOCATOR: CountingAllocator = CountingAllocator;

#[test]
fn all_capture_paths_use_preallocated_storage() {
    TRACK.set(true);
    let budget = Budget::try_new(1024 * 1024).unwrap();
    let mut engine = Engine::try_with_timeline(4, true, budget.clone()).unwrap();
    let mut indexed = Engine::try_with_timeline(32, false, budget.clone()).unwrap();
    let mut sampled = Engine::try_with_sampling(
        32,
        false,
        budget.clone(),
        std::num::NonZeroU64::new(1).unwrap(),
    )
    .unwrap();
    sampled.start(0, 0, 0);
    let reserved = budget.used();
    let capture_allocations = ALLOCATIONS.get();
    let construction_layouts = LIVE.get();
    engine.start(0, 0, 0);
    engine.enter(1, "root", 0, 0, 0);
    engine.enter_internal(2, "PDO::query", 0, 0, 0);
    engine.switch_context(1, 1, 1, 1);
    engine.enter(3, "fiber", 1, 1, 1);
    engine.switch_context(0, 2, 2, 2);
    engine.exit_exception(2, 3, 3, 3);
    engine.enter(4, "root", 3, 3, 3);
    engine.exit(4, u64::MAX, u64::MAX, 4);
    engine.destroy_context(1, u64::MAX, u64::MAX, 4);
    engine.exit(1, u64::MAX, u64::MAX, 4);
    for id in 5..100 {
        engine.enter(id, "root", 0, 0, 0);
        engine.exit(id, u64::MAX, u64::MAX, 0);
    }
    engine.enter(100, "new_beyond_capacity", 0, 0, 0);
    engine.exit(100, 0, 0, 0);
    engine.exit(101, 0, 0, 0);
    const INDEXED_NAMES: [&str; 16] = [
        "s00", "s01", "s02", "s03", "s04", "s05", "s06", "s07", "s08", "s09", "s10", "s11", "s12",
        "s13", "s14", "s15",
    ];
    for id in 0..128 {
        indexed.enter(id, INDEXED_NAMES[id as usize % 16], id * 2, id * 2, 0);
        indexed.exit(id, id * 2 + 1, id * 2 + 1, 0);
    }
    for _ in 0..128 {
        sampled.record_sample(
            SampleObservation {
                wake_count: 2,
                ..SampleObservation::default()
            },
            INDEXED_NAMES,
        );
    }
    sampled.finish_sampling(SamplingFinish {
        counter_snapshot_ns: Some(256),
        unserved_wakes: 2,
        dropped_ticks: 3,
        reason: SamplingTermination::Stopped,
    });
    let calls_after_capture = ALLOCATIONS.get();
    let capture_layouts = LIVE.get();
    let sampled_report = sampled.try_finish(256, 256, 0).unwrap();
    let sampled_layouts_exact = LIVE.get() == budget.used();
    let sampled_json = sampled_report.json().unwrap();
    let sampled_json_exact = LIVE.get() == budget.used();
    let sampled_correct = sampled_report.schema_version == 2
        && sampled_report.sampling.as_ref().unwrap().captured_samples == 32;
    drop(sampled_json);
    drop(sampled_report);
    drop(sampled);
    let indexed_report = indexed.finish(256, 256, 0);
    let indexed_complete = indexed_report.status == "complete"
        && indexed_report.functions.len() == 16
        && indexed_report
            .functions
            .values()
            .all(|metrics| metrics.calls == 8);
    drop(indexed_report);
    let blocker = BudgetVec::<u8>::try_with_capacity(budget.available(), &budget).unwrap();
    let failure = engine.try_finish(u64::MAX, u64::MAX, 0);
    let failed_cleanly = matches!(failure, Err(BudgetError::Limit));
    let failed_layouts = LIVE.get();
    let failed_accounted = budget.used();
    drop(blocker);
    let mut report = engine.try_finish(u64::MAX, u64::MAX, 0).unwrap();
    drop(engine);
    let measured_overflows = report.metric_overflows;
    let dropped_timeline = report.dropped_timeline_events;
    let measured_root_calls = report.functions["root"].calls;
    let report_layouts = LIVE.get();
    let report_accounted = budget.used();
    let before_availability = ALLOCATIONS.get();
    let bool_values_correct =
        BudgetText::from(true) == "true" && BudgetText::from(false) == "false";
    report.mark_clock_failure();
    report.mark_memory_unavailable();
    let after_availability = ALLOCATIONS.get();
    let json = report.json().unwrap();
    let json_layouts = LIVE.get();
    let json_accounted = budget.used();
    let peak_layouts = PEAK.get();
    let peak_accounted = budget.peak();
    drop(json);
    drop(report);
    let final_layouts = LIVE.get();
    let final_accounted = budget.used();
    TRACK.set(false);
    assert!(sampled_layouts_exact);
    assert!(sampled_json_exact);
    assert!(sampled_correct);
    assert!(indexed_complete);
    assert!(failed_cleanly);
    assert_eq!(failed_layouts, failed_accounted);
    assert_eq!(failed_accounted, budget.limit());
    assert!(measured_overflows > 0);
    assert!(dropped_timeline > 0);
    assert_eq!(measured_root_calls, 97);
    assert_eq!(calls_after_capture, capture_allocations);
    assert_eq!(construction_layouts, reserved);
    assert_eq!(capture_layouts, reserved);
    assert_eq!(report_layouts, report_accounted);
    assert!(bool_values_correct);
    assert_eq!(after_availability, before_availability);
    assert_eq!(json_layouts, json_accounted);
    assert_eq!(peak_layouts, peak_accounted);
    assert_eq!(final_layouts, final_accounted);
    assert_eq!(final_accounted, Budget::control_bytes());
}
