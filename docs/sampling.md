# Wall-timer safepoint sampling

Sampling is opt-in and available on Linux builds using glibc 2.32 or later. Other
platforms retain ordinary instrumentation and return `false` from
`PhpProfiler\Profiler::isSamplingSupported()`. Capability does not guarantee that
thread or memory admission will succeed.

```php
use PhpProfiler\Profiler;

$session = Profiler::start(null, ['sampling' => true, 'max_events' => 512]);
try {
    application();
} finally {
    $session->stop();
}
$report = $session->getReport();
$session->reset();
```

`sampling` must be a boolean and defaults to `false`. Sampling-disabled reports
use the unchanged version 1 schema. Opt-in reports use
[version 2](../schema/report-v2.json), which adds a `sampling` object. Both report
accessors and local atomic publication support version 2. Encoded accessors keep
the same 16 MiB limit.

## What is observed

One local worker per PHP request thread waits for a fixed 10 ms wall-clock period.
It publishes actual wake/deadline records and requests a Zend VM interrupt. It
never walks PHP structures, calls Rust or executes PHP. The PHP thread captures
one stack when the VM services that interrupt. Every Rust borrow and callback
owner is released before chaining a previous interrupt handler, which may throw,
exit, fork or execute application code.

These are **wall-timer safepoint observations**, not independent CPU samples.
CPU-heavy loops can produce samples without a function-call boundary, but long
internal calls, JIT safepoints and scheduling can delay delivery. Multiple wakes
can coalesce into one actual stack. That stack is never copied N times or turned
into an estimated duration. The configured deadline is derived from the timer
schedule; wake, service and counter-snapshot timestamps are measured with
`CLOCK_MONOTONIC`. Delivery latency and loss remain explicit in the report.

Nested sessions share a worker, not sample ownership. Each sampled session has
its own counter baseline and bounded data. Pending wakes from before a new
session's start are filtered out. Ordinary unsampled sessions remain version 1.
The top-level report status concerns instrumentation; `sampling.status`, failure
and validity fields describe sampling separately.

## Report data and limits

`sampling.symbols` is an independent capture-order ID-to-name table.
`sampling.frames` contains those IDs; each record in `sampling.samples` addresses
its range through `first_frame` and `frame_count`, in leaf-to-root order. These
IDs are not indices into the instrumented function map. No observed calls are
invented for sampled frames that entered before profiling began.

`max_events` separately bounds sampling symbols and retained records. Flat frame
storage is admitted for at most 64 frames per record. Depth truncation, symbol
capacity, dropped records, dropped native ticks, coalesced wakes, invalid
observations, overflow and unserved wakes have distinct fields. Final counter
availability is explicit; its timestamp is separate from the execution stop
boundary. Unserved wakes do not receive synthetic stacks.

Sample names contain no arguments, return values, SQL, URLs or source paths.
Closures and anonymous classes use separate opaque sampling identities, bounded
to 512 entries of each kind per active sampling episode. Exhaustion truncates
sampling; it cannot exhaust the instrumentation name maps or create fake calls.

Rust sampling pools, names, materialized reports and JSON overlap use the shared
64 MiB requested-capacity quota. Large combined settings or retained sibling
sessions can exhaust that quota; materialization can fail after capture admission.
A sealed capture keeps its first stop facts for retry. Admission is not a promise
that every combination of maximum graph, names, timelines and sampling will fit.

Native worker resources are a separate bounded domain: at most 64 concurrent
workers per process, each requesting a 256 KiB stack and one OS guard page.
Fixed TSRM control storage and the registry pointer table are reported separately
in runtime metadata. These are configured/payload sizes, not resident memory
measurements; pthread TLS, allocator bookkeeping and stack caching are not an RSS
bound. See the [memory budget](core-memory-budget.md).

## Stop, fork and recovery

Execution and counter facts freeze before worker joining or JSON persistence.
Disabling or shutting down several sessions freezes them together, so serializing
one report cannot extend another's measurement. The final owner joins the shared
worker before publishing its report. Cleanup error/system codes are separate
runtime metadata: a transient join error followed by a real successful join does
not invalidate already measured samples. A nested stop can disclose deferred
cleanup while another sampled owner remains active. Published reports are not
changed later.

Fork handlers stop and join profiler workers before the fork. Sampling then stays
terminated in both parent and child until the next PHP request; instrumentation
continues. Reports can contain the inherited prefix, and `sampling.pid_start` and
`sampling.pid_stop` runtime metadata identify a changed process. Reset inherited
sessions before measuring fresh child work; this does not make arbitrary
multithreaded application forks safe.

After other transient backend failures, resetting all remaining sessions retires
the worker and clears retryable state. Fork termination and unavailable native
constructor resources retain their documented lifetime. No worker restarts
implicitly. Actual successful joins are required before control, executor or DSO
retirement. The implementation cannot promise finite shutdown progress after
permanent OS/POSIX join-contract corruption.

[Lifecycle validation](sampling-validation.md) records real PHP, ZTS, TSRM,
signal, fork and unload tests. Independent CPU sampling and exact allocation
volume/counts remain unavailable; instrumentation timelines are not substituted
for either.
