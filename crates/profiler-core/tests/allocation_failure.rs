//! Real null-allocation injection in a separate executable, never a host OOM.
use profiler_core::{
    Budget, BudgetError, Engine, JsonError, SampleObservation, SamplingFinish, SamplingTermination,
};
use std::{
    alloc::{GlobalAlloc, Layout, System},
    cell::Cell,
    ptr,
};

thread_local! { static FAIL_AFTER:Cell<Option<usize>>=const {Cell::new(None)}; }
fn fail_allocation() -> bool {
    FAIL_AFTER.with(|counter| match counter.get() {
        Some(0) => {
            counter.set(None);
            true
        }
        Some(remaining) => {
            counter.set(Some(remaining - 1));
            false
        }
        None => false,
    })
}
struct FaultAllocator;
// SAFETY: unchanged System layout/pointer forwarding. A test-only, thread-local
// counter returns null at one allocation, which fallible APIs must propagate.
unsafe impl GlobalAlloc for FaultAllocator {
    unsafe fn alloc(&self, layout: Layout) -> *mut u8 {
        if fail_allocation() {
            ptr::null_mut()
        } else {
            unsafe { System.alloc(layout) }
        }
    }
    unsafe fn alloc_zeroed(&self, layout: Layout) -> *mut u8 {
        if fail_allocation() {
            ptr::null_mut()
        } else {
            unsafe { System.alloc_zeroed(layout) }
        }
    }
    unsafe fn realloc(&self, pointer: *mut u8, layout: Layout, size: usize) -> *mut u8 {
        if fail_allocation() {
            ptr::null_mut()
        } else {
            unsafe { System.realloc(pointer, layout, size) }
        }
    }
    unsafe fn dealloc(&self, pointer: *mut u8, layout: Layout) {
        unsafe { System.dealloc(pointer, layout) }
    }
}
#[global_allocator]
static ALLOCATOR: FaultAllocator = FaultAllocator;

#[test]
fn native_budget_capture_and_report_allocations_fail_without_abort_or_loss() {
    FAIL_AFTER.set(Some(0));
    let result = Budget::try_new(1024 * 1024);
    FAIL_AFTER.set(None);
    assert!(matches!(result, Err(BudgetError::Allocation)));
    let budget = Budget::try_new(1024 * 1024).unwrap();
    for allocation in 0..7 {
        FAIL_AFTER.set(Some(allocation));
        let result = Engine::try_with_timeline(4, true, budget.clone());
        FAIL_AFTER.set(None);
        assert!(matches!(result, Err(BudgetError::Allocation)));
        assert_eq!(budget.used(), Budget::control_bytes());
    }
    for allocation in 0..11 {
        FAIL_AFTER.set(Some(allocation));
        let result = Engine::try_with_sampling(
            4,
            true,
            budget.clone(),
            std::num::NonZeroU64::new(1).unwrap(),
        );
        FAIL_AFTER.set(None);
        assert!(matches!(result, Err(BudgetError::Allocation)));
        assert_eq!(budget.used(), Budget::control_bytes());
    }
    let mut sampled = Engine::try_with_sampling(
        4,
        true,
        budget.clone(),
        std::num::NonZeroU64::new(1).unwrap(),
    )
    .unwrap();
    sampled.start(0, 0, 0);
    sampled.record_sample(
        SampleObservation {
            due_ns: 1,
            first_wake_ns: 2,
            last_wake_ns: 3,
            service_ns: 4,
            wake_count: 1,
            ..SampleObservation::default()
        },
        ["sample-a", "sample-b"],
    );
    sampled.finish_sampling(SamplingFinish {
        counter_snapshot_ns: Some(6),
        unserved_wakes: 0,
        dropped_ticks: 0,
        reason: SamplingTermination::Stopped,
    });
    let captured = budget.used();
    let mut successes = 0;
    let mut failures = 0;
    for allocation in 0..48 {
        FAIL_AFTER.set(Some(allocation));
        let result = sampled.try_finish(7, 7, 0);
        FAIL_AFTER.set(None);
        match result {
            Err(error) => {
                assert_eq!(error, BudgetError::Allocation);
                failures += 1;
            }
            Ok(report) => {
                assert_eq!(report.sampling.as_ref().unwrap().symbols.len(), 2);
                successes += 1;
            }
        }
        assert_eq!(budget.used(), captured);
    }
    assert!(successes > 0);
    assert!(failures > 0);
    let report = sampled.try_finish(70, 70, 0).unwrap();
    assert_eq!(report.sampling.as_ref().unwrap().end_ns, Some(7));
    drop(report);
    drop(sampled);
    let mut engine = Engine::try_with_timeline(4, true, budget.clone()).unwrap();
    engine.enter(1, "retained", 1, 2, 3);
    engine.exit(1, 4, 5, 6);
    let capture = budget.used();
    let mut failed = 0;
    let mut succeeded = 0;
    for allocation in 0..32 {
        FAIL_AFTER.set(Some(allocation));
        let result = engine.try_finish(7, 8, 9);
        FAIL_AFTER.set(None);
        match result {
            Err(error) => {
                assert_eq!(error, BudgetError::Allocation);
                failed += 1;
            }
            Ok(report) => {
                assert_eq!(report.functions["retained"].calls, 1);
                succeeded += 1;
            }
        }
        assert_eq!(budget.used(), capture);
    }
    assert!(failed > 0);
    assert!(succeeded > 0);
    let report = engine.try_finish(70, 80, 90).unwrap();
    assert_eq!(report.elapsed_wall_ns, 6);
    assert_eq!(report.thread_cpu_ns, 6);
    assert_eq!(report.memory_delta_bytes, 6);
    assert_eq!(report.functions["retained"].inclusive_wall_ns, 3);
    let before_json = budget.used();
    FAIL_AFTER.set(Some(0));
    let json = report.json();
    FAIL_AFTER.set(None);
    assert!(matches!(
        json,
        Err(JsonError::Budget(BudgetError::Allocation))
    ));
    assert_eq!(budget.used(), before_json);
    FAIL_AFTER.set(Some(1));
    let json = report.json();
    FAIL_AFTER.set(None);
    let json = json.unwrap();
    assert!(json.contains("retained"));
    assert_eq!(json.as_nul_terminated_bytes().last(), Some(&0));
    assert_eq!(budget.used(), before_json + json.len() + 1);
    drop(json);
    assert_eq!(budget.used(), before_json);
}
