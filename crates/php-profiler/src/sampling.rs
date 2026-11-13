//! Optional wall-timer safepoint integration. All Zend borrows remain on PHP's thread.
use super::{
    Budget, BudgetError, BudgetText, GeneratedNames, Report, STATE, State, SymbolBuffer,
    profiler_context,
};
use profiler_core::{Engine, SampleObservation, SamplingFinish, SamplingTermination};
use std::{cell::Cell, fmt::Write};
#[cfg(all(profiler_adapter_contract_tests, not(test)))]
#[path = "../../../tests/support/sampling-adapter-contract.rs"]
mod contracts;
#[cfg(all(profiler_adapter_contract_tests, not(test)))]
pub fn register_contracts(module: super::ModuleBuilder) -> super::ModuleBuilder {
    contracts::register(module)
}

pub const PERIOD_NS: u64 = 10_000_000;
const LIMIT: usize = 64;
// Separate from instrumentation identities: sampling cannot exhaust its maps.
const GENERATED_LIMIT: usize = 512;
thread_local! { static CALLBACK_FAILED: Cell<bool> = const {Cell::new(false)}; }

#[repr(C)]
#[derive(Clone, Copy, Default)]
pub struct Tick {
    pub due_ns: u64,
    pub wake_ns: u64,
}
#[repr(C)]
#[derive(Clone, Copy, Default)]
pub struct Status {
    pub wakes: u64,
    pub dropped_ticks: u64,
    pub pending_ticks: u64,
    pub snapshot_ns: u64,
    pub worker_stack_bytes: u64,
    pub worker_guard_bytes: u64,
    pub control_bytes: u64,
    pub running: i32,
    pub termination: i32,
    pub error: i32,
    pub system_error: i32,
}
#[repr(C)]
struct Frame {
    name: *const u8,
    name_length: usize,
    scope: *const u8,
    scope_length: usize,
    closure_identity: u64,
    internal: i32,
}
#[repr(C)]
struct Observation {
    frames: *const Frame,
    frame_count: usize,
    ticks: *const Tick,
    tick_count: usize,
    service_ns: u64,
    status: Status,
    stack_truncated: i32,
}
const _: () = assert!(std::mem::size_of::<Tick>() == 16);
const _: () = assert!(std::mem::size_of::<Status>() == 72);
const _: () = assert!(std::mem::size_of::<Frame>() == 48);
const _: () = assert!(std::mem::size_of::<Observation>() == 120);
unsafe extern "C" {
    #[cfg_attr(
        all(profiler_adapter_contract_tests, not(test)),
        link_name = "profiler_contract_sampling_supported"
    )]
    fn profiler_sampling_supported() -> i32;
    pub fn profiler_sampling_register() -> i32;
    pub fn profiler_sampling_module_shutdown();
    pub fn profiler_sampling_request_init();
    pub fn profiler_sampling_request_shutdown();
    fn profiler_sampling_reset();
    fn profiler_sampling_registry_bytes() -> u64;
    fn profiler_sampling_cleanup_error(kind: *mut i32, system: *mut i32);
    fn profiler_sampling_start() -> i32;
    fn profiler_sampling_stop();
    #[cfg_attr(
        all(profiler_adapter_contract_tests, not(test)),
        link_name = "profiler_contract_sampling_status"
    )]
    fn profiler_sampling_status(status: *mut Status);
}
pub fn supported() -> bool {
    // SAFETY: this provider reads a platform capability, with no PHP calls.
    unsafe { profiler_sampling_supported() != 0 }
}
pub fn status() -> Status {
    let mut result = Status::default();
    // SAFETY: the native provider initializes exactly this caller-owned value.
    unsafe { profiler_sampling_status(&mut result) };
    result
}
pub fn start() -> Result<Status, &'static str> {
    // SAFETY: caller runs after RINIT on the PHP thread; the worker owns no Zend borrows.
    let result = match unsafe { profiler_sampling_start() } {
        1 | 2 => {
            let status = status();
            if status.error != 0 || status.snapshot_ns == 0 {
                Err("Wall safepoint sampler could not snapshot its start boundary")
            } else {
                Ok(status)
            }
        }
        3 => Err("Sampling cannot restart after fork in the same request"),
        _ => Err("Wall safepoint sampler could not start"),
    };
    if result.is_err() {
        // A failed boundary snapshot can follow successful thread creation.
        // Retire it before returning an API error; no application frame is lost.
        unsafe { profiler_sampling_stop() };
    }
    result
}
pub fn reset_failure() {
    CALLBACK_FAILED.set(false);
}
pub fn reset_backend() {
    // SAFETY: caller has no remaining sessions; fork termination stays sticky.
    unsafe { profiler_sampling_reset() };
}

