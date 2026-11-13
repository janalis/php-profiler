# Measurement feasibility and boundaries

These constraints are part of the implementation contract, not measured feature parity with another profiler.

## Function-call visibility

Call graphs contain actual calls delivered by Zend's function observer API.
Some source-level builtins are compiled to dedicated VM opcodes or constants,
with no function-call frame. For example, a direct `strlen($value)` can become
`ZEND_STRLEN`, while an equivalent dynamic callable executes an observed internal
function. A recorded-call graph can therefore be complete while omitting that
source-level builtin invocation. Its work remains in an enclosing observed
function's time, or the session elapsed time when no enclosing frame was observed;
no separate call count or builtin duration is invented.

This applies even without OPcache. Optimizer/JIT transformations and native
library work can have additional visibility boundaries. The extension does not
disable compiler optimizations or replace opcode handlers to create synthetic
function calls. Full source-level call coverage is unsupported under the current
execution-semantics/observer contract. Primary reference:
[PHP 8.3 compiler builtin dispatch and strlen lowering](https://github.com/php/php-src/blob/php-8.3.36/Zend/zend_compile.c#L3726).
The boundary fixture checks direct-opcode versus dynamic-call behavior on actual
PHP 8.3/8.4/8.5, including configured OPcache; it does not assert warmed JIT code.

## Allocation counts and exact session peaks

`zend_memory_usage(false)` reports current Zend memory-manager usage. Its delta
is a signed net change, not allocation volume or an allocation count.
`zend_memory_peak_usage(false)` observes PHP's current request peak window,
which application code can reset. Resetting it for a profiler session would
change application-visible `memory_get_peak_usage()` and is prohibited.

Some audited Linux SDKs expose allocation-limit testing controls. They deliberately
force allocation failure and expose a threshold setter/hit flag, not passive
allocation totals for the default heap. They cannot supply these measurements
without changing execution. See [the audited implementation](https://github.com/php/php-src/blob/php-8.5.12/Zend/zend_alloc.c#L3256-L3268).

Default Zend MM has no public, transparent per-allocation event observer.
Installing custom handlers changes the heap's allocator mode; simply forwarding
to another allocation entry point does not preserve all mode-dependent behavior.
For example, custom-heap branches affect GC/cache reclamation and block-size
queries. Native and persistent allocations can also bypass request-heap
handlers. The supported profiler therefore exposes measured heap snapshots,
net deltas, boundary-observed peaks and the request peak snapshot. General PHP
allocation counts, allocation volume and exact session-local peaks remain
unavailable rather than being inferred from those values.

A narrower measurement is technically possible for an **already custom,
untracked system allocator**: pure C wrappers could count calls to the existing
known malloc/free/realloc handlers and sum the size arguments passed to malloc
and realloc before forwarding original arguments unchanged. Read-only probes on
PHP 8.3.36, 8.4.27 and 8.5.12 distinguish this mode from default and tracked heaps
through the public handler getters. Such counters would describe handler-call
attempts and handler-requested bytes, including requests whose allocator later
bails out. They would not describe objects, successful allocations, freed bytes,
net growth or general PHP allocation volume. Specialized `emalloc` entry points
can already round a constant request to a size class before invoking the handler;
a realloc size is the requested new total, not newly allocated bytes.

This narrow mode is **not implemented or advertised as allocation support**.
Unknown/tracked handlers may inspect their own callback identity, so forwarding
alone does not prove transparent behavior. PHP 8.4+ also has GC/shutdown hooks
that must be preserved with the extended APIs. A later allocator owner can retain
our forwarding callback in an opaque chain; the public get/set interface cannot
unlink that hidden reference. Request shutdown, TSRM fallback and module/DSO
unload must restore ownership safely before heap destruction or callback code
unloads. Generic coexistence, handler replacement and those lifetime cases need
independent proof before any such capability could be promoted. This is a
specific public-API limitation under the profiler's execution-semantics contract,
not a claim that every narrowly defined allocation counter is physically
impossible.

Primary references: [PHP 8.3 allocator and handler dispatch](https://github.com/php/php-src/blob/php-8.3.36/Zend/zend_alloc.c),
[allocation size-class selectors](https://github.com/php/php-src/blob/php-8.3.36/Zend/zend_alloc.h),
[PHP 8.4 extended custom handlers](https://github.com/php/php-src/blob/php-8.4.27/Zend/zend_alloc.h),
[PHP 8.5 allocator lifecycle](https://github.com/php/php-src/blob/php-8.5.12/Zend/zend_alloc.c),
[request/module shutdown ordering](https://github.com/php/php-src/blob/php-8.5.12/main/main.c)
and [TSRM resource teardown](https://github.com/php/php-src/blob/php-8.5.12/TSRM/TSRM.c).

## Statistical sampling

Opt-in wall-timer/VM-safepoint sampling is implemented on Linux glibc 2.32+.
A fixed 10 ms timer requests a VM interrupt; the PHP thread services real timer
wakes and captures a bounded stack. The worker never walks Zend structures or
runs PHP/Rust. Sampling is disabled by default and controlled through PHP.
See [sampling semantics and limitations](sampling.md) and the
[real ZTS lifecycle validation](sampling-validation.md).

These are wall-timer observations with explicit safepoint and scheduling bias,
not unbiased statistical CPU samples. Blocking internal calls can delay service
and coalesce wakes; overflows and truncation are disclosed. Instrumentation
timelines remain function-boundary records, separate from independent timer
observations. Unsupported platforms retain function profiling without sampling.
No CPU durations or fictitious repeated stacks are synthesized from timer ticks.

Primary references: [Zend interrupt service](https://github.com/php/php-src/blob/php-8.5.12/Zend/zend_vm_def.h)
and [opaque Zend atomics](https://github.com/php/php-src/blob/php-8.5.12/Zend/zend_atomic.h).

## SQL, HTTP and I/O

Classifying a known client function identifies client-call latency. It does not measure database execution time, network transfer time, or server latency independently. Async operations, multiplexed handles and native library work outside observed calls can require library-specific integration. SQL text, URLs, parameters, headers, payloads and return values are excluded by default. No stack or protocol data is invented to fill unavailable metrics.

## Termination

Normal shutdown can finalize unfinished sessions and attempt local persistence. SIGKILL and crashes cannot execute cleanup. Unrecoverable allocator failure may make report serialization unsafe. No shutdown durability guarantee is made for those cases. Atomic publication protects the final report name from a partial JSON write; it cannot guarantee a report exists after hard termination.

## Disabled overhead

Loading an observer extension changes Zend execution dispatch even when no session is active. Callback fast paths reduce extension work but cannot remove the engine's observer checks. Benchmark absent, loaded-disabled and enabled separately. A failed negligible-overhead target remains a release blocker; it is not redefined as zero allocations or merely lower overhead than enabled mode.

Reference: https://github.com/php/php-src/blob/PHP-8.3/Zend/zend_observer.c
