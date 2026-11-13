use profiler_core::{Budget, BudgetError, BudgetVec, Engine, JsonError, DEFAULT_BUDGET_BYTES};

#[test]
fn sixteen_large_default_sessions_share_one_quota_with_output_overlap() {
    let budget = Budget::try_new(DEFAULT_BUDGET_BYTES).unwrap();
    // A real charged sibling-owned buffer, not an estimate of adapter layouts.
    let sibling = BudgetVec::<u8>::try_with_capacity(16 * 1024 * 1024, &budget).unwrap();
    let symbols: Vec<_> = (0..512)
        .map(|id| format!("{id:04}{}", "x".repeat(1020)))
        .collect();
    let mut engines: Vec<_> = (0..16)
        .map(|_| Engine::try_with_timeline(512, true, budget.clone()).unwrap())
        .collect();
    for engine in &mut engines {
        for (id, symbol) in symbols.iter().enumerate() {
            engine.enter(id as u64, symbol, 0, 0, 0);
            engine.exit(id as u64, 1, 1, 0);
        }
    }
    let reports: Vec<_> = engines
        .iter_mut()
        .map(|engine| engine.try_finish(2, 2, 0).unwrap())
        .collect();
    assert!(reports
        .iter()
        .all(|report| report.functions.len() == 512 && report.status == "complete"));
    assert!(budget.used() <= DEFAULT_BUDGET_BYTES);
    let blocker = BudgetVec::<u8>::try_with_capacity(budget.available(), &budget).unwrap();
    assert!(matches!(
        reports[0].json(),
        Err(JsonError::Budget(BudgetError::Limit))
    ));
    assert_eq!(reports[0].functions[&*symbols[0]].calls, 1);
    drop(blocker);
    let json = reports[0].json().unwrap();
    assert!(json.len() > 1024 * 1024);
    assert!(budget.peak() <= DEFAULT_BUDGET_BYTES);
    drop(json);
    drop(reports);
    drop(engines);
    drop(sibling);
    assert_eq!(budget.used(), Budget::control_bytes());
}
