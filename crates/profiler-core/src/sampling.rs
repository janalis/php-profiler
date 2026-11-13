//! Bounded wall-clock safepoint observations. Timer wakes are never CPU samples.
use crate::{
    index::{fingerprint, Index},
    use_linear_lookup, Budget, BudgetError, BudgetMap, BudgetText, BudgetVec, InlineName,
};
use serde::Serialize;
use std::num::NonZeroU64;

pub const SAMPLE_STACK_LIMIT: usize = 64;
/// All times are absolute CLOCK_MONOTONIC observations. Loss is a batch delta.
#[derive(Clone, Copy, Debug, Default)]
pub struct SampleObservation {
    pub due_ns: u64,
    pub first_wake_ns: u64,
    pub last_wake_ns: u64,
    pub service_ns: u64,
    pub context_id: u64,
    pub wake_count: u64,
    pub dropped_ticks: u64,
    pub depth_truncated: bool,
}
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum SamplingTermination {
    Stopped,
    Fork,
    ClockFailure,
    ThreadFailure,
    Capacity,
    CallbackFailure,
    CounterFailure,
    IdentityFailure,
}
impl SamplingTermination {
    fn name(self) -> &'static str {
        match self {
            Self::Stopped => "stopped",
            Self::Fork => "fork",
            Self::ClockFailure => "clock_failure",
            Self::ThreadFailure => "thread_failure",
            Self::Capacity => "capacity",
            Self::CallbackFailure => "callback_failure",
            Self::CounterFailure => "counter_failure",
            Self::IdentityFailure => "identity_failure",
        }
    }
}
/// Final loss deltas include wakes not serviced before the backend stopped.
#[derive(Clone, Copy, Debug)]
pub struct SamplingFinish {
    pub counter_snapshot_ns: Option<u64>,
    pub unserved_wakes: u64,
    pub dropped_ticks: u64,
    pub reason: SamplingTermination,
}
#[derive(Clone, Copy, Debug, Default, Serialize, PartialEq, Eq)]
pub struct SamplingCounters {
    pub serviced_wakes: u64,
    pub dropped_ticks: u64,
    pub coalesced_wakes: u64,
    pub dropped_samples: u64,
    pub unserved_wakes: u64,
    pub invalid_observations: u64,
}
#[derive(Clone, Copy, Debug, Serialize)]
pub struct SampleRecord {
    pub due_ns: u64,
    pub first_wake_ns: u64,
    pub last_wake_ns: u64,
    pub service_ns: u64,
    pub context_id: u64,
    pub wake_count: u64,
    pub dropped_ticks: u64,
    pub first_frame: usize,
    pub frame_count: usize,
    pub depth_truncated: bool,
    pub stack_truncated: bool,
}
#[derive(Serialize)]
pub struct SamplingReport {
    pub mode: &'static str,
    pub interval_ns: u64,
    pub start_ns: Option<u64>,
    pub end_ns: Option<u64>,
    pub stack_depth_limit: usize,
    pub status: &'static str,
    pub termination_reason: &'static str,
    pub final_counters_available: bool,
    pub final_counter_snapshot_ns: Option<u64>,
    pub failure_reason: Option<&'static str>,
    pub counter_overflow: bool,
    pub timestamps_valid: bool,
    pub counters_valid: bool,
    pub counters: SamplingCounters,
    pub captured_samples: usize,
    pub truncated_stacks: usize,
    /// Capture-order IDs index this table, independently of the function graph.
    pub symbols: BudgetVec<BudgetText>,
    /// Each record addresses a range in frames, ordered leaf to root.
    pub samples: BudgetVec<SampleRecord>,
    pub frames: BudgetVec<usize>,
    pub metric_provenance: BudgetMap<BudgetText>,
}
pub(crate) struct SamplingCapture {
    interval: NonZeroU64,
    symbols: BudgetVec<InlineName>,
    index: Index,
    records: BudgetVec<SampleRecord>,
    frames: BudgetVec<usize>,
    start: Option<u64>,
    end: Option<u64>,
    last_service: Option<u64>,
    finish: Option<SamplingFinish>,
    failure: Option<SamplingTermination>,
    counters: SamplingCounters,
    counter_overflow: bool,
    budget: Budget,
}
impl SamplingCapture {
    pub(crate) fn try_new(
        limit: usize,
        interval: NonZeroU64,
        budget: Budget,
    ) -> Result<Self, BudgetError> {
        let frame_limit = limit
            .checked_mul(SAMPLE_STACK_LIMIT)
            .ok_or(BudgetError::Overflow)?;
        Ok(Self {
            interval,
            symbols: BudgetVec::try_with_capacity(limit, &budget)?,
            index: Index::try_new(limit, &budget)?,
            records: BudgetVec::try_with_capacity(limit, &budget)?,
            frames: BudgetVec::try_with_capacity(frame_limit, &budget)?,
            start: None,
            end: None,
            last_service: None,
            finish: None,
            failure: None,
            counters: SamplingCounters::default(),
            counter_overflow: false,
            budget,
        })
    }
    pub(crate) fn allocated_bytes(&self) -> usize {
        self.symbols.allocated_bytes()
            + self.index.allocated_bytes()
            + self.records.allocated_bytes()
            + self.frames.allocated_bytes()
    }
    pub(crate) fn start(&mut self, wall: u64) {
        self.start.get_or_insert(wall);
    }
    fn add(counter: &mut u64, value: u64, overflow: &mut bool) {
        match counter.checked_add(value) {
            Some(next) => *counter = next,
            None => {
                *counter = u64::MAX;
                *overflow = true;
            }
        }
    }
    fn intern(&mut self, name: &str) -> Option<usize> {
        if name.len() > 1024 {
            return None;
        }
        let existing = if use_linear_lookup(self.symbols.len()) {
            self.symbols
                .iter()
                .position(|symbol| symbol.as_str() == name)
        } else {
            self.index.find(fingerprint(name), |index| {
                self.symbols[index].as_str() == name
            })
        };
        if existing.is_some() {
            return existing;
        }
        if self.symbols.len() == self.symbols.capacity() {
            return None;
        }
        let index = self.symbols.len();
        self.symbols
            .push(InlineName::new(name))
            .expect("symbol capacity checked");
        assert!(
            self.index.insert(fingerprint(name), index),
            "sample index is admitted at most half full"
        );
        Some(index)
    }
    pub(crate) fn record<S: AsRef<str>>(
        &mut self,
        observation: SampleObservation,
        stack: impl IntoIterator<Item = S>,
    ) {
        if self.end.is_some() || self.finish.is_some() {
            return;
        }
        Self::add(
            &mut self.counters.dropped_ticks,
            observation.dropped_ticks,
            &mut self.counter_overflow,
        );
        if observation.wake_count == 0 {
            return;
        }
        Self::add(
            &mut self.counters.serviced_wakes,
            observation.wake_count,
            &mut self.counter_overflow,
        );
        Self::add(
            &mut self.counters.coalesced_wakes,
            observation.wake_count - 1,
            &mut self.counter_overflow,
        );
        if self
            .start
            .is_none_or(|start| observation.first_wake_ns < start)
            || observation.due_ns > observation.first_wake_ns
            || observation.first_wake_ns > observation.last_wake_ns
            || observation.last_wake_ns > observation.service_ns
            || self
                .last_service
                .is_some_and(|previous| observation.service_ns < previous)
        {
            Self::add(
                &mut self.counters.invalid_observations,
                1,
                &mut self.counter_overflow,
            );
            Self::add(
                &mut self.counters.dropped_samples,
                1,
                &mut self.counter_overflow,
            );
            return;
        }
        self.last_service = Some(observation.service_ns);
        if self.records.len() == self.records.capacity() {
            Self::add(
                &mut self.counters.dropped_samples,
                1,
                &mut self.counter_overflow,
            );
            return;
        }
        let index = self.records.len();
        self.records
            .push(SampleRecord {
                due_ns: observation.due_ns,
                first_wake_ns: observation.first_wake_ns,
                last_wake_ns: observation.last_wake_ns,
                service_ns: observation.service_ns,
                context_id: observation.context_id,
                wake_count: observation.wake_count,
                dropped_ticks: observation.dropped_ticks,
                first_frame: self.frames.len(),
                frame_count: 0,
                depth_truncated: observation.depth_truncated,
                // Keep an explicit partial record if a caller's lazy iterator unwinds.
                stack_truncated: true,
            })
            .expect("sample capacity checked");
        let mut truncated = false;
        for (depth, symbol) in stack.into_iter().enumerate() {
            if depth >= SAMPLE_STACK_LIMIT {
                self.records[index].depth_truncated = true;
                break;
            }
            let Some(id) = self.intern(symbol.as_ref()) else {
                truncated = true;
                break;
            };
            // Earlier records have at most 64 frames and this record has fewer
            // than 64: the N*64 admitted flat pool necessarily has a free slot.
            self.frames
                .push(id)
                .expect("sample frame pool admitted for every bounded stack");
            self.records[index].frame_count += 1;
        }
        self.records[index].stack_truncated = truncated;
    }
    pub(crate) fn unavailable(&mut self, reason: SamplingTermination) {
        if self.end.is_none() {
            self.failure.get_or_insert(reason);
        }
    }
    pub(crate) fn finish(&mut self, finish: SamplingFinish) {
        if self.end.is_some() || self.finish.is_some() {
            return;
        }
        Self::add(
            &mut self.counters.unserved_wakes,
            finish.unserved_wakes,
            &mut self.counter_overflow,
        );
        Self::add(
            &mut self.counters.dropped_ticks,
            finish.dropped_ticks,
            &mut self.counter_overflow,
        );
        self.finish = Some(finish);
    }
    pub(crate) fn seal(&mut self, wall: u64) {
        if self.end.is_some() {
            return;
        }
        let invalid_snapshot = self
            .finish
            .and_then(|finish| finish.counter_snapshot_ns)
            .is_some_and(|snapshot| {
                self.start.is_none_or(|start| snapshot < start)
                    || snapshot > wall
                    || self.last_service.is_some_and(|service| service > snapshot)
            });
        if self.start.is_none_or(|start| wall < start)
            || self.last_service.is_some_and(|service| service > wall)
            || invalid_snapshot
        {
            Self::add(
                &mut self.counters.invalid_observations,
                1,
                &mut self.counter_overflow,
            );
        }
        self.end = Some(wall);
    }
    pub(crate) fn report(&self) -> Result<SamplingReport, BudgetError> {
        let mut symbols = BudgetVec::try_with_capacity(self.symbols.len(), &self.budget)?;
        for symbol in &self.symbols {
            symbols
                .push(BudgetText::try_copy(symbol.as_str(), &self.budget)?)
                .expect("captured symbol count reserved");
        }
        let mut samples = BudgetVec::try_with_capacity(self.records.len(), &self.budget)?;
        for record in &self.records {
            samples
                .push(*record)
                .expect("captured sample count reserved");
        }
        let mut frames = BudgetVec::try_with_capacity(self.frames.len(), &self.budget)?;
        for frame in &self.frames {
            frames.push(*frame).expect("captured frame count reserved");
        }
        let truncated_stacks = self
            .records
            .iter()
            .filter(|record| record.depth_truncated || record.stack_truncated)
            .count();
        let reason = self.finish.map(|finish| finish.reason);
        let final_counter_snapshot_ns = self.finish.and_then(|finish| finish.counter_snapshot_ns);
        let timestamps_valid = self.counters.invalid_observations == 0
            && reason != Some(SamplingTermination::ClockFailure)
            && self.failure != Some(SamplingTermination::ClockFailure);
        let counters_valid = !self.counter_overflow
            && self.failure.is_none()
            && !matches!(
                reason,
                Some(SamplingTermination::CounterFailure | SamplingTermination::CallbackFailure)
            );
        let final_counters_available =
            final_counter_snapshot_ns.is_some() && counters_valid && timestamps_valid;
        let failed = reason.is_some_and(|reason| {
            !matches!(
                reason,
                SamplingTermination::Stopped | SamplingTermination::Capacity
            )
        });
        let status = if !final_counters_available || failed {
            "incomplete"
        } else if truncated_stacks > 0
            || self.counters.dropped_ticks > 0
            || self.counters.dropped_samples > 0
            || self.counters.unserved_wakes > 0
            || reason == Some(SamplingTermination::Capacity)
        {
            "truncated"
        } else {
            "complete"
        };
        let mut provenance = BudgetMap::try_with_capacity(7, &self.budget)?;
        for (name, value) in [
            (
                "timestamps",
                if timestamps_valid {
                    "measured:absolute-monotonic-wall-clock;unit=ns;wakes-are-producer-observations;service-is-VM-safepoint;not-CPU-time"
                } else {
                    "unavailable:clock-failure-or-invalid-order;retained-values-are-raw-observations"
                },
            ),
            (
                "due_ns",
                "derived:scheduled-monotonic-timer-deadline;unit=ns;not-observed-execution-time",
            ),
            (
                "stacks",
                "measured:one-stack-per-serviced-batch;leaf-to-root;VM-safepoint-biased;long-internal-calls-delay-service;coalesced-wakes-do-not-synthesize-stacks",
            ),
            (
                "counters",
                if !counters_valid {
                    "unavailable:counter-overflow-or-backend-failure;numeric-values-are-raw-or-capped"
                } else {
                    "measured:accepted-wakes-and-loss-deltas;unit=count;coalesced-wakes-derived-as-batch-wakes-minus-one"
                },
            ),
            (
                "final_counters",
                if final_counters_available {
                    "measured:backend-stop-loss-deltas;unit=count;scope=counter-snapshot-timestamp"
                } else {
                    "unavailable:backend-stop-counters-or-timestamp-not-valid;zero-unserved-is-not-a-measurement"
                },
            ),
            (
                "captured_samples",
                "derived:number-of-retained-actual-stack-records;not-timer-wake-count",
            ),
            (
                "truncated_stacks",
                "derived:number-of-retained-partial-stacks;unit=count",
            ),
        ] {
            provenance
                .insert(name.into(), value.into())
                .expect("seven sampling provenance entries reserved");
        }
        Ok(SamplingReport {
            mode: "wall-safepoint",
            interval_ns: self.interval.get(),
            start_ns: self.start,
            end_ns: self.end,
            stack_depth_limit: SAMPLE_STACK_LIMIT,
            status,
            termination_reason: reason.map_or("unreported", SamplingTermination::name),
            final_counters_available,
            final_counter_snapshot_ns,
            failure_reason: self.failure.map(SamplingTermination::name),
            counter_overflow: self.counter_overflow,
            timestamps_valid,
            counters_valid,
            counters: self.counters,
            captured_samples: self.records.len(),
            truncated_stacks,
            symbols,
            samples,
            frames,
            metric_provenance: provenance,
        })
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::Engine;
    struct CountingFrames<'a> {
        visits: &'a std::cell::Cell<usize>,
        next: Option<&'static str>,
    }
    impl Iterator for CountingFrames<'_> {
        type Item = &'static str;
        fn next(&mut self) -> Option<Self::Item> {
            self.visits.set(self.visits.get() + 1);
            self.next.take()
        }
    }
    fn observation() -> SampleObservation {
        SampleObservation {
            due_ns: 1,
            first_wake_ns: 2,
            last_wake_ns: 3,
            service_ns: 4,
            context_id: 7,
            wake_count: 2,
            dropped_ticks: 0,
            depth_truncated: false,
        }
    }
    fn finish() -> SamplingFinish {
        SamplingFinish {
            counter_snapshot_ns: Some(8),
            unserved_wakes: 0,
            dropped_ticks: 0,
            reason: SamplingTermination::Stopped,
        }
    }
    fn engine(limit: usize) -> Engine {
        let mut engine = Engine::try_with_sampling(
            limit,
            false,
            Budget::new(crate::DEFAULT_BUDGET_BYTES),
            NonZeroU64::new(1_000_000).unwrap(),
        )
        .unwrap();
        engine.start(0, 0, 0);
        engine
    }
    #[test]
    fn sampled_v2_is_independent_and_legacy_v1_has_no_sampling_fields() {
        let legacy = Engine::new(1).finish(0, 0, 0);
        let json: serde_json::Value = serde_json::from_str(&legacy.json().unwrap()).unwrap();
        assert_eq!(json["schema_version"], 1);
        assert!(json.get("sampling").is_none());
        // Golden emitted by the pre-sampling archived core, not regenerated
        // from this implementation: opt-out retains the complete v1 byte shape.
        assert_eq!(
            legacy.json().unwrap().as_str(),
            include_str!("../tests/fixtures/report-v1-empty.json")
        );
        let mut e = engine(2);
        e.record_sample(observation(), ["preexisting", "ancestor"]);
        e.enter(1, "actual", 5, 1, 0);
        e.exit(1, 6, 2, 0);
        e.finish_sampling(finish());
        let report = e.finish(10, 3, 0);
        assert_eq!(report.schema_version, 2);
        assert_eq!(report.status, "complete");
        assert_eq!(report.functions.len(), 1);
        assert_eq!(report.functions["actual"].calls, 1);
        assert!(report.capabilities["wall_safepoint_sampling"]);
        assert!(!report.capabilities["statistical_sampling"]);
        let sample = report.sampling.as_ref().unwrap();
        assert_eq!(sample.status, "complete");
        assert_eq!(sample.start_ns, Some(0));
        assert_eq!(sample.end_ns, Some(10));
        assert_eq!(sample.symbols[0].as_str(), "preexisting");
        assert_eq!(sample.symbols[1].as_str(), "ancestor");
        assert_eq!(sample.frames.iter().copied().collect::<Vec<_>>(), [0, 1]);
        assert_eq!(sample.samples[0].context_id, 7);
        assert_eq!(sample.samples[0].wake_count, 2);
        assert_eq!(sample.counters.serviced_wakes, 2);
        assert_eq!(sample.counters.coalesced_wakes, 1);
    }
    #[test]
    fn sample_limits_preserve_known_ids_and_do_not_consume_graph_capacity() {
        let mut e = engine(2);
        e.record_sample(observation(), ["b", "a"]);
        e.record_sample(observation(), ["b", "new"]);
        let visits = std::cell::Cell::new(0);
        e.record_sample(
            observation(),
            CountingFrames {
                visits: &visits,
                next: Some("not-evaluated"),
            },
        );
        assert_eq!(visits.get(), 0);
        e.enter(1, "graph-one", 5, 1, 0);
        e.exit(1, 6, 2, 0);
        e.enter(2, "graph-two", 7, 3, 0);
        e.exit(2, 8, 4, 0);
        e.finish_sampling(finish());
        let r = e.finish(10, 5, 0);
        assert_eq!(r.status, "complete");
        assert_eq!(r.functions.len(), 2);
        let s = r.sampling.unwrap();
        assert_eq!(s.status, "truncated");
        assert_eq!(s.symbols.len(), 2);
        assert_eq!(s.samples.len(), 2);
        assert_eq!(s.samples[1].first_frame, 2);
        assert_eq!(s.samples[1].frame_count, 1);
        assert!(s.samples[1].stack_truncated);
        assert_eq!(s.frames.iter().copied().collect::<Vec<_>>(), [0, 1, 0]);
        assert_eq!(s.counters.dropped_samples, 1);
    }
    #[test]
    fn depth_limits_oversized_symbols_and_empty_stacks_are_explicit() {
        let mut e = engine(4);
        e.record_sample(observation(), std::iter::repeat_n("deep", 65));
        let oversized = "x".repeat(1025);
        e.record_sample(observation(), [oversized.as_str()]);
        e.record_sample(
            SampleObservation {
                depth_truncated: true,
                ..observation()
            },
            std::iter::empty::<&str>(),
        );
        e.record_sample(observation(), std::iter::empty::<&str>());
        e.finish_sampling(finish());
        let r = e.finish(10, 0, 0);
        let s = r.sampling.unwrap();
        assert_eq!(s.status, "truncated");
        assert_eq!(s.truncated_stacks, 3);
        assert_eq!(s.samples[0].frame_count, 64);
        assert!(s.samples[0].depth_truncated);
        assert!(!s.samples[0].stack_truncated);
        assert!(s.samples[1].stack_truncated);
        assert_eq!(s.samples[1].frame_count, 0);
        assert_eq!(s.samples[3].frame_count, 0);
        assert_eq!(s.frames.len(), 64);
        assert_eq!(s.symbols.len(), 1);
    }
    #[test]
    fn invalid_timestamp_orders_do_not_create_stacks_or_damage_graph_metrics() {
        let mut e = engine(8);
        let mut cases = [observation(); 4];
        cases[0].due_ns = 3;
        cases[1].first_wake_ns = 4;
        cases[2].last_wake_ns = 5;
        cases[3].first_wake_ns = 0;
        e.sampling.as_mut().unwrap().start = Some(1);
        for item in cases {
            e.record_sample(item, ["never-interned"]);
        }
        e.finish_sampling(finish());
        let r = e.finish(10, 0, 0);
        assert_eq!(r.status, "complete");
        let s = r.sampling.unwrap();
        assert_eq!(s.counters.invalid_observations, 4);
        assert_eq!(s.counters.dropped_samples, 4);
        assert_eq!(s.status, "incomplete");
        assert!(!s.timestamps_valid);
        assert!(s.counters_valid);
        assert!(s.samples.is_empty());
        assert!(s.symbols.is_empty());
        let mut unstarted =
            Engine::try_with_sampling(1, false, Budget::new(100_000), NonZeroU64::new(1).unwrap())
                .unwrap();
        unstarted.record_sample(observation(), ["before-start"]);
        let s = unstarted.finish(10, 0, 0).sampling.unwrap();
        assert_eq!(s.counters.invalid_observations, 1);
        assert!(!s.final_counters_available);
    }
    #[test]
    fn drop_only_batches_and_all_backend_terminations_have_separate_status() {
        let reasons = [
            (SamplingTermination::Stopped, "stopped", "complete"),
            (SamplingTermination::Fork, "fork", "incomplete"),
            (
                SamplingTermination::ClockFailure,
                "clock_failure",
                "incomplete",
            ),
            (
                SamplingTermination::ThreadFailure,
                "thread_failure",
                "incomplete",
            ),
            (SamplingTermination::Capacity, "capacity", "truncated"),
            (
                SamplingTermination::CallbackFailure,
                "callback_failure",
                "incomplete",
            ),
            (
                SamplingTermination::CounterFailure,
                "counter_failure",
                "incomplete",
            ),
            (
                SamplingTermination::IdentityFailure,
                "identity_failure",
                "incomplete",
            ),
        ];
        for (reason, name, status) in reasons {
            let mut e = engine(1);
            e.finish_sampling(SamplingFinish { reason, ..finish() });
            let s = e.finish(10, 0, 0).sampling.unwrap();
            assert_eq!(s.termination_reason, name);
            assert_eq!(s.status, status);
            assert_eq!(
                s.timestamps_valid,
                reason != SamplingTermination::ClockFailure
            );
        }
        let mut e = engine(1);
        let visits = std::cell::Cell::new(0);
        e.record_sample(
            SampleObservation {
                wake_count: 0,
                dropped_ticks: 2,
                ..SampleObservation::default()
            },
            CountingFrames {
                visits: &visits,
                next: Some("not-evaluated"),
            },
        );
        assert_eq!(visits.get(), 0);
        e.finish_sampling(SamplingFinish {
            unserved_wakes: 3,
            dropped_ticks: 4,
            ..finish()
        });
        let s = e.finish(10, 0, 0).sampling.unwrap();
        assert_eq!(s.counters.dropped_ticks, 6);
        assert_eq!(s.counters.unserved_wakes, 3);
        assert_eq!(s.counters.serviced_wakes, 0);
        assert!(s.samples.is_empty());
        assert_eq!(s.status, "truncated");
    }
    #[test]
    fn first_sampling_stop_and_graph_seal_survive_quota_retry() {
        let mut e = engine(2);
        e.record_sample(observation(), ["preserved"]);
        e.finish_sampling(SamplingFinish {
            counter_snapshot_ns: Some(8),
            unserved_wakes: 2,
            dropped_ticks: 3,
            reason: SamplingTermination::Fork,
        });
        e.finish_sampling(finish());
        let visits = std::cell::Cell::new(0);
        e.record_sample(
            observation(),
            CountingFrames {
                visits: &visits,
                next: Some("not-evaluated"),
            },
        );
        assert_eq!(visits.get(), 0);
        let b = e.budget();
        let before = b.used();
        let block = b.try_reserve(b.available()).unwrap();
        assert!(matches!(e.try_finish(10, 1, 0), Err(BudgetError::Limit)));
        e.finish_sampling(finish());
        e.record_sample(observation(), ["late"]);
        drop(block);
        let mut r = e.try_finish(100, 100, 100).unwrap();
        let s = r.sampling.take().unwrap();
        assert_eq!(s.end_ns, Some(10));
        assert_eq!(s.termination_reason, "fork");
        assert_eq!(s.symbols[0], "preserved");
        assert_eq!(s.samples.len(), 1);
        assert_eq!(s.counters.dropped_ticks, 3);
        assert_eq!(s.counters.unserved_wakes, 2);
        drop(s);
        drop(r);
        assert_eq!(b.used(), before);
        let mut missing = engine(1);
        let b = missing.budget();
        let block = b.try_reserve(b.available()).unwrap();
        assert!(missing.try_finish(10, 0, 0).is_err());
        drop(block);
        missing.finish_sampling(finish());
        let s = missing.finish(20, 0, 0).sampling.unwrap();
        assert!(!s.final_counters_available);
        assert_eq!(s.termination_reason, "unreported");
        assert_eq!(s.status, "incomplete");
        let mut legacy = Engine::new(1);
        legacy.record_sample(observation(), ["ignored"]);
        legacy.finish_sampling(finish());
        assert!(legacy.finish(10, 0, 0).sampling.is_none());
    }
    #[test]
    fn counter_overflows_are_explicit_and_do_not_poison_instrumentation() {
        let mut e = engine(2);
        let s = e.sampling.as_mut().unwrap();
        s.counters.serviced_wakes = u64::MAX;
        s.counters.coalesced_wakes = u64::MAX;
        s.counters.dropped_ticks = u64::MAX;
        s.counters.unserved_wakes = u64::MAX;
        e.record_sample(
            SampleObservation {
                dropped_ticks: 1,
                ..observation()
            },
            ["raw"],
        );
        e.finish_sampling(SamplingFinish {
            unserved_wakes: 1,
            ..finish()
        });
        let r = e.finish(10, 0, 0);
        assert_eq!(r.status, "complete");
        assert_eq!(r.metric_overflows, 0);
        let s = r.sampling.unwrap();
        assert!(s.counter_overflow);
        assert!(!s.counters_valid);
        assert_eq!(s.status, "incomplete");
        assert_eq!(s.counters.serviced_wakes, u64::MAX);
        assert!(s.metric_provenance["counters"].starts_with("unavailable:"));
    }
    #[test]
    fn lazy_iterator_unwind_retains_an_explicit_partial_stack_and_reuses_remaining_pool() {
        let mut e = engine(2);
        let mut calls = 0;
        let frames = std::iter::from_fn(|| {
            calls += 1;
            if calls == 1 {
                Some("prefix")
            } else {
                panic!("caller frame rendering failure")
            }
        });
        assert!(std::panic::catch_unwind(std::panic::AssertUnwindSafe(
            || e.record_sample(observation(), frames)
        ))
        .is_err());
        e.record_sample(observation(), ["prefix"]);
        e.finish_sampling(finish());
        let s = e.finish(10, 0, 0).sampling.unwrap();
        assert_eq!(s.samples.len(), 2);
        assert_eq!(s.samples[0].frame_count, 1);
        assert!(s.samples[0].stack_truncated);
        assert!(!s.samples[1].stack_truncated);
        assert_eq!(s.frames.iter().copied().collect::<Vec<_>>(), [0, 0]);
        assert_eq!(s.truncated_stacks, 1);
    }
    #[test]
    fn independent_index_handles_many_symbols_and_zero_capacity_is_bounded() {
        let mut e = engine(16);
        let names: Vec<_> = (0..16).map(|i| format!("symbol-{i}")).collect();
        e.record_sample(observation(), names.iter());
        e.record_sample(observation(), ["symbol-0", "symbol-15"]);
        e.record_sample(observation(), ["unknown-after-full-index"]);
        e.finish_sampling(finish());
        let s = e.finish(10, 0, 0).sampling.unwrap();
        assert_eq!(s.symbols.len(), 16);
        assert_eq!(s.frames[16], 0);
        assert_eq!(s.frames[17], 15);
        assert!(s.samples[2].stack_truncated);
        assert_eq!(s.samples[2].frame_count, 0);
        assert_eq!(s.status, "truncated");
        let b = Budget::new(100_000);
        let before = b.used();
        assert!(matches!(
            SamplingCapture::try_new(usize::MAX, NonZeroU64::new(1).unwrap(), b.clone()),
            Err(BudgetError::Overflow)
        ));
        assert_eq!(b.used(), before);
        let mut empty = engine(0);
        empty.record_sample(observation(), ["not-retained"]);
        empty.finish_sampling(finish());
        let s = empty.finish(10, 0, 0).sampling.unwrap();
        assert!(s.samples.is_empty());
        assert!(s.frames.is_empty());
        assert_eq!(s.counters.dropped_samples, 1);
    }
    #[test]
    fn sealing_rejects_service_after_stop_and_does_not_replace_first_window() {
        let mut e = engine(1);
        e.record_sample(observation(), ["sample"]);
        e.finish_sampling(finish());
        let r = e.try_finish(3, 0, 0).unwrap();
        let s = r.sampling.unwrap();
        assert_eq!(s.status, "incomplete");
        assert!(!s.timestamps_valid);
        assert_eq!(s.end_ns, Some(3));
        let s = e.try_finish(20, 0, 0).unwrap().sampling.unwrap();
        assert_eq!(s.end_ns, Some(3));
        let mut missing =
            SamplingCapture::try_new(1, NonZeroU64::new(1).unwrap(), Budget::new(100_000)).unwrap();
        missing.seal(0);
        missing.seal(10);
        let s = missing.report().unwrap();
        assert_eq!(s.end_ns, Some(0));
        assert_eq!(s.status, "incomplete");
    }
    #[test]
    fn final_snapshot_availability_and_transport_failures_never_fabricate_valid_counts() {
        let mut e = engine(1);
        e.record_sample(observation(), ["raw"]);
        e.finish_sampling(SamplingFinish {
            counter_snapshot_ns: None,
            ..finish()
        });
        let s = e.finish(10, 0, 0).sampling.unwrap();
        assert!(!s.final_counters_available);
        assert_eq!(s.final_counter_snapshot_ns, None);
        assert_eq!(s.status, "incomplete");
        for snapshot in [0, 3, 11] {
            let mut e = engine(1);
            e.sampling.as_mut().unwrap().start = Some(1);
            e.record_sample(observation(), ["raw"]);
            e.finish_sampling(SamplingFinish {
                counter_snapshot_ns: Some(snapshot),
                ..finish()
            });
            let s = e.finish(10, 0, 0).sampling.unwrap();
            assert!(!s.final_counters_available);
            assert!(!s.timestamps_valid);
            assert_eq!(s.status, "incomplete");
        }
        for reason in [
            SamplingTermination::CallbackFailure,
            SamplingTermination::CounterFailure,
        ] {
            let mut e = engine(1);
            e.record_sample(observation(), ["raw"]);
            e.mark_sampling_unavailable(reason);
            e.mark_sampling_unavailable(SamplingTermination::ClockFailure);
            e.finish_sampling(finish());
            let mut r = e.try_finish(10, 0, 0).unwrap();
            assert_eq!(r.status, "complete");
            assert_eq!(r.metric_overflows, 0);
            let s = r.sampling.take().unwrap();
            assert!(!s.counters_valid);
            assert!(!s.final_counters_available);
            assert_eq!(s.failure_reason, Some(reason.name()));
            assert_eq!(s.status, "incomplete");
            e.mark_sampling_unavailable(SamplingTermination::ClockFailure);
            let s = e.finish(20, 0, 0).sampling.unwrap();
            assert_eq!(s.failure_reason, Some(reason.name()));
        }
        let mut e = engine(1);
        e.mark_sampling_unavailable(SamplingTermination::ClockFailure);
        e.finish_sampling(finish());
        assert!(!e.finish(10, 0, 0).sampling.unwrap().timestamps_valid);
        let mut legacy = Engine::new(1);
        legacy.mark_sampling_unavailable(SamplingTermination::CallbackFailure);
        assert!(legacy.finish(10, 0, 0).sampling.is_none());
    }
    #[test]
    fn sampled_schema_extends_v1_without_redefining_existing_graph_fields() {
        let v1: serde_json::Value =
            serde_json::from_str(include_str!("../../../schema/report-v1.json")).unwrap();
        let v2: serde_json::Value =
            serde_json::from_str(include_str!("../../../schema/report-v2.json")).unwrap();
        assert_eq!(v1["$defs"], v2["$defs"]);
        for (key, value) in v1["properties"].as_object().unwrap() {
            if key != "schema_version" {
                assert_eq!(v2["properties"][key], *value);
            }
        }
        let mut e = engine(1);
        e.record_sample(observation(), ["raw"]);
        e.finish_sampling(finish());
        let report = e.finish(10, 0, 0);
        let json: serde_json::Value = serde_json::from_str(&report.json().unwrap()).unwrap();
        let schema = &v2["properties"]["sampling"]["properties"];
        for key in json["sampling"].as_object().unwrap().keys() {
            assert!(schema.get(key).is_some(), "missing {key}");
        }
        for key in json["sampling"]["samples"][0].as_object().unwrap().keys() {
            assert!(
                schema["samples"]["items"]["properties"].get(key).is_some(),
                "missing record {key}"
            );
        }
        for key in json["sampling"]["counters"].as_object().unwrap().keys() {
            assert!(
                schema["counters"]["properties"].get(key).is_some(),
                "missing counter {key}"
            );
        }
        assert_eq!(json["schema_version"], 2);
        assert_eq!(json["sampling"]["final_counter_snapshot_ns"], 8);
    }
    #[test]
    fn service_clock_regressions_are_detected_even_after_record_storage_is_full() {
        let mut e = engine(1);
        e.record_sample(
            SampleObservation {
                service_ns: 7,
                ..observation()
            },
            ["first"],
        );
        e.record_sample(
            SampleObservation {
                service_ns: 9,
                ..observation()
            },
            ["full"],
        );
        e.record_sample(
            SampleObservation {
                service_ns: 8,
                ..observation()
            },
            ["regressed"],
        );
        e.finish_sampling(SamplingFinish {
            counter_snapshot_ns: Some(9),
            ..finish()
        });
        let s = e.finish(10, 0, 0).sampling.unwrap();
        assert_eq!(s.samples.len(), 1);
        assert_eq!(s.counters.invalid_observations, 1);
        assert_eq!(s.counters.dropped_samples, 2);
        assert!(!s.timestamps_valid);
        assert_eq!(s.status, "incomplete");
    }
    #[test]
    fn final_snapshot_covers_even_service_batches_dropped_after_capacity() {
        let mut e = engine(1);
        e.record_sample(observation(), ["first"]);
        e.record_sample(
            SampleObservation {
                service_ns: 8,
                ..observation()
            },
            ["full"],
        );
        e.finish_sampling(SamplingFinish {
            counter_snapshot_ns: Some(7),
            ..finish()
        });
        let s = e.finish(10, 0, 0).sampling.unwrap();
        assert!(!s.final_counters_available);
        assert!(!s.timestamps_valid);
        assert_eq!(s.counters.invalid_observations, 1);
        assert_eq!(s.samples.len(), 1);
    }
    #[test]
    fn exact_timestamp_boundaries_and_counter_saturation_are_not_silent_estimates() {
        let mut e = engine(1);
        e.record_sample(
            SampleObservation {
                wake_count: 1,
                ..SampleObservation::default()
            },
            ["zero-time"],
        );
        e.finish_sampling(SamplingFinish {
            counter_snapshot_ns: Some(0),
            ..finish()
        });
        let s = e.finish(0, 0, 0).sampling.unwrap();
        assert_eq!(s.status, "complete");
        assert!(s.timestamps_valid);
        assert!(s.final_counters_available);
        assert_eq!(s.final_counter_snapshot_ns, Some(0));
        let mut value = u64::MAX - 1;
        let mut overflow = false;
        SamplingCapture::add(&mut value, 1, &mut overflow);
        assert_eq!(value, u64::MAX);
        assert!(!overflow);
        let mut value = u64::MAX - 1;
        SamplingCapture::add(&mut value, 2, &mut overflow);
        assert_eq!(value, u64::MAX);
        assert!(overflow);
        SamplingCapture::add(&mut value, 0, &mut overflow);
        assert_eq!(value, u64::MAX);
        assert!(overflow);
    }
    #[test]
    fn admitted_lazy_frames_are_evaluated_once_and_all_capture_pools_are_accounted() {
        let mut e = engine(1);
        let b = e.budget();
        assert_eq!(
            e.capture_allocation_bytes() + Budget::control_bytes(),
            b.used()
        );
        let visits = std::cell::Cell::new(0);
        e.record_sample(
            observation(),
            CountingFrames {
                visits: &visits,
                next: Some("actual"),
            },
        );
        assert_eq!(visits.get(), 2);
        e.finish_sampling(finish());
        let s = e.finish(10, 0, 0).sampling.unwrap();
        assert_eq!(s.samples[0].frame_count, 1);
        assert_eq!(s.symbols[0], "actual");
    }
    #[test]
    fn unserved_stop_wakes_are_loss_even_without_ring_or_record_drops() {
        let mut e = engine(1);
        e.record_sample(observation(), ["retained"]);
        e.finish_sampling(SamplingFinish {
            unserved_wakes: 1,
            ..finish()
        });
        let s = e.finish(10, 0, 0).sampling.unwrap();
        assert_eq!(s.status, "truncated");
        assert_eq!(s.truncated_stacks, 0);
        assert_eq!(s.counters.dropped_ticks, 0);
        assert_eq!(s.counters.dropped_samples, 0);
        assert_eq!(s.counters.unserved_wakes, 1);
        let mut capture =
            SamplingCapture::try_new(1, NonZeroU64::new(1).unwrap(), Budget::new(100_000)).unwrap();
        capture.start(0);
        capture.seal(0);
        capture.record(observation(), ["late"]);
        capture.finish(finish());
        let s = capture.report().unwrap();
        assert!(s.samples.is_empty());
        assert!(!s.final_counters_available);
        assert_eq!(s.termination_reason, "unreported");
    }
    #[test]
    fn mixed_callback_batches_preserve_a_reference_trace_across_admission_states() {
        fn push(capture: &mut SamplingCapture, observation: SampleObservation, frames: &[&str]) {
            capture.record(observation, frames.iter().copied());
        }
        let budget = Budget::new(100_000);
        let mut capture =
            SamplingCapture::try_new(3, NonZeroU64::new(1).unwrap(), budget.clone()).unwrap();
        push(&mut capture, observation(), &["unstarted"]);
        capture.start(0);
        push(
            &mut capture,
            SampleObservation {
                wake_count: 0,
                dropped_ticks: 2,
                ..SampleObservation::default()
            },
            &["loss-only"],
        );
        push(
            &mut capture,
            SampleObservation {
                due_ns: 3,
                ..observation()
            },
            &["invalid-due"],
        );
        push(
            &mut capture,
            SampleObservation {
                first_wake_ns: 4,
                ..observation()
            },
            &["invalid-first"],
        );
        push(
            &mut capture,
            SampleObservation {
                last_wake_ns: 5,
                ..observation()
            },
            &["invalid-last"],
        );
        push(&mut capture, observation(), &["a"]);
        push(
            &mut capture,
            SampleObservation {
                service_ns: 3,
                ..observation()
            },
            &["regressed-service"],
        );
        push(
            &mut capture,
            observation(),
            &["b", "c", "over-symbol-capacity"],
        );
        push(&mut capture, observation(), &["a"; 65]);
        push(&mut capture, observation(), &["over-record-capacity"]);
        capture.finish(finish());
        capture.finish(SamplingFinish {
            unserved_wakes: 99,
            dropped_ticks: 99,
            ..finish()
        });
        push(&mut capture, observation(), &["after-finish"]);
        capture.seal(10);
        push(&mut capture, observation(), &["after-seal"]);
        let report = capture.report().unwrap();
        assert_eq!(report.captured_samples, 3);
        assert_eq!(report.symbols.len(), 3);
        assert_eq!(report.symbols[0], "a");
        assert_eq!(report.symbols[1], "b");
        assert_eq!(report.symbols[2], "c");
        assert_eq!(report.samples[0].frame_count, 1);
        assert_eq!(report.samples[1].frame_count, 2);
        assert!(report.samples[1].stack_truncated);
        assert_eq!(report.samples[2].first_frame, 3);
        assert_eq!(report.samples[2].frame_count, 64);
        assert!(report.samples[2].depth_truncated);
        assert_eq!(report.frames.len(), 67);
        assert_eq!(report.frames[0], 0);
        assert_eq!(report.frames[1], 1);
        assert_eq!(report.frames[2], 2);
        assert_eq!(report.counters.serviced_wakes, 18);
        assert_eq!(report.counters.coalesced_wakes, 9);
        assert_eq!(report.counters.dropped_ticks, 2);
        assert_eq!(report.counters.dropped_samples, 6);
        assert_eq!(report.counters.invalid_observations, 5);
        assert_eq!(report.truncated_stacks, 2);
        assert_eq!(report.status, "incomplete");
        let mut scoped = SamplingCapture::try_new(1, NonZeroU64::new(1).unwrap(), budget).unwrap();
        scoped.start(3);
        push(&mut scoped, observation(), &["before-window"]);
        assert_eq!(scoped.counters.invalid_observations, 1);
    }
    #[test]
    fn counter_or_callback_failure_at_stop_invalidates_unobserved_loss_counters() {
        for reason in [
            SamplingTermination::CounterFailure,
            SamplingTermination::CallbackFailure,
        ] {
            let mut e = engine(1);
            e.finish_sampling(SamplingFinish { reason, ..finish() });
            let r = e.finish(10, 0, 0);
            assert_eq!(r.status, "complete");
            let s = r.sampling.unwrap();
            assert!(!s.counters_valid);
            assert!(!s.final_counters_available);
            assert_eq!(s.status, "incomplete");
            assert!(s.metric_provenance["counters"].starts_with("unavailable:"));
        }
        for reason in [
            SamplingTermination::ThreadFailure,
            SamplingTermination::IdentityFailure,
        ] {
            let mut e = engine(1);
            e.finish_sampling(SamplingFinish { reason, ..finish() });
            let s = e.finish(10, 0, 0).sampling.unwrap();
            assert!(s.counters_valid);
            assert!(s.final_counters_available);
            assert_eq!(s.status, "incomplete");
        }
    }
    #[test]
    fn sampling_symbol_byte_limit_admits_exactly_1024_and_rejects_1025() {
        let accepted = "n".repeat(1024);
        let rejected = "n".repeat(1025);
        let mut e = engine(3);
        for name in [&accepted, &rejected, &accepted] {
            e.record_sample(observation(), [name.as_str()]);
        }
        e.finish_sampling(finish());
        let s = e.finish(10, 0, 0).sampling.unwrap();
        assert_eq!(s.symbols.len(), 1);
        assert_eq!(s.symbols[0].as_str(), accepted);
        assert_eq!(s.samples.len(), 3);
        assert_eq!(s.samples[0].frame_count, 1);
        assert!(!s.samples[0].stack_truncated);
        assert_eq!(s.samples[1].frame_count, 0);
        assert!(s.samples[1].stack_truncated);
        assert_eq!(s.samples[2].frame_count, 1);
        assert!(!s.samples[2].stack_truncated);
        assert_eq!(s.frames.iter().copied().collect::<Vec<_>>(), [0, 0]);
        assert_eq!(s.truncated_stacks, 1);
    }
    #[test]
    fn each_seal_clock_disjunct_independently_invalidates_timestamps() {
        let mut e = engine(1);
        e.record_sample(observation(), ["actual"]);
        e.finish_sampling(SamplingFinish {
            counter_snapshot_ns: None,
            ..finish()
        });
        let s = e.finish(3, 0, 0).sampling.unwrap();
        assert_eq!(s.counters.invalid_observations, 1);
        assert!(!s.timestamps_valid);
        assert_eq!(s.status, "incomplete");
        let mut capture =
            SamplingCapture::try_new(1, NonZeroU64::new(1).unwrap(), Budget::new(100_000)).unwrap();
        capture.start(10);
        capture.finish(SamplingFinish {
            counter_snapshot_ns: None,
            ..finish()
        });
        capture.seal(9);
        let s = capture.report().unwrap();
        assert!(s.samples.is_empty());
        assert_eq!(s.counters.invalid_observations, 1);
        assert!(!s.timestamps_valid);
        assert_eq!(s.status, "incomplete");
    }
    #[test]
    fn each_sampling_loss_cause_independently_truncates_valid_stopped_capture() {
        for cause in 0..6 {
            let mut e = engine(1);
            e.record_sample(
                SampleObservation {
                    dropped_ticks: u64::from(cause == 0),
                    depth_truncated: cause == 2,
                    ..observation()
                },
                if cause == 3 {
                    vec!["retained", "unadmitted"]
                } else {
                    vec!["retained"]
                },
            );
            if cause == 1 {
                e.record_sample(observation(), ["omitted-record"]);
            }
            e.finish_sampling(SamplingFinish {
                unserved_wakes: u64::from(cause == 4),
                reason: if cause == 5 {
                    SamplingTermination::Capacity
                } else {
                    SamplingTermination::Stopped
                },
                ..finish()
            });
            let r = e.finish(10, 0, 0);
            assert_eq!(r.status, "complete");
            let s = r.sampling.unwrap();
            assert_eq!(s.status, "truncated", "independent cause {cause}");
            assert!(s.timestamps_valid);
            assert!(s.counters_valid);
            assert!(s.final_counters_available);
            assert_eq!(s.counters.dropped_ticks, u64::from(cause == 0));
            assert_eq!(s.counters.dropped_samples, u64::from(cause == 1));
            assert_eq!(s.counters.unserved_wakes, u64::from(cause == 4));
            assert_eq!(s.counters.invalid_observations, 0);
            assert_eq!(s.truncated_stacks, usize::from(cause == 2 || cause == 3));
            assert_eq!(s.samples.len(), 1);
        }
    }
}
