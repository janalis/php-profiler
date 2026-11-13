//! Zend-independent bounded instrumentation and local JSON reports.
mod budget;
mod index;
use index::{fingerprint, Index};
mod sampling;
mod serialization;
pub use sampling::{
    SampleObservation, SampleRecord, SamplingCounters, SamplingFinish, SamplingReport,
    SamplingTermination, SAMPLE_STACK_LIMIT,
};
mod storage;
pub use budget::{Budget, BudgetError, Reservation, DEFAULT_BUDGET_BYTES};
pub use serialization::{JsonBuffer, JsonError};
pub use storage::{BudgetMap, BudgetText, BudgetVec};

use serde::Serialize;

/// Measurements captured at one instrumentation boundary. CPU is thread CPU.
#[derive(Clone, Copy, Debug, Default, Serialize)]
struct Snapshot {
    wall: u64,
    cpu: u64,
    memory: u64,
}

#[derive(Clone, Debug, Default, Serialize, PartialEq, Eq)]
pub struct Metrics {
    pub calls: u64,
    pub exceptions: u64,
    pub inclusive_wall_ns: u64,
    pub exclusive_wall_ns: u64,
    pub inclusive_cpu_ns: u64,
    pub exclusive_cpu_ns: u64,
    /// Signed difference of Zend heap usage at entry and exit, not allocation volume.
    pub memory_delta_bytes: i64,
}
#[derive(Debug, Serialize)]
pub struct Edge {
    pub caller: BudgetText,
    pub callee: BudgetText,
    pub metrics: Metrics,
}
#[derive(Debug, Serialize)]
pub struct Event {
    pub frame_id: u64,
    pub parent_frame_id: Option<u64>,
    pub symbol: BudgetText,
    pub start_ns: u64,
    pub end_ns: u64,
    pub completed: bool,
    pub outcome: BudgetText,
    pub context_id: u64,
    pub active_wall_ns: u64,
    pub active_cpu_ns: u64,
    pub suspended_wall_ns: u64,
}
/// A derived ranking entry; metrics are unchanged function aggregates.
#[derive(Debug, Serialize)]
pub struct Hotspot {
    pub symbol: BudgetText,
    pub metrics: Metrics,
}
#[derive(Serialize)]
pub struct Report {
    pub schema_version: u32,
    pub termination_reason: BudgetText,
    pub runtime_metadata: BudgetMap<BudgetText>,
    pub status: BudgetText,
    pub elapsed_wall_ns: u64,
    pub thread_cpu_ns: u64,
    pub memory_delta_bytes: i64,
    /// Maximum of entry/exit heap observations; not an exact session peak.
    pub observed_boundary_peak_bytes: u64,
    /// Zend peak-counter snapshot when available; PHP code may reset its window.
    pub request_peak_bytes: Option<u64>,
    pub dropped_calls: u64,
    pub dropped_timeline_events: u64,
    pub unmatched_exits: u64,
    pub clock_regressions: u64,
    pub metric_overflows: u64,
    /// Names apply to every instance of the metric, including aggregates/timelines.
    pub unavailable_metrics: BudgetVec<BudgetText>,
    pub functions: BudgetMap<Metrics>,
    pub hotspots: BudgetVec<Hotspot>,
    pub edges: BudgetVec<Edge>,
    pub timeline: BudgetVec<Event>,
    pub limitations: BudgetVec<BudgetText>,
    pub metric_provenance: BudgetMap<BudgetText>,
    pub capabilities: BudgetMap<bool>,
    pub operations: BudgetMap<Metrics>,
    pub timeline_enabled: bool,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub sampling: Option<SamplingReport>,
    #[serde(skip)]
    budget: Budget,
}
impl Report {
    pub fn budget(&self) -> Budget {
        self.budget.clone()
    }
    /// Native clock failure can yield zero without a detectable numeric regression.
    pub fn mark_clock_failure(&mut self) {
        self.status = "incomplete".into();
        self.hotspots.clear();
        self.mark_unavailable(
            TIMING_FIELDS,
            "unavailable:clock-failure-or-regression;numeric-values-are-not-valid-measurements",
        );
    }
    /// Zend MM can be disabled; zero counters then mean unavailable, not no memory.
    pub fn mark_memory_unavailable(&mut self) {
        self.request_peak_bytes = None;
        self.capabilities
            .insert("boundary_memory".into(), false)
            .expect("reserved capability slot");
        self.mark_unavailable(
            &[
                "memory_delta_bytes",
                "session.memory_delta_bytes",
                "observed_boundary_peak_bytes",
                "request_peak_bytes",
            ],
            "unavailable:Zend-memory-manager-disabled;numeric-values-are-not-valid-measurements",
        );
    }
    fn mark_unavailable(&mut self, fields: &[&'static str], reason: &'static str) {
        for field in fields {
            if !self
                .unavailable_metrics
                .iter()
                .any(|name| &**name == *field)
            {
                self.unavailable_metrics
                    .push((*field).into())
                    .expect("reserved unavailable-metric slots");
            }
            self.metric_provenance
                .insert((*field).into(), reason.into())
                .expect("reserved provenance slots");
        }
        self.unavailable_metrics.sort_unstable();
    }
}
const TIMING_FIELDS: &[&str] = &[
    "inclusive_wall_ns",
    "exclusive_wall_ns",
    "inclusive_cpu_ns",
    "exclusive_cpu_ns",
    "session.elapsed_wall_ns",
    "session.thread_cpu_ns",
    "timeline.start_ns",
    "timeline.end_ns",
    "timeline.active_wall_ns",
    "timeline.active_cpu_ns",
    "timeline.suspended_wall_ns",
    "hotspots",
];
#[derive(Default)]
struct Unavailable([Option<&'static str>; 32]);
impl Unavailable {
    fn insert(&mut self, value: &'static str) {
        if !self.contains(value) {
            *self
                .0
                .iter_mut()
                .find(|slot| slot.is_none())
                .expect("metric registry exceeds static slots") = Some(value);
        }
    }
    fn contains(&self, value: &str) -> bool {
        self.0.contains(&Some(value))
    }
    fn extend(&mut self, values: impl IntoIterator<Item = &'static str>) {
        for value in values {
            self.insert(value);
        }
    }
    #[cfg(test)]
    fn len(&self) -> usize {
        self.0.iter().flatten().count()
    }
}
impl<'a> IntoIterator for &'a Unavailable {
    type Item = &'a &'static str;
    type IntoIter = std::iter::Flatten<std::slice::Iter<'a, Option<&'static str>>>;
    fn into_iter(self) -> Self::IntoIter {
        self.0.iter().flatten()
    }
}
#[derive(Default)]
struct Arithmetic {
    overflows: u64,
    unavailable: Unavailable,
}
impl Arithmetic {
    fn overflow(&mut self, field: &'static str) {
        self.unavailable.insert(field);
        match self.overflows.checked_add(1) {
            Some(count) => self.overflows = count,
            None => {
                self.unavailable.insert("metric_overflows");
            }
        }
    }
    fn unsigned(&mut self, a: u64, b: u64, field: &'static str) -> u64 {
        match a.checked_add(b) {
            Some(value) => value,
            None => {
                self.overflow(field);
                u64::MAX
            }
        }
    }
    fn signed(&mut self, a: i64, b: i64, field: &'static str) -> i64 {
        match a.checked_add(b) {
            Some(value) => value,
            None => {
                self.overflow(field);
                a.saturating_add(b)
            }
        }
    }
    fn subtract(&mut self, a: u64, b: u64, field: &'static str) -> u64 {
        match a.checked_sub(b) {
            Some(value) => value,
            None => {
                self.overflow(field);
                0
            }
        }
    }
}
struct Frame {
    id: u64,
    symbol: usize,
    parent: Option<usize>,
    edge: usize,
    start: Snapshot,
    children_wall: u64,
    children_cpu: u64,
    segment: Option<Snapshot>,
    active_wall: u64,
    active_cpu: u64,
    active_memory: i64,
    category: Option<&'static str>,
    exception: bool,
}
/// Vacant slots carry the next free index instead of allocating a second pool.
enum FrameSlot {
    Vacant(Option<usize>),
    Occupied(Frame),
}
impl FrameSlot {
    fn as_ref(&self) -> Option<&Frame> {
        match self {
            Self::Occupied(frame) => Some(frame),
            Self::Vacant(_) => None,
        }
    }
    fn as_mut(&mut self) -> Option<&mut Frame> {
        match self {
            Self::Occupied(frame) => Some(frame),
            Self::Vacant(_) => None,
        }
    }
    fn take(&mut self) -> Option<Frame> {
        match std::mem::replace(self, Self::Vacant(None)) {
            Self::Occupied(frame) => Some(frame),
            Self::Vacant(next) => {
                *self = Self::Vacant(next);
                None
            }
        }
    }
}
/// Fixed-capacity capture records. A symbol is copied once, with no event-path allocation.
struct InlineName {
    bytes: [u8; 1024],
    len: usize,
}
impl InlineName {
    fn as_str(&self) -> &str {
        std::str::from_utf8(&self.bytes[..self.len]).expect("copied UTF-8 symbol")
    }
    fn new(name: &str) -> Self {
        let mut bytes = [0; 1024];
        bytes[..name.len()].copy_from_slice(name.as_bytes());
        Self {
            bytes,
            len: name.len(),
        }
    }
}
struct Symbol {
    name: InlineName,
    metrics: Metrics,
}
impl Symbol {
    fn name(&self) -> &str {
        self.name.as_str()
    }
    fn new(name: &str) -> Self {
        Self {
            name: InlineName::new(name),
            metrics: Metrics::default(),
        }
    }
}
struct CapturedEdge {
    caller: Option<usize>,
    callee: usize,
    metrics: Metrics,
}
struct Context {
    id: u64,
    head: Option<usize>,
    skipped: usize,
}
struct CapturedEvent {
    frame_id: u64,
    parent_frame_id: Option<u64>,
    symbol: usize,
    start_ns: u64,
    end_ns: u64,
    completed: bool,
    outcome: &'static str,
    context_id: u64,
    active_wall_ns: u64,
    active_cpu_ns: u64,
    suspended_wall_ns: u64,
}
/// One session with fallibly preallocated capture storage, shared across all contexts.
/// No instrumentation boundary allocates. Exhaustion preserves existing aggregates.
pub struct Engine {
    limit: usize,
    active_frames: usize,
    capture_closed: bool,
    dropped_timeline: u64,
    skipped_depth: usize,
    head: Option<usize>,
    frames: BudgetVec<FrameSlot>,
    free_head: Option<usize>,
    functions: BudgetVec<Symbol>,
    edges: BudgetVec<CapturedEdge>,
    function_index: Index,
    edge_index: Index,
    timeline: BudgetVec<CapturedEvent>,
    start: Option<Snapshot>,
    peak: u64,
    dropped: u64,
    unmatched: u64,
    incomplete: bool,
    clock_regressions: u64,
    context_id: u64,
    contexts: BudgetVec<Context>,
    timeline_enabled: bool,
    operations: [Metrics; 3],
    arithmetic: Arithmetic,
    budget: Budget,
    finalized: Option<(Snapshot, i64)>,
    sampling: Option<sampling::SamplingCapture>,
}
impl Engine {
    pub fn new(max_events: usize) -> Self {
        Self::with_timeline(max_events, false)
    }
    pub fn with_timeline(max_events: usize, timeline_enabled: bool) -> Self {
        Self::try_with_timeline(
            max_events,
            timeline_enabled,
            Budget::new(DEFAULT_BUDGET_BYTES),
        )
        .expect("capture preallocation failed; use try_with_timeline to handle allocation failure")
    }
    pub fn try_with_timeline(
        max_events: usize,
        timeline_enabled: bool,
        budget: Budget,
    ) -> Result<Self, BudgetError> {
        let limit = max_events.min(8192);
        let frames = BudgetVec::try_with_capacity(limit, &budget)?;
        let functions = BudgetVec::try_with_capacity(limit, &budget)?;
        let edges = BudgetVec::try_with_capacity(limit, &budget)?;
        let contexts = BudgetVec::try_with_capacity(limit, &budget)?;
        let timeline =
            BudgetVec::try_with_capacity(if timeline_enabled { limit } else { 0 }, &budget)?;
        let function_index = Index::try_new(limit, &budget)?;
        let edge_index = Index::try_new(limit, &budget)?;
        let mut engine = Self {
            limit,
            active_frames: 0,
            capture_closed: false,
            dropped_timeline: 0,
            skipped_depth: 0,
            head: None,
            frames,
            free_head: (limit > 0).then_some(0),
            functions,
            edges,
            function_index,
            edge_index,
            timeline,
            start: None,
            peak: 0,
            dropped: 0,
            unmatched: 0,
            incomplete: false,
            clock_regressions: 0,
            context_id: 0,
            contexts,
            timeline_enabled,
            operations: std::array::from_fn(|_| Metrics::default()),
            arithmetic: Arithmetic::default(),
            budget,
            finalized: None,
            sampling: None,
        };
        for index in 0..limit {
            engine
                .frames
                .push(FrameSlot::Vacant((index + 1 < limit).then_some(index + 1)))
                .expect("frame pool reserved for exactly limit slots");
        }
        Ok(engine)
    }
    /// Opt-in bounded wall-safepoint observations, independent of graph storage.
    pub fn try_with_sampling(
        max_events: usize,
        timeline_enabled: bool,
        budget: Budget,
        interval_ns: std::num::NonZeroU64,
    ) -> Result<Self, BudgetError> {
        let mut engine = Self::try_with_timeline(max_events, timeline_enabled, budget)?;
        engine.sampling = Some(sampling::SamplingCapture::try_new(
            engine.limit,
            interval_ns,
            engine.budget.clone(),
        )?);
        Ok(engine)
    }
    /// Record one actual stack for a batch of timer wakes; input is leaf to root.
    pub fn record_sample<S: AsRef<str>>(
        &mut self,
        observation: SampleObservation,
        frames: impl IntoIterator<Item = S>,
    ) {
        if self.finalized.is_none() {
            if let Some(sampling) = &mut self.sampling {
                sampling.record(observation, frames);
            }
        }
    }
    /// A sampler transport failure never invalidates the instrumentation graph.
    pub fn mark_sampling_unavailable(&mut self, reason: SamplingTermination) {
        if let Some(sampling) = &mut self.sampling {
            sampling.unavailable(reason);
        }
    }
    /// First backend stop facts win, including across report-allocation retries.
    pub fn finish_sampling(&mut self, finish: SamplingFinish) {
        if let Some(sampling) = &mut self.sampling {
            sampling.finish(finish);
        }
    }
    /// Finalized captures remain available for report-allocation retries, but
    /// must no longer keep the adapter's observer collection gate enabled.
    pub fn is_recording(&self) -> bool {
        self.finalized.is_none()
    }
    pub fn budget(&self) -> Budget {
        self.budget.clone()
    }
    pub fn capture_allocation_bytes(&self) -> usize {
        self.frames.allocated_bytes()
            + self.functions.allocated_bytes()
            + self.edges.allocated_bytes()
            + self.contexts.allocated_bytes()
            + self.timeline.allocated_bytes()
            + self.function_index.allocated_bytes()
            + self.edge_index.allocated_bytes()
            + self
                .sampling
                .as_ref()
                .map_or(0, sampling::SamplingCapture::allocated_bytes)
    }
    pub fn start(&mut self, wall_ns: u64, cpu_ns: u64, memory_bytes: u64) {
        if self.start.is_none() {
            self.start = Some(Snapshot {
                wall: wall_ns,
                cpu: cpu_ns,
                memory: memory_bytes,
            });
            self.peak = memory_bytes;
            if let Some(sampling) = &mut self.sampling {
                sampling.start(wall_ns);
            }
        }
    }
    pub fn enter(
        &mut self,
        frame_id: u64,
        symbol: &str,
        wall_ns: u64,
        cpu_ns: u64,
        memory_bytes: u64,
    ) {
        self.enter_kind(frame_id, symbol, wall_ns, cpu_ns, memory_bytes, None);
    }
    fn enter_kind(
        &mut self,
        frame_id: u64,
        symbol: &str,
        wall_ns: u64,
        cpu_ns: u64,
        memory_bytes: u64,
        category: Option<&'static str>,
    ) {
        if self.finalized.is_some() {
            return;
        }
        self.start(wall_ns, cpu_ns, memory_bytes);
        self.peak = self.peak.max(memory_bytes);
        if self.capture_closed
            || self.skipped_depth > 0
            || self.active_frames >= self.limit
            || symbol.len() > 1024
        {
            self.skip_entry();
            return;
        }
        let caller = self
            .head
            .map(|index| self.frames[index].as_ref().unwrap().symbol);
        // Tiny graphs retain the lower-cost linear path. Every insertion also
        // populates the indexes, so crossing the threshold needs no rebuilding.
        let existing = if use_linear_lookup(self.functions.len()) {
            self.functions
                .iter()
                .position(|entry| entry.name() == symbol)
        } else {
            self.function_index.find(fingerprint(symbol), |record| {
                self.functions[record].name() == symbol
            })
        };
        let symbol_index = existing.unwrap_or(self.functions.len());
        let edge_key = (caller, symbol_index);
        let edge = if use_linear_lookup(self.edges.len()) {
            self.edges
                .iter()
                .position(|edge| (edge.caller, edge.callee) == edge_key)
        } else {
            self.edge_index.find(fingerprint(&edge_key), |record| {
                let edge = &self.edges[record];
                (edge.caller, edge.callee) == edge_key
            })
        };
        if (existing.is_none() && self.functions.len() >= self.limit)
            || (edge.is_none() && self.edges.len() >= self.limit)
        {
            self.skip_entry();
            return;
        }
        if existing.is_none() {
            self.functions
                .push(Symbol::new(symbol))
                .expect("symbol capacity checked");
            assert!(
                self.function_index
                    .insert(fingerprint(symbol), symbol_index),
                "admitted symbols occupy at most half the index slots"
            );
        }
        let edge_index = edge.unwrap_or_else(|| {
            self.edges
                .push(CapturedEdge {
                    caller,
                    callee: symbol_index,
                    metrics: Metrics::default(),
                })
                .expect("edge capacity checked");
            let record = self.edges.len() - 1;
            assert!(
                self.edge_index.insert(fingerprint(&edge_key), record),
                "admitted edges occupy at most half the index slots"
            );
            record
        });
        let index = self.free_head.expect("active-frame capacity checked");
        let FrameSlot::Vacant(next) = self.frames[index] else {
            unreachable!("free chain only contains vacant slots");
        };
        self.free_head = next;
        self.frames[index] = FrameSlot::Occupied(Frame {
            id: frame_id,
            symbol: symbol_index,
            parent: self.head,
            edge: edge_index,
            start: Snapshot {
                wall: wall_ns,
                cpu: cpu_ns,
                memory: memory_bytes,
            },
            children_wall: 0,
            children_cpu: 0,
            segment: Some(Snapshot {
                wall: wall_ns,
                cpu: cpu_ns,
                memory: memory_bytes,
            }),
            active_wall: 0,
            active_cpu: 0,
            active_memory: 0,
            category,
            exception: false,
        });
        self.head = Some(index);
        self.active_frames += 1;
    }
    fn skip_entry(&mut self) {
        self.skipped_depth = self.skipped_depth.saturating_add(1);
        self.dropped = self.arithmetic.unsigned(self.dropped, 1, "dropped_calls");
    }
    pub fn exit(&mut self, frame_id: u64, wall_ns: u64, cpu_ns: u64, memory_bytes: u64) {
        if self.finalized.is_some() {
            return;
        }
        self.peak = self.peak.max(memory_bytes);
        if self.skipped_depth > 0 {
            self.skipped_depth -= 1;
            return;
        }
        let mut cursor = self.head;
        let mut found = None;
        while let Some(index) = cursor {
            let frame = self.frames[index].as_ref().unwrap();
            if frame.id == frame_id {
                found = Some(index);
                break;
            }
            cursor = frame.parent;
        }
        let Some(position) = found else {
            self.unmatched = self
                .arithmetic
                .unsigned(self.unmatched, 1, "unmatched_exits");
            self.incomplete = true;
            return;
        };
        let end = Snapshot {
            wall: wall_ns,
            cpu: cpu_ns,
            memory: memory_bytes,
        };
        while self.head != Some(position) {
            self.close(end, false);
        }
        self.close(end, true);
    }
    /// Record a frame leaving while Zend has a pending exception, without payload capture.
    pub fn exit_exception(&mut self, frame_id: u64, wall_ns: u64, cpu_ns: u64, memory_bytes: u64) {
        if self.skipped_depth == 0 {
            let mut cursor = self.head;
            while let Some(index) = cursor {
                let frame = self.frames[index].as_mut().unwrap();
                if frame.id == frame_id {
                    frame.exception = true;
                    break;
                }
                cursor = frame.parent;
            }
        }
        self.exit(frame_id, wall_ns, cpu_ns, memory_bytes);
    }
    fn close(&mut self, end: Snapshot, completed: bool) {
        let Some(index) = self.head else {
            return;
        };
        let frame = self.frames[index].take().unwrap();
        self.frames[index] = FrameSlot::Vacant(self.free_head);
        self.free_head = Some(index);
        self.head = frame.parent;
        self.active_frames -= 1;
        self.incomplete |= !completed;
        let mut frame = frame;
        self.accrue(&mut frame, end);
        let wall = frame.active_wall;
        let cpu = frame.active_cpu;
        let metrics = Metrics {
            calls: 1,
            exceptions: u64::from(frame.exception),
            inclusive_wall_ns: wall,
            exclusive_wall_ns: self.arithmetic.subtract(
                wall,
                frame.children_wall,
                "exclusive_wall_ns",
            ),
            inclusive_cpu_ns: cpu,
            exclusive_cpu_ns: self
                .arithmetic
                .subtract(cpu, frame.children_cpu, "exclusive_cpu_ns"),
            memory_delta_bytes: frame.active_memory,
        };
        if let Some(category) = frame.category {
            let index = match category {
                "sql" => 0,
                "http" => 1,
                _ => 2,
            };
            add(&mut self.operations[index], &metrics, &mut self.arithmetic);
        }
        add(
            &mut self.functions[frame.symbol].metrics,
            &metrics,
            &mut self.arithmetic,
        );
        let parent_frame_id = self
            .head
            .map(|index| self.frames[index].as_ref().unwrap().id);
        if let Some(index) = self.head {
            let parent = self.frames[index].as_mut().unwrap();
            parent.children_wall =
                self.arithmetic
                    .unsigned(parent.children_wall, wall, "exclusive_wall_ns");
            parent.children_cpu =
                self.arithmetic
                    .unsigned(parent.children_cpu, cpu, "exclusive_cpu_ns");
        }
        add(
            &mut self.edges[frame.edge].metrics,
            &metrics,
            &mut self.arithmetic,
        );
        if self.timeline_enabled {
            if self.timeline.len() >= self.limit {
                self.dropped_timeline =
                    self.arithmetic
                        .unsigned(self.dropped_timeline, 1, "dropped_timeline_events");
                return;
            }
            self.timeline
                .push(CapturedEvent {
                    frame_id: frame.id,
                    parent_frame_id,
                    symbol: frame.symbol,
                    start_ns: frame.start.wall,
                    end_ns: end.wall,
                    completed,
                    outcome: if !completed {
                        "incomplete"
                    } else if frame.exception {
                        "exception"
                    } else {
                        "return"
                    },
                    context_id: self.context_id,
                    active_wall_ns: wall,
                    active_cpu_ns: cpu,
                    suspended_wall_ns: self.arithmetic.subtract(
                        end.wall.saturating_sub(frame.start.wall),
                        wall,
                        "timeline.suspended_wall_ns",
                    ),
                })
                .expect("timeline capacity checked");
        }
    }
    /// Instrument a verified internal PHP function, permitting conservative categorization.
    pub fn enter_internal(
        &mut self,
        frame_id: u64,
        symbol: &str,
        wall_ns: u64,
        cpu_ns: u64,
        memory_bytes: u64,
    ) {
        self.enter_kind(
            frame_id,
            symbol,
            wall_ns,
            cpu_ns,
            memory_bytes,
            classify(symbol),
        );
    }
    fn accrue(&mut self, frame: &mut Frame, end: Snapshot) {
        if let Some(segment) = frame.segment.take() {
            if end.wall < segment.wall || end.cpu < segment.cpu {
                self.clock_regressions =
                    self.arithmetic
                        .unsigned(self.clock_regressions, 1, "clock_regressions");
                self.incomplete = true;
            }
            frame.active_wall = self.arithmetic.unsigned(
                frame.active_wall,
                end.wall.saturating_sub(segment.wall),
                "inclusive_wall_ns",
            );
            frame.active_cpu = self.arithmetic.unsigned(
                frame.active_cpu,
                end.cpu.saturating_sub(segment.cpu),
                "inclusive_cpu_ns",
            );
            let memory = delta(end.memory, segment.memory, &mut self.arithmetic);
            frame.active_memory =
                self.arithmetic
                    .signed(frame.active_memory, memory, "memory_delta_bytes");
        }
    }
    /// Switch execution context. Suspended contexts retain independent call stacks.
    pub fn switch_context(
        &mut self,
        context_id: u64,
        wall_ns: u64,
        cpu_ns: u64,
        memory_bytes: u64,
    ) {
        if self.finalized.is_some() {
            return;
        }
        if context_id == self.context_id {
            return;
        }
        let snapshot = Snapshot {
            wall: wall_ns,
            cpu: cpu_ns,
            memory: memory_bytes,
        };
        self.peak = self.peak.max(memory_bytes);
        let mut cursor = self.head;
        while let Some(index) = cursor {
            let mut frame = self.frames[index].take().unwrap();
            cursor = frame.parent;
            self.accrue(&mut frame, snapshot);
            self.frames[index] = FrameSlot::Occupied(frame);
        }
        let incoming = self
            .contexts
            .iter()
            .position(|context| context.id == context_id)
            .map(|index| self.contexts.swap_remove(index));
        if self.head.is_some() || (self.skipped_depth > 0 && !self.capture_closed) {
            if self.contexts.len() >= self.limit {
                self.capture_closed = true;
                if self.head.is_some() {
                    let rejected = self
                        .contexts
                        .iter()
                        .position(|context| context.head.is_none())
                        .expect("active-frame limit leaves a reusable context slot");
                    self.contexts.swap_remove(rejected);
                }
            }
            if self.contexts.len() < self.limit {
                self.contexts
                    .push(Context {
                        id: self.context_id,
                        head: self.head,
                        skipped: self.skipped_depth,
                    })
                    .expect("context capacity checked");
            }
        }
        self.head = incoming.as_ref().and_then(|context| context.head);
        self.skipped_depth = incoming.map_or(0, |context| context.skipped);
        self.context_id = context_id;
        let mut cursor = self.head;
        while let Some(index) = cursor {
            let frame = self.frames[index].as_mut().unwrap();
            frame.segment = Some(snapshot);
            cursor = frame.parent;
        }
    }
    /// Close destroyed fibers without attributing suspended time.
    pub fn destroy_context(
        &mut self,
        context_id: u64,
        wall_ns: u64,
        cpu_ns: u64,
        memory_bytes: u64,
    ) {
        let end = Snapshot {
            wall: wall_ns,
            cpu: cpu_ns,
            memory: memory_bytes,
        };
        if context_id == self.context_id {
            while self.head.is_some() {
                self.close(end, false);
            }
            self.skipped_depth = 0;
            return;
        }
        if let Some(position) = self
            .contexts
            .iter()
            .position(|context| context.id == context_id)
        {
            let context = self.contexts.swap_remove(position);
            let active = std::mem::replace(&mut self.head, context.head);
            let active_id = std::mem::replace(&mut self.context_id, context_id);
            while self.head.is_some() {
                self.close(end, false);
            }
            self.head = active;
            self.context_id = active_id;
        }
    }
    pub fn finish(mut self, wall_ns: u64, cpu_ns: u64, memory_bytes: u64) -> Report {
        self.try_finish(wall_ns, cpu_ns, memory_bytes)
            .expect("report allocation failed; native callers must use try_finish")
    }
    /// On allocation failure, the finalized captured measurements remain owned by
    /// this engine and can be materialized again after other storage is released.
    pub fn try_finish(
        &mut self,
        wall_ns: u64,
        cpu_ns: u64,
        memory_bytes: u64,
    ) -> Result<Report, BudgetError> {
        if self.finalized.is_none() {
            self.start(wall_ns, cpu_ns, memory_bytes);
            let end = Snapshot {
                wall: wall_ns,
                cpu: cpu_ns,
                memory: memory_bytes,
            };
            while self.head.is_some() {
                self.close(end, false);
            }
            while let Some(context) = self.contexts.pop() {
                self.context_id = context.id;
                self.head = context.head;
                self.skipped_depth = context.skipped;
                while self.head.is_some() {
                    self.close(end, false);
                }
            }
            let start = self.start.unwrap_or_default();
            if wall_ns < start.wall || cpu_ns < start.cpu {
                self.clock_regressions =
                    self.arithmetic
                        .unsigned(self.clock_regressions, 1, "clock_regressions");
                self.incomplete = true;
            }
            let memory_delta = delta(memory_bytes, start.memory, &mut self.arithmetic);
            if self.arithmetic.unavailable.contains("inclusive_wall_ns") {
                self.arithmetic.unavailable.extend([
                    "exclusive_wall_ns",
                    "timeline.active_wall_ns",
                    "timeline.suspended_wall_ns",
                ]);
            }
            if self.arithmetic.unavailable.contains("inclusive_cpu_ns") {
                self.arithmetic
                    .unavailable
                    .extend(["exclusive_cpu_ns", "timeline.active_cpu_ns"]);
            }
            if self.arithmetic.unavailable.contains("memory_delta_bytes") {
                self.arithmetic
                    .unavailable
                    .insert("session.memory_delta_bytes");
            }
            if self.arithmetic.unavailable.contains("exclusive_wall_ns") {
                self.arithmetic.unavailable.insert("hotspots");
            }
            self.capture_closed = true;
            if let Some(sampling) = &mut self.sampling {
                sampling.seal(wall_ns);
            }
            self.finalized = Some((end, memory_delta));
        }
        let (end, memory_delta) = self.finalized.expect("finalized measurements");
        let start = self.start.unwrap_or_default();
        let mut functions = BudgetMap::try_with_capacity(self.functions.len(), &self.budget)?;
        for entry in &self.functions {
            functions
                .insert(
                    BudgetText::try_copy(entry.name(), &self.budget)?,
                    entry.metrics.clone(),
                )
                .expect("function-map capacity reserved from captured symbol count");
        }
        let mut ranked = [usize::MAX; 20];
        for (index, entry) in self.functions.iter().enumerate() {
            if let Some(position) = ranked.iter().position(|other| {
                *other == usize::MAX || {
                    let other = &self.functions[*other];
                    other
                        .metrics
                        .exclusive_wall_ns
                        .cmp(&entry.metrics.exclusive_wall_ns)
                        .then_with(|| entry.name().cmp(other.name()))
                        .is_lt()
                }
            }) {
                ranked.copy_within(position..19, position + 1);
                ranked[position] = index;
            }
        }
        let mut hotspots =
            BudgetVec::try_with_capacity(self.functions.len().min(20), &self.budget)?;
        if !self.arithmetic.unavailable.contains("hotspots") {
            for index in ranked.into_iter().take_while(|index| *index != usize::MAX) {
                let entry = &self.functions[index];
                hotspots
                    .push(Hotspot {
                        symbol: BudgetText::try_copy(entry.name(), &self.budget)?,
                        metrics: entry.metrics.clone(),
                    })
                    .expect("hotspot capacity reserved for top twenty");
            }
        }
        let mut metric_provenance = provenance(&self.budget)?;
        let mut unavailable_metrics = BudgetVec::try_with_capacity(32, &self.budget)?;
        for field in &self.arithmetic.unavailable {
            unavailable_metrics
                .push((*field).into())
                .expect("inline registry has at most thirty-two fields");
            metric_provenance.insert((*field).into(),"unavailable:arithmetic-overflow-or-underflow;numeric-values-are-capped-placeholders".into()).expect("reserved provenance covers the static metric vocabulary");
        }
        unavailable_metrics.sort_unstable();
        let mut edges = BudgetVec::try_with_capacity(self.edges.len(), &self.budget)?;
        for edge in &self.edges {
            edges
                .push(Edge {
                    caller: BudgetText::try_copy(
                        edge.caller
                            .map_or("{session}", |index| self.functions[index].name()),
                        &self.budget,
                    )?,
                    callee: BudgetText::try_copy(self.functions[edge.callee].name(), &self.budget)?,
                    metrics: edge.metrics.clone(),
                })
                .expect("edge capacity reserved from captured edge count");
        }
        edges.sort_unstable_by(|a, b| {
            a.caller
                .cmp(&b.caller)
                .then_with(|| a.callee.cmp(&b.callee))
        });
        let mut timeline = BudgetVec::try_with_capacity(self.timeline.len(), &self.budget)?;
        for event in &self.timeline {
            timeline
                .push(Event {
                    frame_id: event.frame_id,
                    parent_frame_id: event.parent_frame_id,
                    symbol: BudgetText::try_copy(
                        self.functions[event.symbol].name(),
                        &self.budget,
                    )?,
                    start_ns: event.start_ns,
                    end_ns: event.end_ns,
                    completed: event.completed,
                    outcome: event.outcome.into(),
                    context_id: event.context_id,
                    active_wall_ns: event.active_wall_ns,
                    active_cpu_ns: event.active_cpu_ns,
                    suspended_wall_ns: event.suspended_wall_ns,
                })
                .expect("timeline capacity reserved from captured event count");
        }
        let mut operations = BudgetMap::try_with_capacity(3, &self.budget)?;
        for (name, metrics) in ["sql", "http", "io"].into_iter().zip(&self.operations) {
            if metrics.calls > 0 {
                operations
                    .insert(name.into(), metrics.clone())
                    .expect("three operation names fit three reserved slots");
            }
        }
        let mut limitations = BudgetVec::try_with_capacity(5, &self.budget)?;
        for limitation in ["Memory deltas are heap observations, not allocation counts; boundary peak may miss transient allocations.",
            "CPU includes instrumentation overhead; wall clock is monotonic elapsed time.",
            if self.sampling.is_some() { "Wall-safepoint samples are biased by VM service and delayed by long internal calls; coalesced wakes do not create extra stacks or CPU estimates. Independent CPU sampling and exact allocation counts are unavailable. Generator callbacks count resume segments; operation categories measure overlapping enclosing client latency." } else { "Statistical sampling and exact allocation counts are unavailable. Generator observer callbacks count resume segments rather than logical generator lifetimes. Operation categories measure enclosing client-call active latency, not remote server time; nested category durations can overlap." }] {
            limitations.push(limitation.into()).expect("three core limitations fit five reserved slots");
        }
        let mut report = Report {
            schema_version: if self.sampling.is_some() { 2 } else { 1 },
            termination_reason: "stopped".into(),
            runtime_metadata: BudgetMap::try_with_capacity(32, &self.budget)?,
            status: if self.arithmetic.overflows > 0 {
                "incomplete"
            } else if self.dropped > 0 || self.dropped_timeline > 0 {
                "truncated"
            } else if self.incomplete {
                "incomplete"
            } else {
                "complete"
            }
            .into(),
            elapsed_wall_ns: end.wall.saturating_sub(start.wall),
            thread_cpu_ns: end.cpu.saturating_sub(start.cpu),
            memory_delta_bytes: memory_delta,
            observed_boundary_peak_bytes: self.peak.max(end.memory),
            request_peak_bytes: None,
            dropped_calls: self.dropped,
            dropped_timeline_events: self.dropped_timeline,
            unmatched_exits: self.unmatched,
            clock_regressions: self.clock_regressions,
            metric_overflows: self.arithmetic.overflows,
            unavailable_metrics,
            functions,
            hotspots,
            edges,
            timeline,
            metric_provenance,
            capabilities: capabilities(&self.budget, self.sampling.is_some())?,
            operations,
            timeline_enabled: self.timeline_enabled,
            sampling: self
                .sampling
                .as_ref()
                .map(sampling::SamplingCapture::report)
                .transpose()?,
            limitations,
            budget: self.budget.clone(),
        };
        if report.clock_regressions > 0 {
            report.mark_clock_failure();
        }
        Ok(report)
    }
}
/// Shared lookup policy: preserve the measured tiny-graph fast path for both
/// dictionaries instead of duplicating independently drifting thresholds.
#[inline]
fn use_linear_lookup(records: usize) -> bool {
    records <= 8
}
fn classify(symbol: &str) -> Option<&'static str> {
    match symbol {
        "PDO::query"
        | "PDO::exec"
        | "PDOStatement::execute"
        | "mysqli_query"
        | "mysqli::query"
        | "mysqli_stmt_execute"
        | "mysqli_stmt::execute" => Some("sql"),
        "curl_exec" | "curl_multi_exec" => Some("http"),
        "file_get_contents"
        | "readfile"
        | "fopen"
        | "fread"
        | "fwrite"
        | "stream_socket_client" => Some("io"),
        _ => None,
    }
}
fn provenance(budget: &Budget) -> Result<BudgetMap<BudgetText>, BudgetError> {
    let mut map = BudgetMap::try_with_capacity(32, budget)?;
    for (a,b) in [
        ("metric_overflows","measured:detected-metric-arithmetic-range-errors;unit=count;capped-only-if-counter-is-itself-unavailable"),
        ("hotspots","derived:top-20-function-aggregates-ranked-by-exclusive-wall-descending-then-symbol-ascending;no-new-measurement"),
        ("request_peak_bytes","measured:zend-memory-peak-counter-snapshot;unit=bytes;scope=current-PHP-request-heap-peak-window;application-resettable;not-session-peak"),
        ("calls","measured:observer-entry-count;unit=count;scope=function-or-edge;generators-count-resume-segments"),
        ("exceptions","measured:observer-exit-with-pending-zend-exception;unit=count;scope=function-or-edge"),
        ("timeline.suspended_wall_ns","derived:elapsed-minus-active;unit=ns;scope=invocation-context"),
        ("session.memory_delta_bytes","derived:stop-minus-start-heap-snapshots;unit=bytes;scope=zend-request-heap;not-allocation-volume"),
        ("session.elapsed_wall_ns","measured:monotonic-stop-minus-start;unit=ns;scope=session-elapsed-including-suspension"),
        ("session.thread_cpu_ns","measured:thread-cpu-stop-minus-start;unit=ns;scope=thread-during-session"),
        ("inclusive_wall_ns", "measured:active-monotonic-time;unit=ns;scope=function-or-edge-active-context"),
        ("exclusive_wall_ns", "derived:inclusive-minus-children;unit=ns;scope=function-or-edge-active-context"),
        ("inclusive_cpu_ns", "measured:active-thread-cpu;unit=ns;scope=function-or-edge-active-context"),
        ("exclusive_cpu_ns", "derived:inclusive-minus-children;unit=ns;scope=function-or-edge-active-context"),
        (
            "memory_delta_bytes",
            "derived:active-segment-heap-differences;unit=bytes;scope=zend-request-heap-observations-during-function",
        ),
        (
            "observed_boundary_peak_bytes",
            "measured:maximum-observed-zend-heap-snapshot;unit=bytes;scope=absolute-request-heap-observed-during-session;not-session-peak",
        ),
    ]
    {map.insert(a.into(),b.into()).expect("static provenance fits reserved metric slots");}
    Ok(map)
}
fn capabilities(budget: &Budget, sampled: bool) -> Result<BudgetMap<bool>, BudgetError> {
    let mut map = BudgetMap::try_with_capacity(6 + usize::from(sampled), budget)?;
    for (a, b) in [
        ("fiber_contexts", true),
        ("logical_generator_calls", false),
        ("exact_allocation_counts", false),
        ("statistical_sampling", false),
        ("boundary_memory", true),
        ("internal_operation_latency", true),
    ] {
        map.insert(a.into(), b)
            .expect("six capability names fit six reserved slots");
    }
    if sampled {
        map.insert("wall_safepoint_sampling".into(), true)
            .expect("optional capability slot reserved");
    }
    Ok(map)
}
fn delta(end: u64, start: u64, arithmetic: &mut Arithmetic) -> i64 {
    let difference = i128::from(end) - i128::from(start);
    match i64::try_from(difference) {
        Ok(value) => value,
        Err(_) => {
            arithmetic.overflow("memory_delta_bytes");
            difference.clamp(i64::MIN as i128, i64::MAX as i128) as i64
        }
    }
}
fn add(target: &mut Metrics, value: &Metrics, arithmetic: &mut Arithmetic) {
    target.calls = arithmetic.unsigned(target.calls, value.calls, "calls");
    target.exceptions = arithmetic.unsigned(target.exceptions, value.exceptions, "exceptions");
    target.inclusive_wall_ns = arithmetic.unsigned(
        target.inclusive_wall_ns,
        value.inclusive_wall_ns,
        "inclusive_wall_ns",
    );
    target.exclusive_wall_ns = arithmetic.unsigned(
        target.exclusive_wall_ns,
        value.exclusive_wall_ns,
        "exclusive_wall_ns",
    );
    target.inclusive_cpu_ns = arithmetic.unsigned(
        target.inclusive_cpu_ns,
        value.inclusive_cpu_ns,
        "inclusive_cpu_ns",
    );
    target.exclusive_cpu_ns = arithmetic.unsigned(
        target.exclusive_cpu_ns,
        value.exclusive_cpu_ns,
        "exclusive_cpu_ns",
    );
    target.memory_delta_bytes = arithmetic.signed(
        target.memory_delta_bytes,
        value.memory_delta_bytes,
        "memory_delta_bytes",
    );
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn indexed_lookup_preserves_early_records_after_crossing_the_small_graph_threshold() {
        assert!(use_linear_lookup(0));
        assert!(use_linear_lookup(8));
        assert!(!use_linear_lookup(9));
        assert!(!use_linear_lookup(512));
        let mut engine = Engine::new(16);
        let names: Vec<_> = (0..10).map(|id| format!("function_{id:02}")).collect();
        for (id, name) in names.iter().enumerate() {
            engine.enter(id as u64, name, 0, 0, 0);
            engine.exit(id as u64, 1, 1, 0);
        }
        for (id, name) in names.iter().enumerate().rev() {
            engine.enter(100 + id as u64, name, 2, 2, 0);
            engine.exit(100 + id as u64, 3, 3, 0);
        }
        let report = engine.finish(4, 4, 0);
        assert_eq!(report.status, "complete");
        assert_eq!(report.functions.len(), 10);
        assert_eq!(report.edges.len(), 10);
        for metrics in report.functions.values() {
            assert_eq!(metrics.calls, 2);
            assert_eq!(metrics.exclusive_wall_ns, 2);
        }
        assert!(report.edges.iter().all(|edge| edge.metrics.calls == 2));
    }
    #[test]
    fn edge_index_crosses_its_threshold_independently_of_the_symbol_dictionary() {
        let mut engine = Engine::new(64);
        let names = ["a", "b", "c", "d"];
        let mut id = 0;
        for round in 0..2 {
            for parent in names {
                for child in names {
                    let wall = (round * 16 + id) * 10;
                    engine.enter(id, parent, wall, wall, 0);
                    engine.enter(id + 1, child, wall + 1, wall + 1, 0);
                    engine.exit(id + 1, wall + 2, wall + 2, 0);
                    engine.exit(id, wall + 3, wall + 3, 0);
                    id += 2;
                }
            }
        }
        let report = engine.finish(1000, 1000, 0);
        assert_eq!(report.status, "complete");
        assert_eq!(report.functions.len(), 4);
        assert_eq!(report.edges.len(), 20);
        for metrics in report.functions.values() {
            assert_eq!(metrics.calls, 16);
            assert_eq!(metrics.inclusive_wall_ns, 32);
            assert_eq!(metrics.exclusive_wall_ns, 24);
        }
        for edge in &report.edges {
            assert_eq!(
                edge.metrics.calls,
                if edge.caller == "{session}" { 8 } else { 2 }
            );
        }
    }
    #[test]
    fn every_partial_report_quota_refusal_releases_output_and_preserves_capture() {
        let budget = Budget::try_new(64 * 1024).unwrap();
        let mut engine = Engine::try_with_timeline(4, true, budget.clone()).unwrap();
        engine.enter(1, "root", 0, 0, 0);
        engine.enter(2, "child", 1, 1, 1);
        engine.exit(2, 2, 2, 2);
        engine.exit(1, 4, 4, 4);
        let capture = budget.used();
        let available = budget.available();
        let mut refusals = 0;
        let mut success = false;
        // Vary only the admitted destination quota, without duplicating private
        // record layouts or predicting which construction step needs the bytes.
        for allowance in 0..=available {
            let blocker = budget.try_reserve(available - allowance).unwrap();
            match engine.try_finish(6, 6, 6) {
                Err(error) => {
                    assert_eq!(error, BudgetError::Limit);
                    refusals += 1;
                }
                Ok(report) => {
                    assert_eq!(report.status, "complete");
                    assert_eq!(report.functions["root"].calls, 1);
                    assert_eq!(report.functions["root"].inclusive_wall_ns, 4);
                    assert_eq!(report.functions["root"].exclusive_wall_ns, 3);
                    assert_eq!(report.functions["child"].inclusive_wall_ns, 1);
                    assert_eq!(report.timeline.len(), 2);
                    assert_eq!(report.elapsed_wall_ns, 6);
                    success = true;
                }
            }
            assert_eq!(budget.used(), capture + blocker.bytes());
            drop(blocker);
            assert_eq!(budget.used(), capture);
            if success {
                break;
            }
        }
        assert!(refusals > 0);
        assert!(success);
    }
    #[test]
    fn complete_static_diagnostic_vocabulary_fits_reserved_report_slots() {
        let mut engine = Engine::new(0);
        for field in [
            "calls",
            "exceptions",
            "inclusive_wall_ns",
            "exclusive_wall_ns",
            "inclusive_cpu_ns",
            "exclusive_cpu_ns",
            "memory_delta_bytes",
            "dropped_calls",
            "dropped_timeline_events",
            "unmatched_exits",
            "clock_regressions",
            "metric_overflows",
            "timeline.active_wall_ns",
            "timeline.active_cpu_ns",
            "timeline.suspended_wall_ns",
            "session.memory_delta_bytes",
            "hotspots",
        ] {
            engine.arithmetic.overflow(field);
        }
        let mut report = engine.finish(0, 0, 0);
        report.mark_clock_failure();
        report.mark_memory_unavailable();
        assert_eq!(report.metric_provenance.len(), 23);
        assert!(report.unavailable_metrics.len() <= 32);
        assert_eq!(report.limitations.capacity(), 5);
        report
            .limitations
            .push("adapter observer failure".into())
            .unwrap();
        report
            .limitations
            .push("adapter unfinished shutdown".into())
            .unwrap();
        assert_eq!(
            report.limitations.push("no further slot".into()),
            Err(BudgetError::Limit)
        );
        assert!(report
            .unavailable_metrics
            .iter()
            .all(|field| report.metric_provenance[field].starts_with("unavailable:")));
    }
    #[test]
    fn completed_child_is_aggregated_before_its_parent_finishes() {
        let mut engine = Engine::new(4);
        engine.enter(1, "parent", 0, 0, 0);
        engine.enter(2, "child", 3, 1, 0);
        engine.exit(2, 6, 2, 0);
        let child = engine
            .functions
            .iter()
            .find(|entry| entry.name() == "child")
            .unwrap();
        assert_eq!(child.metrics.calls, 1);
        assert_eq!(child.metrics.inclusive_wall_ns, 3);
        assert_eq!(child.metrics.exclusive_wall_ns, 3);
        assert_eq!(engine.edges[1].metrics.calls, 1);
        assert_eq!(engine.active_frames, 1);
        assert_eq!(engine.functions[0].metrics.calls, 0);
        engine.exit(1, 9, 3, 0);
        assert_eq!(engine.functions[0].metrics.calls, 1);
        assert_eq!(engine.functions[0].metrics.exclusive_wall_ns, 6);
    }
    #[test]
    fn equal_duration_hotspots_keep_lexically_smaller_name_when_it_was_seen_first() {
        let mut engine = Engine::new(4);
        for (id, name) in [(1, "a"), (2, "z")] {
            engine.enter(id, name, 0, 0, 0);
            engine.exit(id, 2, 2, 0);
        }
        let report = engine.finish(3, 3, 0);
        assert_eq!(report.hotspots[0].symbol, "a");
        assert_eq!(report.hotspots[1].symbol, "z");
    }
    #[test]
    fn full_rejected_context_pool_cannot_discard_an_active_captured_chain() {
        let mut engine = Engine::new(1);
        let oversized = "x".repeat(1025);
        engine.enter(1, &oversized, 0, 0, 0);
        engine.switch_context(1, 1, 1, 0);
        assert_eq!(engine.contexts.len(), 1);
        engine.enter(2, "kept", 1, 1, 0);
        engine.switch_context(2, 2, 2, 0);
        assert_eq!(engine.contexts.len(), 1);
        assert_eq!(engine.contexts[0].id, 1);
        assert!(engine.capture_closed);
        engine.destroy_context(1, 3, 3, 0);
        assert_eq!(engine.active_frames, 0);
        let report = engine.finish(4, 4, 0);
        assert_eq!(report.functions["kept"].calls, 1);
        assert_eq!(report.functions["kept"].inclusive_wall_ns, 1);
        assert_eq!(report.dropped_calls, 1);
    }
    #[test]
    fn exception_parent_lookup_traverses_only_the_active_context_chain() {
        let mut engine = Engine::new(4);
        engine.enter(1, "parent", 0, 0, 0);
        engine.enter(2, "child", 1, 1, 0);
        engine.exit_exception(1, 2, 2, 0);
        engine.enter(3, "unrelated", 3, 3, 0);
        engine.exit_exception(99, 4, 4, 0);
        engine.exit(3, 5, 5, 0);
        let report = engine.finish(6, 6, 0);
        assert_eq!(report.functions["parent"].exceptions, 1);
        assert_eq!(report.functions["child"].exceptions, 0);
        assert_eq!(report.functions["unrelated"].exceptions, 0);
        assert_eq!(report.unmatched_exits, 1);
    }
    #[test]
    fn weaker_hotspots_do_not_replace_a_full_top_twenty() {
        let mut engine = Engine::new(32);
        assert_eq!(
            engine.budget().used(),
            Budget::control_bytes() + engine.capture_allocation_bytes()
        );
        for id in 0..25 {
            engine.enter(id, &format!("f{id:02}"), 0, 0, 0);
            engine.exit(id, 25 - id, 1, 0);
        }
        let report = engine.finish(30, 30, 0);
        assert_eq!(report.hotspots.len(), 20);
        assert_eq!(report.hotspots[0].symbol, "f00");
        assert_eq!(report.hotspots[19].symbol, "f19");
        assert!(report
            .hotspots
            .iter()
            .all(|entry| entry.metrics.exclusive_wall_ns >= 6));
    }
    #[test]
    fn report_budget_exhaustion_preserves_finalized_capture_and_retry_boundary() {
        let budget = Budget::try_new(1024 * 1024).unwrap();
        let control = Budget::control_bytes();
        let mut engine = Engine::try_with_timeline(4, true, budget.clone()).unwrap();
        assert!(engine.is_recording());
        engine.enter(1, "kept", 10, 20, 30);
        let capture_bytes = engine.capture_allocation_bytes();
        let blocker = budget.try_reserve(budget.available()).unwrap();
        assert!(matches!(
            engine.try_finish(40, 50, 60),
            Err(BudgetError::Limit)
        ));
        assert!(!engine.is_recording());
        assert_eq!(engine.functions[0].metrics.calls, 1);
        assert_eq!(engine.functions[0].metrics.inclusive_wall_ns, 30);
        assert_eq!(engine.active_frames, 0);
        engine.enter(99, "after_failed_stop", 100, 200, 300);
        engine.exit(99, 200, 300, 400);
        engine.switch_context(123, 400, 500, 600);
        assert_eq!(engine.context_id, 0);
        assert_eq!(engine.dropped, 0);
        assert_eq!(engine.unmatched, 0);
        drop(blocker);
        assert_eq!(budget.used(), control + capture_bytes);
        let report = engine.try_finish(400, 500, 600).unwrap();
        assert!(!engine.is_recording());
        assert_eq!(report.functions["kept"].calls, 1);
        assert_eq!(report.elapsed_wall_ns, 30);
        assert_eq!(report.thread_cpu_ns, 30);
        assert_eq!(report.memory_delta_bytes, 30);
        assert_eq!(report.timeline[0].end_ns, 40);
        let retained = budget.used() - capture_bytes;
        drop(engine);
        assert_eq!(budget.used(), retained);
        assert!(retained > control);
        drop(report);
        assert_eq!(budget.used(), control);
    }
    #[test]
    fn shared_capture_capacity_is_reserved_before_admission_and_released_on_failure() {
        let measured = Engine::new(8).capture_allocation_bytes();
        let control = Budget::control_bytes();
        let budget = Budget::try_new(control + measured).unwrap();
        let engine = Engine::try_with_timeline(8, false, budget.clone()).unwrap();
        assert_eq!(budget.used(), control + measured);
        assert!(matches!(
            Engine::try_with_timeline(1, false, budget.clone()),
            Err(BudgetError::Limit)
        ));
        assert_eq!(budget.used(), control + measured);
        drop(engine);
        assert_eq!(budget.used(), control);
        let too_small = Budget::try_new(control + measured - 1).unwrap();
        assert!(matches!(
            Engine::try_with_timeline(8, false, too_small.clone()),
            Err(BudgetError::Limit)
        ));
        assert_eq!(too_small.used(), control);
        let engines: Vec<_> = (0..16)
            .map(|_| Engine::try_with_timeline(0, true, budget.clone()).unwrap())
            .collect();
        assert_eq!(budget.used(), control);
        drop(engines);
        assert!(matches!(
            Engine::try_with_timeline(8, true, budget.clone()),
            Err(BudgetError::Limit)
        ));
        assert_eq!(budget.used(), control);
    }
    #[test]
    fn nested_recursion_has_exact_exclusive_metrics_and_edges() {
        let mut e = Engine::with_timeline(10, true);
        e.start(10, 20, 100);
        e.enter(1, "f", 12, 22, 110);
        e.enter(2, "f", 14, 24, 120);
        e.exit(2, 18, 27, 90);
        e.exit(1, 22, 30, 105);
        let r = e.finish(24, 32, 104);
        assert_eq!(r.status, "complete");
        assert_eq!(r.metric_overflows, 0);
        assert!(r.unavailable_metrics.is_empty());
        assert_eq!(r.elapsed_wall_ns, 14);
        assert_eq!(r.thread_cpu_ns, 12);
        assert_eq!(r.memory_delta_bytes, 4);
        assert_eq!(r.observed_boundary_peak_bytes, 120);
        assert_eq!(
            r.functions["f"],
            Metrics {
                calls: 2,
                exceptions: 0,
                inclusive_wall_ns: 14,
                exclusive_wall_ns: 10,
                inclusive_cpu_ns: 11,
                exclusive_cpu_ns: 8,
                memory_delta_bytes: -35
            }
        );
        assert_eq!(r.edges.len(), 2);
        assert_eq!(r.timeline[0].parent_frame_id, Some(1));
    }
    #[test]
    fn bounded_capture_keeps_parents_balanced() {
        let mut e = Engine::with_timeline(1, true);
        e.enter(1, "a", 0, 0, 10);
        e.enter(2, "b", 1, 1, 20);
        e.enter(3, "c", 2, 2, 30);
        e.exit(3, 3, 3, 20);
        e.exit(2, 4, 4, 10);
        e.exit(1, 5, 5, 10);
        let r = e.finish(6, 6, 10);
        assert_eq!(r.status, "truncated");
        assert_eq!(r.dropped_calls, 2);
        assert_eq!(r.timeline.len(), 1);
        assert_eq!(r.functions["a"].inclusive_wall_ns, 5);
    }
    #[test]
    fn oversized_symbols_are_explicitly_dropped() {
        let mut e = Engine::with_timeline(10, true);
        e.enter(1, &"x".repeat(1025), 1, 1, 1);
        e.exit(1, 2, 2, 2);
        let r = e.finish(3, 3, 3);
        assert_eq!(r.dropped_calls, 1);
        assert!(r.functions.is_empty());
    }
    #[test]
    fn unfinished_frames_and_mismatched_exits_are_reported() {
        let mut e = Engine::with_timeline(10, true);
        e.enter(1, "a", 1, 1, 1);
        e.enter(2, "b", 2, 2, 2);
        e.exit(99, 3, 3, 3);
        e.exit(1, 4, 4, 4);
        e.enter(3, "c", 5, 5, 5);
        let r = e.finish(6, 6, 6);
        assert_eq!(r.status, "incomplete");
        assert_eq!(r.unmatched_exits, 1);
        assert!(!r.timeline[0].completed);
        assert!(r.timeline[1].completed);
        assert!(!r.timeline[2].completed);
    }
    #[test]
    fn empty_session_and_delta_extremes() {
        let r = Engine::new(0).finish(10, 10, 10);
        assert_eq!(r.elapsed_wall_ns, 0);
        assert_eq!(r.status, "complete");
        assert_eq!(delta(0, u64::MAX, &mut Arithmetic::default()), i64::MIN);
        assert_eq!(delta(u64::MAX, 0, &mut Arithmetic::default()), i64::MAX);
    }
    #[test]
    fn backwards_clocks_do_not_underflow() {
        let mut e = Engine::with_timeline(1, true);
        e.enter(1, "a", 10, 10, 10);
        e.exit(1, 1, 1, 1);
        let r = e.finish(0, 0, 0);
        assert_eq!(r.functions["a"].inclusive_wall_ns, 0);
        assert_eq!(r.clock_regressions, 2);
        assert!(r.hotspots.is_empty());
        assert!(r
            .unavailable_metrics
            .iter()
            .any(|name| name == "session.elapsed_wall_ns"));
        assert!(r.metric_provenance["inclusive_wall_ns"].starts_with("unavailable:"));
        assert_eq!(r.status, "incomplete");
    }
    #[test]
    fn close_on_empty_stack_is_safe() {
        let mut e = Engine::with_timeline(1, true);
        e.close(Snapshot::default(), false);
        assert!(e.finish(0, 0, 0).timeline.is_empty());
    }
    #[test]
    fn contexts_exclude_suspension_and_keep_separate_stacks() {
        let mut e = Engine::with_timeline(10, true);
        e.start(0, 0, 100);
        e.enter(1, "main", 0, 0, 100);
        e.switch_context(1, 5, 3, 110);
        e.enter(2, "fiber", 5, 3, 110);
        e.switch_context(0, 9, 5, 120);
        e.enter(3, "child", 10, 6, 121);
        e.exit(3, 12, 7, 122);
        e.switch_context(1, 15, 9, 130);
        e.exit(2, 20, 13, 125);
        e.switch_context(0, 20, 13, 125);
        e.exit(1, 25, 15, 124);
        let r = e.finish(25, 15, 124);
        assert_eq!(r.status, "complete");
        assert_eq!(r.functions["main"].inclusive_wall_ns, 16);
        assert_eq!(r.functions["main"].exclusive_wall_ns, 14);
        assert_eq!(r.functions["main"].inclusive_cpu_ns, 9);
        assert_eq!(r.functions["fiber"].inclusive_wall_ns, 9);
        assert_eq!(r.functions["fiber"].inclusive_cpu_ns, 6);
        let fiber = r.timeline.iter().find(|v| v.symbol == "fiber").unwrap();
        assert_eq!(fiber.context_id, 1);
        assert_eq!(fiber.suspended_wall_ns, 6);
        assert_eq!(fiber.active_wall_ns, 9);
        assert_eq!(fiber.active_cpu_ns, 6);
        assert_eq!(fiber.parent_frame_id, None);
        let main = r.timeline.iter().find(|v| v.symbol == "main").unwrap();
        assert_eq!(main.suspended_wall_ns, 9);
    }
    #[test]
    fn suspended_unfinished_contexts_are_incomplete_without_charging_wait() {
        let mut e = Engine::with_timeline(10, true);
        e.enter(1, "a", 0, 0, 0);
        e.switch_context(1, 5, 3, 10);
        e.enter(2, "b", 5, 3, 10);
        e.switch_context(2, 8, 5, 20);
        let r = e.finish(100, 20, 100);
        assert_eq!(r.status, "incomplete");
        assert_eq!(r.functions["a"].inclusive_wall_ns, 5);
        assert_eq!(r.functions["b"].inclusive_wall_ns, 3);
        assert_eq!(r.timeline.len(), 2);
    }
    #[test]
    fn same_context_switch_is_noop_and_empty_contexts_do_not_accumulate() {
        let mut e = Engine::new(1);
        e.switch_context(0, 1, 1, 1);
        for id in 1..1000 {
            e.switch_context(id, id, id, id);
        }
        assert!(e.contexts.is_empty());
        e.enter(1, "a", 1001, 1001, 1001);
        e.exit(1, 1002, 1002, 1002);
        let r = e.finish(1003, 1003, 1003);
        assert!(!r.timeline_enabled);
        assert!(r.timeline.is_empty());
    }
    #[test]
    fn verified_internal_calls_only_receive_operation_classification() {
        let mut e = Engine::new(10);
        for (i, symbol) in ["PDO::query", "curl_exec", "fread", "unknown"]
            .into_iter()
            .enumerate()
        {
            e.enter_internal(i as u64, symbol, 0, 0, 0);
            e.exit(i as u64, 10, 5, 1);
        }
        e.enter(5, "curl_exec", 0, 0, 0);
        e.exit(5, 50, 20, 1);
        let r = e.finish(100, 100, 1);
        assert_eq!(r.operations.len(), 3);
        for category in ["sql", "http", "io"] {
            assert_eq!(r.operations[category].calls, 1);
            assert_eq!(r.operations[category].inclusive_wall_ns, 10);
        }
        assert!(!r.capabilities["exact_allocation_counts"]);
        assert!(r.capabilities["fiber_contexts"]);
        assert!(r.metric_provenance["exclusive_wall_ns"].starts_with("derived:"));
    }
    #[test]
    fn symbol_boundary_and_skipped_subtrees_preserve_other_calls() {
        let mut e = Engine::new(10);
        let max = "a".repeat(1024);
        e.enter(1, &max, 0, 0, 0);
        e.exit(1, 1, 1, 1);
        e.enter(2, &"b".repeat(1025), 2, 2, 2);
        e.enter(3, "must_skip", 3, 3, 3);
        e.exit(3, 4, 4, 4);
        e.exit(2, 5, 5, 5);
        e.enter(4, "after", 6, 6, 6);
        e.exit(4, 7, 7, 7);
        let r = e.finish(8, 8, 8);
        assert_eq!(r.functions.len(), 2);
        assert_eq!(r.functions[&max].calls, 1);
        assert_eq!(r.functions["after"].calls, 1);
        assert_eq!(r.dropped_calls, 2);
        assert_eq!(r.unmatched_exits, 0);
    }
    #[test]
    fn clocks_validate_independent_regressions_and_allow_zero_duration() {
        for (wall, cpu) in [(0, 10), (10, 0)] {
            let mut e = Engine::new(1);
            e.start(10, 10, 0);
            e.enter(1, "a", 10, 10, 0);
            e.exit(1, wall, cpu, 0);
            let r = e.finish(10, 10, 0);
            assert_eq!(r.clock_regressions, 1);
            assert_eq!(r.status, "incomplete");
            let mut e = Engine::new(1);
            e.start(10, 10, 0);
            let r = e.finish(wall, cpu, 0);
            assert_eq!(r.clock_regressions, 1);
            assert_eq!(r.status, "incomplete");
        }
        let mut e = Engine::new(1);
        e.enter(1, "zero", 5, 5, 0);
        e.exit(1, 5, 5, 0);
        let r = e.finish(5, 5, 0);
        assert_eq!(r.clock_regressions, 0);
        assert_eq!(r.status, "complete");
    }
    #[test]
    fn rejected_root_context_retains_skipped_depth_when_resumed() {
        let mut e = Engine::new(10);
        e.enter(1, &"x".repeat(1025), 0, 0, 0);
        e.switch_context(1, 1, 1, 1);
        e.enter(2, "b", 1, 1, 1);
        e.exit(2, 2, 2, 2);
        e.switch_context(0, 3, 3, 3);
        e.enter(3, "skipped", 3, 3, 3);
        e.exit(3, 4, 4, 4);
        e.exit(1, 5, 5, 5);
        let r = e.finish(6, 6, 6);
        assert_eq!(r.dropped_calls, 2);
        assert_eq!(r.functions.len(), 1);
        assert_eq!(r.functions["b"].calls, 1);
        assert_eq!(r.unmatched_exits, 0);
    }
    #[test]
    fn internal_rejected_call_cannot_reclassify_parent() {
        let mut e = Engine::new(1);
        e.enter(1, "main", 0, 0, 0);
        e.enter_internal(2, "curl_exec", 1, 1, 1);
        e.exit(2, 2, 2, 2);
        e.exit(1, 3, 3, 3);
        assert!(e.finish(4, 4, 4).operations.is_empty());
    }
    #[test]
    fn exception_outcomes_are_payload_free_and_counted() {
        let mut e = Engine::with_timeline(10, true);
        e.enter(1, "a", 0, 0, 0);
        e.enter(2, "b", 1, 1, 1);
        e.exit_exception(2, 2, 2, 2);
        e.exit(1, 3, 3, 3);
        e.exit_exception(99, 4, 4, 4);
        let r = e.finish(5, 5, 5);
        assert_eq!(r.functions["b"].exceptions, 1);
        assert_eq!(r.functions["a"].exceptions, 0);
        assert_eq!(r.timeline[0].outcome, "exception");
        assert_eq!(r.timeline[1].outcome, "return");
        assert_eq!(r.unmatched_exits, 1);
        let mut e = Engine::new(0);
        e.enter(1, "dropped", 0, 0, 0);
        e.exit_exception(1, 1, 1, 1);
        assert_eq!(e.finish(2, 2, 2).dropped_calls, 1);
    }
    #[test]
    fn destroyed_fibers_finalize_without_charging_suspension_or_touching_active_stack() {
        let mut e = Engine::with_timeline(10, true);
        e.enter(1, "a", 0, 0, 0);
        e.switch_context(1, 5, 3, 5);
        e.enter(2, "b", 5, 3, 5);
        e.destroy_context(0, 100, 10, 20);
        e.exit(2, 110, 20, 30);
        e.destroy_context(99, 110, 20, 30);
        let r = e.finish(120, 21, 40);
        assert_eq!(r.functions["a"].inclusive_wall_ns, 5);
        assert_eq!(r.functions["b"].inclusive_wall_ns, 105);
        assert_eq!(r.status, "incomplete");
        assert_eq!(r.timeline[0].outcome, "incomplete");
        let mut e = Engine::new(1);
        e.enter(1, "a", 0, 0, 0);
        e.destroy_context(0, 2, 1, 1);
        assert_eq!(e.finish(5, 3, 2).functions["a"].inclusive_wall_ns, 2);
    }
    #[test]
    fn unmatched_exit_alone_marks_report_incomplete() {
        let mut e = Engine::new(1);
        e.start(0, 0, 0);
        e.exit(7, 1, 1, 1);
        let r = e.finish(2, 2, 2);
        assert_eq!(r.unmatched_exits, 1);
        assert_eq!(r.status, "incomplete");
    }
    #[test]
    fn repeated_aggregates_do_not_exhaust_storage_capacity() {
        let mut e = Engine::new(1);
        for id in 0..5000 {
            e.enter(id, "f", id * 2, id, 10);
            e.exit(id, id * 2 + 1, id + 1, 11);
        }
        let r = e.finish(10_000, 5001, 11);
        assert_eq!(r.status, "complete");
        assert_eq!(r.functions["f"].calls, 5000);
        assert_eq!(r.edges.len(), 1);
        assert_eq!(r.dropped_calls, 0);
        assert_eq!(r.dropped_timeline_events, 0);
    }
    #[test]
    fn timeline_exhaustion_preserves_complete_aggregate_counts() {
        let mut e = Engine::with_timeline(2, true);
        for id in 0..100 {
            e.enter(id, "f", id * 2, id, 0);
            e.exit(id, id * 2 + 1, id + 1, 0);
        }
        let r = e.finish(200, 101, 0);
        assert_eq!(r.status, "truncated");
        assert_eq!(r.functions["f"].calls, 100);
        assert_eq!(r.timeline.len(), 2);
        assert_eq!(r.dropped_timeline_events, 98);
        assert_eq!(r.dropped_calls, 0);
    }
    #[test]
    fn distinct_symbol_capacity_drops_new_symbols_but_retains_existing_ones() {
        let mut e = Engine::new(2);
        for (id, symbol) in ["a", "b", "c", "a"].into_iter().enumerate() {
            e.enter(id as u64, symbol, 0, 0, 0);
            e.exit(id as u64, 1, 1, 1);
        }
        let r = e.finish(2, 2, 2);
        assert_eq!(r.functions.len(), 2);
        assert_eq!(r.functions["a"].calls, 2);
        assert_eq!(r.functions["b"].calls, 1);
        assert_eq!(r.dropped_calls, 1);
    }
    #[test]
    fn distinct_edge_capacity_is_independent_of_symbol_capacity() {
        let mut e = Engine::new(2);
        e.enter(1, "a", 0, 0, 0);
        e.enter(2, "b", 1, 1, 1);
        e.exit(2, 2, 2, 2);
        e.exit(1, 3, 3, 3);
        e.enter(3, "b", 4, 4, 4);
        e.exit(3, 5, 5, 5);
        e.enter(4, "a", 6, 6, 6);
        e.exit(4, 7, 7, 7);
        let r = e.finish(8, 8, 8);
        assert_eq!(r.functions.len(), 2);
        assert_eq!(r.edges.len(), 2);
        assert_eq!(r.functions["a"].calls, 2);
        assert_eq!(r.functions["b"].calls, 1);
        assert_eq!(r.dropped_calls, 1);
    }
    #[test]
    fn active_frame_budget_is_shared_across_fiber_contexts() {
        let mut e = Engine::new(2);
        e.enter(1, "f", 0, 0, 0);
        e.switch_context(1, 1, 1, 1);
        e.enter(2, "f", 1, 1, 1);
        e.switch_context(2, 2, 2, 2);
        e.enter(3, "f", 2, 2, 2);
        e.exit(3, 3, 3, 3);
        e.switch_context(1, 4, 4, 4);
        e.exit(2, 5, 5, 5);
        e.switch_context(0, 6, 6, 6);
        e.exit(1, 7, 7, 7);
        let r = e.finish(8, 8, 8);
        assert_eq!(r.functions["f"].calls, 2);
        assert_eq!(r.dropped_calls, 1);
        assert_eq!(r.unmatched_exits, 0);
    }
    #[test]
    fn excessive_suspended_rejected_contexts_close_capture_without_unbounded_storage() {
        let mut e = Engine::new(1);
        let oversized = "x".repeat(1025);
        for context in 0..10 {
            e.enter(context, &oversized, context, context, 0);
            e.switch_context(context + 1, context + 1, context + 1, 0);
        }
        assert!(e.contexts.len() <= 1);
        e.enter(100, "must_not_resume_collection", 11, 11, 0);
        e.exit(100, 12, 12, 0);
        let r = e.finish(13, 13, 0);
        assert!(r.functions.is_empty());
        assert_eq!(r.dropped_calls, 11);
        assert_eq!(r.status, "truncated");
    }
    fn assert_frame_pool_partition(engine: &Engine) {
        let mut free = vec![false; engine.frames.len()];
        let mut cursor = engine.free_head;
        let mut count = 0;
        while let Some(index) = cursor {
            assert!(!free[index], "free chain contains a cycle");
            free[index] = true;
            count += 1;
            let FrameSlot::Vacant(next) = engine.frames[index] else {
                panic!("free chain contains a live frame");
            };
            cursor = next;
        }
        for (index, slot) in engine.frames.iter().enumerate() {
            assert_eq!(slot.as_ref().is_none(), free[index]);
        }
        assert_eq!(count + engine.active_frames, engine.frames.len());
    }
    #[test]
    fn intrusive_frame_slots_preserve_vacancy_and_existing_pool_layout() {
        let mut vacant = FrameSlot::Vacant(Some(7));
        assert!(vacant.as_ref().is_none());
        assert!(vacant.as_mut().is_none());
        assert!(vacant.take().is_none());
        assert!(matches!(vacant, FrameSlot::Vacant(Some(7))));
        assert_eq!(
            std::mem::size_of::<FrameSlot>(),
            std::mem::size_of::<Option<Frame>>()
        );
        println!(
            "frame slots remain {} bytes; engine {} bytes",
            std::mem::size_of::<FrameSlot>(),
            std::mem::size_of::<Engine>()
        );
        let empty = Engine::new(0);
        assert_frame_pool_partition(&empty);
        let mut engine = Engine::new(1);
        engine.enter(1, "leaf", 0, 0, 0);
        assert_frame_pool_partition(&engine);
        engine.exit(1, 1, 1, 0);
        assert_frame_pool_partition(&engine);
        engine.enter(2, "leaf", 2, 2, 0);
        engine.exit(2, 3, 3, 0);
        assert_frame_pool_partition(&engine);
        assert_eq!(engine.finish(4, 4, 0).functions["leaf"].calls, 2);
    }
    #[test]
    #[should_panic(expected = "free chain only contains vacant slots")]
    fn corrupted_frame_free_chain_fails_before_overwriting_a_live_frame() {
        let mut engine = Engine::new(2);
        engine.enter(1, "leaf", 0, 0, 0);
        engine.free_head = Some(0);
        assert!(std::panic::catch_unwind(|| assert_frame_pool_partition(&engine)).is_err());
        engine.enter(2, "leaf", 1, 1, 0);
    }
    #[test]
    fn frame_holes_reuse_across_fibers_mismatched_exits_and_destroyed_contexts() {
        let mut engine = Engine::new(8);
        engine.enter(1, "main", 0, 0, 0);
        engine.switch_context(1, 1, 1, 0);
        engine.enter(2, "worker", 2, 2, 0);
        engine.enter(3, "child", 3, 3, 0);
        engine.switch_context(0, 4, 4, 0);
        engine.enter(4, "child", 5, 5, 0);
        engine.exit(4, 6, 6, 0);
        assert_frame_pool_partition(&engine);
        engine.switch_context(1, 7, 7, 0);
        engine.exit(2, 8, 8, 0);
        assert_frame_pool_partition(&engine);
        engine.switch_context(2, 9, 9, 0);
        engine.enter(5, "other", 10, 10, 0);
        engine.destroy_context(0, 11, 11, 0);
        assert_frame_pool_partition(&engine);
        engine.enter(6, "child", 12, 12, 0);
        engine.exit_exception(5, 13, 13, 0);
        assert_frame_pool_partition(&engine);
        assert_eq!(engine.active_frames, 0);
        let report = engine.finish(14, 14, 0);
        assert_eq!(report.functions["main"].calls, 1);
        assert_eq!(report.functions["worker"].calls, 1);
        assert_eq!(report.functions["child"].calls, 3);
        assert_eq!(report.functions["other"].exceptions, 1);
        assert_eq!(report.unmatched_exits, 0);
        assert_eq!(report.status, "incomplete");
    }
    #[test]
    fn maximum_frame_depth_recovers_every_slot_after_skips_and_partial_unwind() {
        let mut engine = Engine::new(8192);
        let mut clock = 0;
        for id in 1..=8192 {
            engine.enter(id, "recursive", clock, clock, 0);
            clock += 1;
        }
        assert_frame_pool_partition(&engine);
        assert!(engine.free_head.is_none());
        engine.enter(8193, "recursive", clock, clock, 0);
        clock += 1;
        engine.exit(8193, clock, clock, 0);
        clock += 1;
        assert_eq!(engine.active_frames, 8192);
        engine.exit_exception(4096, clock, clock, 0);
        clock += 1;
        assert_frame_pool_partition(&engine);
        assert_eq!(engine.active_frames, 4095);
        for id in 9000..=13096 {
            engine.enter(id, "recursive", clock, clock, 0);
            clock += 1;
        }
        assert_eq!(engine.active_frames, 8192);
        assert_frame_pool_partition(&engine);
        for id in (9000..=13096).rev().chain((1..=4095).rev()) {
            engine.exit(id, clock, clock, 0);
            clock += 1;
        }
        assert_frame_pool_partition(&engine);
        assert_eq!(engine.active_frames, 0);
        let report = engine.finish(clock, clock, 0);
        assert_eq!(report.functions["recursive"].calls, 12289);
        assert_eq!(report.functions["recursive"].exceptions, 1);
        assert_eq!(report.dropped_calls, 1);
        assert_eq!(report.unmatched_exits, 0);
        assert_eq!(report.status, "truncated");
    }
    #[test]
    fn suspended_stack_capacity_does_not_retain_historical_depth() {
        let mut e = Engine::new(32);
        for context in 0..8 {
            e.switch_context(context, context * 100, context * 100, 0);
            for depth in 0..16 {
                e.enter(
                    context * 100 + depth,
                    "recursive",
                    context * 100 + depth,
                    context * 100 + depth,
                    0,
                );
            }
            for depth in (1..16).rev() {
                e.exit(
                    context * 100 + depth,
                    context * 100 + 20,
                    context * 100 + 20,
                    0,
                );
            }
        }
        e.switch_context(99, 900, 900, 0);
        assert_eq!(e.frames.len(), 32);
        assert_eq!(
            e.frames.iter().filter_map(FrameSlot::as_ref).count(),
            e.active_frames
        );
        assert_eq!(e.active_frames, 8);
        let r = e.finish(1000, 1000, 0);
        assert_eq!(r.functions["recursive"].calls, 128);
        assert_eq!(r.dropped_calls, 0);
    }
    #[test]
    fn switching_preserves_existing_buffers_within_the_capacity_budget() {
        let mut e = Engine::new(10);
        for id in 0..5 {
            e.enter(id, "f", id, id, 0);
        }
        e.exit(4, 5, 5, 0);
        let bytes = e.capture_allocation_bytes();
        e.switch_context(1, 6, 6, 0);
        // Contexts reference the fixed frame pool, with no per-context allocation.
        assert_eq!(e.capture_allocation_bytes(), bytes);
        assert_eq!(e.frames.iter().filter_map(FrameSlot::as_ref).count(), 4);
        let r = e.finish(7, 7, 0);
        assert_eq!(r.functions["f"].calls, 5);
    }
    #[test]
    fn switching_to_a_known_rejected_context_reuses_its_storage_slot() {
        let mut e = Engine::new(1);
        let symbol = "x".repeat(1025);
        e.enter(1, &symbol, 0, 0, 0);
        e.switch_context(1, 1, 1, 0);
        e.enter(2, &symbol, 1, 1, 0);
        e.switch_context(0, 2, 2, 0);
        e.exit(1, 3, 3, 0);
        e.enter(3, "recovered", 4, 4, 0);
        e.exit(3, 5, 5, 0);
        let r = e.finish(6, 6, 0);
        assert_eq!(r.functions["recovered"].calls, 1);
        assert_eq!(r.dropped_calls, 2);
    }
    #[test]
    fn rejected_empty_contexts_release_historical_stack_buffers() {
        let mut e = Engine::new(32);
        let oversized = "x".repeat(1025);
        for context in 0..8 {
            for depth in 0..16 {
                e.enter(
                    context * 100 + depth,
                    "recursive",
                    context * 100 + depth,
                    context * 100 + depth,
                    0,
                );
            }
            for depth in (0..16).rev() {
                e.exit(
                    context * 100 + depth,
                    context * 100 + 20,
                    context * 100 + 20,
                    0,
                );
            }
            e.enter(
                context * 100 + 99,
                &oversized,
                context * 100 + 21,
                context * 100 + 21,
                0,
            );
            e.switch_context(context + 1, context * 100 + 22, context * 100 + 22, 0);
        }
        assert_eq!(e.frames.iter().filter_map(FrameSlot::as_ref).count(), 0);
        assert_eq!(e.frames.len(), 32);
        let r = e.finish(1000, 1000, 0);
        assert_eq!(r.functions["recursive"].calls, 128);
        assert_eq!(r.dropped_calls, 8);
    }
    #[test]
    fn request_peak_is_unavailable_without_a_native_measurement() {
        let mut report = Engine::new(0).finish(0, 0, 0);
        assert_eq!(report.request_peak_bytes, None);
        let value: serde_json::Value = serde_json::from_str(&report.json().unwrap()).unwrap();
        assert!(value["request_peak_bytes"].is_null());
        report.request_peak_bytes = Some(1_048_576);
        let value: serde_json::Value = serde_json::from_str(&report.json().unwrap()).unwrap();
        assert_eq!(value["request_peak_bytes"], 1_048_576);
        assert!(report.metric_provenance["request_peak_bytes"].contains("application-resettable"));
    }
    #[test]
    fn hotspots_rank_exclusive_wall_time_and_resolve_ties_by_symbol() {
        let mut e = Engine::new(10);
        for (id, (name, duration)) in [("z", 2), ("a", 2), ("b", 5)].into_iter().enumerate() {
            e.enter(id as u64, name, 0, 0, 0);
            e.exit(id as u64, duration, 1, 0);
        }
        let report = e.finish(10, 10, 0);
        let names: Vec<_> = report
            .hotspots
            .iter()
            .map(|entry| entry.symbol.as_str())
            .collect();
        assert_eq!(names, ["b", "a", "z"]);
        assert_eq!(report.hotspots[0].metrics, report.functions["b"]);
        assert!(report.metric_provenance["hotspots"].starts_with("derived:"));
    }
    #[test]
    fn hotspot_ranking_retains_only_twenty_entries() {
        let mut e = Engine::new(32);
        for id in 0..25 {
            e.enter(id, &format!("f{id:02}"), 0, 0, 0);
            e.exit(id, id + 1, 1, 0);
        }
        let report = e.finish(30, 30, 0);
        assert_eq!(report.functions.len(), 25);
        assert_eq!(report.hotspots.len(), 20);
        assert_eq!(report.hotspots[0].symbol, "f24");
        assert_eq!(report.hotspots[19].symbol, "f05");
    }
    #[test]
    fn recursive_aggregate_overflow_is_incomplete_and_never_claimed_measured() {
        let mut e = Engine::with_timeline(10, true);
        e.start(0, 0, 0);
        e.enter(1, "f", 0, 0, 0);
        e.enter(2, "f", 0, 0, 0);
        e.exit(2, u64::MAX, u64::MAX, 0);
        e.exit(1, u64::MAX, u64::MAX, 0);
        let report = e.finish(u64::MAX, u64::MAX, 0);
        assert_eq!(report.metric_overflows, 2);
        assert_eq!(report.status, "incomplete");
        assert_eq!(report.functions["f"].inclusive_wall_ns, u64::MAX);
        for name in [
            "inclusive_wall_ns",
            "exclusive_wall_ns",
            "inclusive_cpu_ns",
            "exclusive_cpu_ns",
            "hotspots",
        ] {
            assert!(report.unavailable_metrics.iter().any(|field| field == name));
            assert!(report.metric_provenance[name].starts_with("unavailable:"));
        }
        assert!(report.hotspots.is_empty());
    }
    #[test]
    fn segment_accumulation_and_signed_memory_totals_report_range_errors() {
        let mut e = Engine::new(10);
        e.enter(1, "f", 0, 0, 0);
        e.switch_context(1, u64::MAX, u64::MAX, 0);
        e.switch_context(0, 0, 0, 0);
        e.exit(1, u64::MAX, u64::MAX, 0);
        let report = e.finish(u64::MAX, u64::MAX, 0);
        assert!(report.metric_overflows >= 2);
        assert_eq!(report.status, "incomplete");
        for (start, end) in [(0, i64::MAX as u64), (1, 0)] {
            let mut e = Engine::new(10);
            e.enter_internal(1, "fread", 0, 0, start);
            e.exit(1, 1, 1, end);
            e.enter_internal(2, "fread", 2, 2, 0);
            e.exit(2, 3, 3, 1);
            let report = e.finish(4, 4, 0);
            if start == 0 {
                assert!(report.metric_overflows >= 3);
                assert_eq!(report.functions["fread"].memory_delta_bytes, i64::MAX);
            } else {
                assert_eq!(report.metric_overflows, 0);
            }
        }
        let mut e = Engine::new(10);
        e.enter(1, "heap", 0, 0, 0);
        e.exit(1, 1, 1, u64::MAX);
        let report = e.finish(2, 2, u64::MAX);
        assert_eq!(report.metric_overflows, 2);
        assert!(report.metric_provenance["session.memory_delta_bytes"].starts_with("unavailable:"));
        assert_eq!(report.observed_boundary_peak_bytes, u64::MAX);
    }
    #[test]
    fn every_metric_accumulator_checks_overflow_and_counter_overflow_is_explicit() {
        let mut arithmetic = Arithmetic::default();
        let mut metrics = Metrics {
            calls: u64::MAX,
            exceptions: u64::MAX,
            inclusive_wall_ns: u64::MAX,
            exclusive_wall_ns: u64::MAX,
            inclusive_cpu_ns: u64::MAX,
            exclusive_cpu_ns: u64::MAX,
            memory_delta_bytes: i64::MAX,
        };
        let extra = Metrics {
            calls: 1,
            exceptions: 1,
            inclusive_wall_ns: 1,
            exclusive_wall_ns: 1,
            inclusive_cpu_ns: 1,
            exclusive_cpu_ns: 1,
            memory_delta_bytes: 1,
        };
        add(&mut metrics, &extra, &mut arithmetic);
        assert_eq!(arithmetic.overflows, 7);
        assert_eq!(arithmetic.unavailable.len(), 7);
        assert_eq!(
            arithmetic.signed(i64::MIN, -1, "memory_delta_bytes"),
            i64::MIN
        );
        assert_eq!(arithmetic.subtract(0, 1, "exclusive_wall_ns"), 0);
        arithmetic.overflows = u64::MAX;
        arithmetic.overflow("calls");
        assert_eq!(arithmetic.overflows, u64::MAX);
        assert!(arithmetic.unavailable.contains("metric_overflows"));
    }
    #[test]
    fn child_time_exceeding_parent_invalidates_exclusive_metrics() {
        let mut e = Engine::new(10);
        e.enter(1, "parent", 0, 0, 0);
        e.enter(2, "child", 10, 10, 0);
        e.exit(2, 20, 20, 0);
        e.exit(1, 5, 5, 0);
        let report = e.finish(30, 30, 0);
        assert_eq!(report.metric_overflows, 2);
        assert_eq!(report.status, "incomplete");
        assert!(report.metric_provenance["exclusive_wall_ns"].starts_with("unavailable:"));
    }
    #[test]
    fn native_clock_and_memory_unavailability_are_idempotent_and_explicit() {
        let mut engine = Engine::new(1);
        engine.enter(1, "healthy", 0, 0, 0);
        engine.exit(1, 1, 1, 1);
        let mut report = engine.finish(2, 2, 1);
        assert_eq!(report.hotspots.len(), 1);
        report.request_peak_bytes = Some(1024);
        report.mark_memory_unavailable();
        assert_eq!(report.request_peak_bytes, None);
        assert!(!report.capabilities["boundary_memory"]);
        assert_eq!(report.status, "complete");
        for name in [
            "memory_delta_bytes",
            "session.memory_delta_bytes",
            "observed_boundary_peak_bytes",
            "request_peak_bytes",
        ] {
            assert!(report.unavailable_metrics.iter().any(|field| field == name));
            assert!(report.metric_provenance[name].starts_with("unavailable:"));
        }
        report.mark_clock_failure();
        let fields: Vec<_> = report
            .unavailable_metrics
            .iter()
            .map(ToString::to_string)
            .collect();
        report.mark_clock_failure();
        report.mark_memory_unavailable();
        assert_eq!(
            report
                .unavailable_metrics
                .iter()
                .map(ToString::to_string)
                .collect::<Vec<_>>(),
            fields
        );
        assert_eq!(report.status, "incomplete");
        assert!(report.hotspots.is_empty());
        assert_eq!(report.metric_overflows, 0);
    }
}