pub struct Adapter {
    functions: GeneratedNames,
    classes: GeneratedNames,
}
impl Adapter {
    pub fn try_new(budget: &Budget) -> Result<Self, BudgetError> {
        let mut value = Self {
            functions: GeneratedNames::default(),
            classes: GeneratedNames::default(),
        };
        value
            .functions
            .initialize_with_capacity(GENERATED_LIMIT, budget)?;
        value
            .classes
            .initialize_with_capacity(GENERATED_LIMIT, budget)?;
        Ok(value)
    }
    pub fn clear(&mut self) {
        self.functions.clear();
        self.classes.clear();
    }
    /// C owns both strings for this synchronous callback. No pointer is retained.
    unsafe fn render(&mut self, frame: &Frame) -> Option<SymbolBuffer> {
        if frame
            .name_length
            .saturating_add(frame.scope_length)
            .saturating_add(if frame.scope_length > 0 { 2 } else { 0 })
            > 1024
        {
            return Some(SymbolBuffer::oversized());
        }
        if (frame.name.is_null() && frame.name_length != 0)
            || (frame.scope.is_null() && frame.scope_length != 0)
        {
            CALLBACK_FAILED.set(true);
            return Some(SymbolBuffer::oversized());
        }
        // SAFETY: bounds checked above; C guarantees live descriptor ranges.
        let name = if frame.name.is_null() {
            b"{main}".as_slice()
        } else {
            unsafe { std::slice::from_raw_parts(frame.name, frame.name_length) }
        };
        let scope = if frame.scope_length == 0 {
            &[]
        } else {
            unsafe { std::slice::from_raw_parts(frame.scope, frame.scope_length) }
        };
        if scope == b"PhpProfiler\\Profiler" || scope == b"PhpProfiler\\Session" {
            return None;
        }
        let mut result = SymbolBuffer::new();
        let mut render = || -> std::fmt::Result {
            if !scope.is_empty() {
                if scope.starts_with(b"class@anonymous") {
                    let ordinal = self.classes.identity(scope, 0).ok_or(std::fmt::Error)?;
                    write!(result, "sample-anonymous-class#{ordinal}")?;
                } else {
                    result.identifier(scope)?;
                }
                result.write_str("::")?;
            }
            if frame.closure_identity != 0 || name.starts_with(b"{closure") {
                let ordinal = self
                    .functions
                    .identity(name, frame.closure_identity)
                    .ok_or(std::fmt::Error)?;
                write!(result, "{{sample-closure#{ordinal}}}")?;
            } else {
                result.identifier(name)?;
            }
            Ok(())
        };
        if render().is_err() {
            Some(SymbolBuffer::oversized())
        } else {
            Some(result)
        }
    }
}

pub struct Tracker {
    start_ns: u64,
    initial_wakes: u64,
    initial_drops: u64,
    last_drops: u64,
    serviced: u64,
    finished: bool,
    final_status: Option<Status>,
    cleanup: Option<Cleanup>,
    process_start: u32,
    process_stop: Option<u32>,
}
#[derive(Clone, Copy)]
enum Cleanup {
    Deferred,
    Joined { kind: i32, system: i32 },
}
impl Tracker {
    pub fn new(start_ns: u64, baseline: Status) -> Self {
        Self {
            start_ns,
            initial_wakes: baseline.wakes,
            initial_drops: baseline.dropped_ticks,
            last_drops: baseline.dropped_ticks,
            serviced: 0,
            finished: false,
            final_status: None,
            cleanup: None,
            process_start: std::process::id(),
            process_stop: None,
        }
    }
    pub fn is_recording(&self) -> bool {
        !self.finished
    }
    pub fn finish(&mut self, engine: &mut Engine, native: Status) {
        if self.finished {
            return;
        }
        self.finished = true;
        self.process_stop = Some(std::process::id());
        self.final_status = Some(native);
        let deltas = native
            .wakes
            .checked_sub(self.initial_wakes)
            .and_then(|wakes| {
                let drops = native.dropped_ticks.checked_sub(self.initial_drops)?;
                let final_drops = native.dropped_ticks.checked_sub(self.last_drops)?;
                let unserved = wakes.checked_sub(drops)?.checked_sub(self.serviced)?;
                Some((final_drops, unserved))
            });
        if let Some((dropped_ticks, unserved_wakes)) = deltas {
            engine.finish_sampling(SamplingFinish {
                unserved_wakes,
                dropped_ticks,
                reason: reason(native),
                counter_snapshot_ns: (native.snapshot_ns != 0).then_some(native.snapshot_ns),
            });
        } else {
            engine.mark_sampling_unavailable(SamplingTermination::CounterFailure);
        }
    }
    pub fn decorate(&self, report: &mut Report) -> Result<(), BudgetError> {
        let native = self
            .final_status
            .expect("sampled report retains first stop status");
        for (key, value) in [
            (
                "sampling.worker_stack_requested_bytes",
                native.worker_stack_bytes,
            ),
            (
                "sampling.worker_guard_requested_bytes",
                native.worker_guard_bytes,
            ),
            ("sampling.control_payload_bytes", native.control_bytes),
            // SAFETY: fixed pointer-table layout; no clocks, allocator or PHP call.
            ("sampling.registry_pointer_table_bytes", unsafe {
                profiler_sampling_registry_bytes()
            }),
        ] {
            metadata_number(report, key, i128::from(value))?;
        }
        metadata_number(
            report,
            "sampling.backend_error_code",
            i128::from(native.error),
        )?;
        metadata_number(
            report,
            "sampling.backend_system_error_code",
            i128::from(native.system_error),
        )?;
        metadata_number(report, "sampling.pid_start", i128::from(self.process_start))?;
        metadata_number(
            report,
            "sampling.pid_stop",
            i128::from(
                self.process_stop
                    .expect("sampled stop captures its process identity"),
            ),
        )?;
        report.runtime_metadata.insert(
            "sampling.backend".into(),
            "linux_glibc_2_32_wall_timer_safepoint".into(),
        )?;
        report
            .runtime_metadata
            .insert("sampling.generated_identity_capacity".into(), "512".into())?;
        let deferred = match self.cleanup {
            Some(Cleanup::Deferred) => "true",
            Some(_) => "false",
            None => "unavailable",
        };
        report
            .runtime_metadata
            .insert("sampling.cleanup_deferred".into(), deferred.into())?;
        if let Some(Cleanup::Joined { kind, system }) = self.cleanup {
            for (key, value) in [
                ("sampling.cleanup_error_code", kind),
                ("sampling.cleanup_system_error_code", system),
            ] {
                metadata_number(report, key, i128::from(value))?;
            }
        }
        Ok(())
    }
    fn record(
        &mut self,
        engine: &mut Engine,
        native: &Observation,
        ticks: &[Tick],
        frames: &[Frame],
        context: u64,
        adapter: &mut Adapter,
    ) {
        if self.finished {
            return;
        }
        if native.status.error != 0 {
            engine.mark_sampling_unavailable(reason(native.status));
        }
        let Some(dropped_ticks) = native.status.dropped_ticks.checked_sub(self.last_drops) else {
            engine.mark_sampling_unavailable(SamplingTermination::CounterFailure);
            return;
        };
        self.last_drops = native.status.dropped_ticks;
        let mut accepted = ticks.iter().filter(|tick| tick.wake_ns >= self.start_ns);
        let first = accepted.next();
        let (due_ns, first_wake_ns, last_wake_ns, wake_count) = if let Some(first) = first {
            let mut last = first.wake_ns;
            let mut count = 1;
            for tick in accepted {
                last = tick.wake_ns;
                count += 1;
            }
            (first.due_ns, first.wake_ns, last, count)
        } else {
            (0, 0, 0, 0)
        };
        let Some(serviced) = self.serviced.checked_add(wake_count) else {
            engine.mark_sampling_unavailable(SamplingTermination::CounterFailure);
            return;
        };
        self.serviced = serviced;
        let observation = SampleObservation {
            due_ns,
            first_wake_ns,
            last_wake_ns,
            service_ns: native.service_ns,
            context_id: context,
            wake_count,
            dropped_ticks,
            depth_truncated: native.stack_truncated != 0,
        };
        // A lazy iterator keeps one bounded symbol buffer on the stack, rather
        // than an array of 64 full symbol buffers. It never changes call counts.
        engine.record_sample(
            observation,
            frames.iter().filter_map(|frame| {
                // SAFETY: frame strings are borrowed from the enclosing C callback.
                unsafe { adapter.render(frame) }
            }),
        );
    }
}
fn metadata_number(report: &mut Report, key: &'static str, value: i128) -> Result<(), BudgetError> {
    let mut text = SymbolBuffer::new();
    write!(text, "{value}").expect("i128 fits bounded metadata buffer");
    let value = BudgetText::try_copy(text.as_str(), &report.budget())?;
    report.runtime_metadata.insert(key.into(), value)?;
    Ok(())
}
fn reason(native: Status) -> SamplingTermination {
    if CALLBACK_FAILED.get() {
        SamplingTermination::CallbackFailure
    } else if native.termination == 2 {
        SamplingTermination::Fork
    } else {
        match native.error {
            0 => SamplingTermination::Stopped,
            2 => SamplingTermination::Capacity,
            3 | 4 => SamplingTermination::ClockFailure,
            10 => SamplingTermination::CounterFailure,
            9 => SamplingTermination::IdentityFailure,
            _ => SamplingTermination::ThreadFailure,
        }
    }
}
fn has_subscribers(state: &State) -> bool {
    state.sessions.iter().any(|session| {
        session.sampling.as_ref().is_some_and(Tracker::is_recording)
            && session.engine.as_ref().is_some_and(Engine::is_recording)
    })
}
pub fn clear_if_idle(state: &mut State) {
    if !has_subscribers(state)
        && let Some(adapter) = &mut state.sampling
    {
        adapter.clear();
    }
}
pub fn synchronize(state: &State) {
    if state.sampling.is_some() && !has_subscribers(state) {
        // SAFETY: C joins the one request-thread worker before returning.
        unsafe { profiler_sampling_stop() };
    }
}
/// Observe cleanup only after the measured boundary has been frozen.
pub fn prepare_reports(state: &mut State) {
    if state.sampling.is_none() {
        return;
    }
    let deferred = has_subscribers(state);
    synchronize(state);
    let cleanup = if deferred {
        Cleanup::Deferred
    } else {
        let mut kind = 0;
        let mut system = 0;
        // SAFETY: initialized outputs; this C getter performs no clock or PHP calls.
        unsafe { profiler_sampling_cleanup_error(&mut kind, &mut system) };
        Cleanup::Joined { kind, system }
    };
    for session in &mut state.sessions {
        if let Some(tracker) = &mut session.sampling
            && tracker.finished
        {
            tracker.cleanup.get_or_insert(cleanup);
        }
    }
}
fn guard(callback: &mut dyn FnMut()) {
    let result = std::panic::catch_unwind(std::panic::AssertUnwindSafe(callback));
    if result.is_err() {
        CALLBACK_FAILED.set(true);
    }
    if CALLBACK_FAILED.get() {
        // All callback borrows and local owners have dropped before C cleanup.
        unsafe { profiler_sampling_stop() };
    }
}
#[unsafe(no_mangle)]
/// # Safety
/// C must supply one live observation and borrowed descriptor arrays for this call.
unsafe extern "C" fn profiler_sampling_observe(pointer: *const Observation) {
    guard(&mut || {
        if pointer.is_null() {
            CALLBACK_FAILED.set(true);
            return;
        }
        // SAFETY: native caller supplies this synchronous initialized descriptor.
        let native = unsafe { &*pointer };
        if native.frame_count > LIMIT
            || native.tick_count > LIMIT
            || (native.frame_count != 0 && native.frames.is_null())
            || (native.tick_count != 0 && native.ticks.is_null())
        {
            CALLBACK_FAILED.set(true);
            return;
        }
        // SAFETY: descriptor bounds checked; C keeps both arrays live until return.
        let frames = if native.frame_count == 0 {
            &[]
        } else {
            unsafe { std::slice::from_raw_parts(native.frames, native.frame_count) }
        };
        let ticks = if native.tick_count == 0 {
            &[]
        } else {
            unsafe { std::slice::from_raw_parts(native.ticks, native.tick_count) }
        };
        STATE.with(|state| {
            let Ok(mut state) = state.try_borrow_mut() else {
                CALLBACK_FAILED.set(true);
                return;
            };
            // SAFETY: this callback runs only on the PHP request thread.
            let pointer = unsafe { profiler_context() };
            let Some(context) = state.contexts.get(&pointer).copied() else {
                CALLBACK_FAILED.set(true);
                return;
            };
            let State {
                sessions, sampling, ..
            } = &mut *state;
            let Some(adapter) = sampling else {
                CALLBACK_FAILED.set(true);
                return;
            };
            for session in sessions {
                if let (Some(engine), Some(tracker)) = (&mut session.engine, &mut session.sampling)
                    && engine.is_recording()
                {
                    tracker.record(engine, native, ticks, frames, context, adapter);
                }
            }
        });
    });
}
