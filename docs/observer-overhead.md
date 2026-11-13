# Disabled observer dispatch

## Final ownership-gate benchmark observations

Nine ordinary Linux ARM64 images passed 114 workload captures with seven retained samples per mode. Source, image and extension identities, application results, exact capture checks and sampling counters passed the final analyzer. The virtual machine exposes 16 CPUs; load metadata is retained. These are local descriptive measurements, with no idle-host or universal-overhead claim.

The following application-loop medians use one million tiny leaf calls with OPcache/JIT disabled. Admission, stop, report retrieval and reset are outside this timed region. Warm disabled mode follows a real stopped/reset capture outside timing; allocator and observer caches are also warmed, so this is a state comparison rather than a causal isolation of one gate.

| PHP/ABI | Absent wall ms | Cold disabled / absent | Warm disabled / absent | Enabled wall ms |
| --- | ---: | ---: | ---: | ---: |
| 8.3 NTS | 15.904 | 1.4284 | 1.4261 | 434.663 |
| 8.3 ZTS | 16.505 | 1.4389 | 1.5498 | 457.269 |
| 8.4 NTS | 15.669 | 1.4481 | 1.3773 | 434.117 |
| 8.4 ZTS | 16.593 | 1.3910 | 1.5356 | 437.093 |
| 8.5 NTS | 15.433 | 1.4300 | 1.4206 | 424.676 |
| 8.5 ZTS | 16.614 | 1.3977 | 1.5651 | 431.592 |

Negligible disabled overhead remains unmet. Full instrumentation of tiny calls also has substantial relative overhead; these ratios must not be generalized to application workloads. CPU/wall medians, ranges, every retained sample, normal/sampled workloads and worker lifecycle measurements remain in the raw evidence. Worker timings include per-iteration start/stop/report/reset; their large enabled ratios use a tiny uninstrumented loop denominator.

True FPM measurements use twenty requests per batch in a reused worker. Batch wall time includes application, stop/report and local FastCGI transport; separately retained per-request workload CPU/wall excludes transport and report retrieval. Transport CPU is not measured. FPM graph status/count checks are enforced by the frozen driver before retention; those per-request graph fields are absent from saved timing JSON and are not synthesized. There is no FPM post-capture warm-disabled experiment.

Raw evidence and derived tables: `target/performance-sampling-ownership/performance/` and `target/performance-sampling-ownership/analysis/{analysis.json,tables.md}`. The final analyzer verifies 330 source inputs and all nine image/DSO hashes. It retains actual unserved stop-boundary wakes as disclosed sampling truncation; timer ticks are not converted into invented stacks.

The extension keeps Zend call observers installed while capture is disabled.
After this process first starts capture or defers a native bailout, the
first-party disabled callback path reads thread-local flags and performs no
clock, heap, symbol-copying or Rust callback work. This does not remove Zend's
observer dispatch, observed-frame bookkeeping or observer-aware VM execution.

Zend's supported registration API is startup-only. Its runtime add/remove API
operates on one function's cached handlers; it does not disable or revive the
whole extension. Returning empty handlers while disabled caches that decision
for an existing function. A previously created closure must still be observable
when PHP later starts capture. In addition, Zend links an observed frame before
calling begin handlers, so adding an end handler during the first active begin
is too late to establish that frame's end dispatch. Removing callbacks while a
shared handler list is being iterated also requires version-specific care for
other observers. The implementation does not override Zend's global observer
enabled flag or alter other extensions' callbacks.

Primary implementation references: [PHP 8.3 observer dispatch](https://github.com/php/php-src/blob/PHP-8.3/Zend/zend_observer.c),
[PHP 8.4 observer dispatch](https://github.com/php/php-src/blob/PHP-8.4/Zend/zend_observer.c),
and [PHP 8.5 observer dispatch](https://github.com/php/php-src/blob/PHP-8.5/Zend/zend_observer.c).

## Grouped TLS flags experiment

Three independent C TLS booleans were combined into one thread-local struct,
without changing capture, pending fatal or clock failure semantics. On the
measured macOS arm64 PHP 8.5.12 ZTS release build, disassembly confirms the end
callback resolves one TLS address instead of two. No Zend registration or cache
behavior changes.

`python3 scripts/observer-overhead.py --baseline /path/baseline.dylib --candidate
/path/candidate.dylib` records randomized paired absent/baseline/candidate runs,
checks the workload checksum, and retains binary hashes and every sample. The
initial isolated source trees differed only in `bridge.c`. Raw local artifacts
are under `target/disabled-overhead`; they are not committed benchmark receipts.

| Idle local run | Absent median | Previous disabled | Grouped disabled | Paired grouped/previous median |
| --- | --- | --- | --- | --- |
| 31 pairs, one million leaf calls | 15.682 ms | 25.378 ms | 25.025 ms | 0.98079 |
| 15 pairs, three million leaf calls | 46.786 ms | 75.926 ms | 73.783 ms | 0.97309 |

This is a small measured improvement on one ZTS host. It does not demonstrate
negligible disabled overhead: the grouped extension still took 1.577 times the
absent execution time in the second call-heavy workload. NTS, FPM, optimizer,
application-level and other-observer comparisons require their own measurements.

## Active instrumentation layout

The begin/end observers now keep their original TLS gates in thin wrappers and
call private `zend_never_inline` helpers for active instrumentation. The end
wrapper still rethrows a pending bailout before checking capture state. This
changes code layout without adding a process-wide hint or changing lifecycle
accounting. Release disassembly on the tested macOS arm64 builds confirms that
disabled callbacks avoid the active body's 128-byte register-save area. Zend
debug builds may define the noinline macro differently; correctness does not
depend on that optimization being honored.

Isolated paired PHP 8.5.12 ZTS runs observed never-started CPU ratios of 0.9367
and 0.9512 versus the previous layout. Disabled-after-stop results varied between
runs, and PHP 8.3.35 NTS showed no reliable improvement. Enabled captures retained
their exact call counts and complete status, without a reliable CPU regression
in these samples. These runs had substantial concurrent host load: they do not
establish idle-host latency, universal improvement, or negligible disabled
overhead. Wall time and CPU time are retained separately rather than treating
descheduling as profiler work. Raw source manifests, binary hashes, samples and
disassembly are under `target/wrapper-layout`; an interrupted confirmation is
explicitly retained as incomplete.

## Further isolated candidates

An extension-owned global hint was investigated in isolated source copies, without
changing the product. A monotonic “profiling has ever started” hint can bypass TLS
in a never-started process. It cannot improve later disabled requests. Clang
hoisted TLS resolution before an inline hint check; separate non-inlined active
continuations were needed to obtain a genuinely TLS-free disabled wrapper.

Under concurrent host load, the larger paired trial observed a candidate/baseline
median of 0.833, but the candidate still took 1.640 times absent execution. A
wrapper-only control retained the original TLS and pending-bailout semantics and
reduced the disabled register-save area from 128 to 16 bytes. Its paired median
was 0.919, with a descriptive bootstrap interval of 0.611–1.012. These are noisy
local experiments, not established cross-platform improvements. Neither was accepted from those initial measurements; the subsequently refreshed
TLS-only wrapper work is described above. Raw samples, binary/source hashes, disassembly,
smoke tests and qualifications are retained in `target/disabled-hint/review.json`.

A reversible aggregate hint would require registering each thread before either
capture or a deferred bailout becomes active, releasing it only after both clear,
and proving initialization, teardown, fork, overflow and abnormal-thread behavior.
A false zero could skip the required fatal rethrow. The simpler existing gate
remains in use; these experiments do not satisfy the negligible-overhead target.

## Empty-observer control

A separate, explicitly nonfunctional control returned empty begin/end handlers
from the registered initializer. It never invoked profiler APIs or report/fatal
paths. Actual initializer assembly was checked. In 15 randomized pairs of three
million calls, measured CPU medians relative to an absent extension were 1.164
for PHP 8.3 NTS and 1.069 for PHP 8.5 ZTS. Production-disabled comparisons in those
runs were 1.276 and 1.453 respectively. Wall timings were more affected by host
contention.

This shows residual loaded-observer cost in those workloads even after removing
the callbacks entirely. It is a diagnostic control, not a working implementation,
an idle-host certificate, or a proof that every possible integration has that
minimum cost. It must not be loaded for real profiling. Complete raw samples,
CPU/wall observations, source/binary hashes and disassembly checks are retained in
`target/empty-observer-bound/review.json`.

## Runtime activation API review

PHP 8.3–8.5's public headers explicitly restrict fcall observer registration to
module startup. Public per-function add/remove functions do exist, but require
prior registration; they do not offer bulk invalidation or deferred registration.
See the [public header](https://github.com/php/php-src/blob/PHP-8.5/Zend/zend_observer.h#L64-L75).

`zend_observer_activate()` only resets the current observed-frame chain. It is
not a runtime enable operation. Post-startup setup reserves handler-cache space,
changes internal frame temporaries and selects observer VM handlers. Repeating
that setup after code/frames already exist is not a supported activation API;
see the [startup implementation](https://github.com/php/php-src/blob/PHP-8.4/Zend/zend_observer.c#L58-L94).
JIT emission also tests observer enablement while generating calls, rather than
providing a later global deoptimization operation;
see [JIT generation](https://github.com/php/php-src/blob/PHP-8.4/ext/opcache/jit/zend_jit_ir.c#L10389-L10407).

Returning empty handlers while disabled caches that decision per function.
Re-enabling requires reaching every previously cached callable safely. Named
function/class tables and live Closure objects do not cover all completed
OPcache main-script wrappers: OPcache creates temporary op-array copies and Zend
frees them after execution. Retaining raw function pointers is therefore unsafe;
retaining PHP owners can change destructor timing. See
[OPcache loading](https://github.com/php/php-src/blob/PHP-8.5/ext/opcache/zend_accelerator_util_funcs.c#L367-L375)
and [script teardown](https://github.com/php/php-src/blob/PHP-8.5/Zend/zend.c#L1982-L2006).
Clearing shared observer cache data or resetting the frame chain would also
interfere with other observers. Control-method end callbacks must remain available
for this extension's deferred fatal rethrow even after capture stops.

This review found no supported, complete runtime-registration/invalidation path
that preserves the current call-coverage, JIT and coexistence contracts. No
runtime deferral or global observer-state override was implemented. The
empty-handler control remains a nonfunctional diagnostic, not an implementation
of delayed profiling.

A renewed review against the exact PHP 8.3.36/8.4.27/8.5.12 SDKs also checked
compile hooks, op-array destructors and live-callable enumeration. Temporary
`Closure::call` caches can be freed directly without an op-array destructor, and
shared op-arrays can return before that destructor hook. Those hooks do not
provide a complete lifetime registry. Public handler mutation also lacks an
iterator/cache-installation completion contract for PHP control reentered from
other observers. No unsafe cache registry or runtime global-state override was
adopted. Exact tagged sources and SDK hashes are retained in
`target/blocker-api-audit/REPORT.md`.

## Monotonic ZTS process hint

A subsequent isolated, current-source comparison accepted a narrower ZTS-only
hint. Its atomic boolean starts false and is never reset. The capture setter
publishes true before setting its thread-local active flag. Every first-party
native bailout producer uses one `profiler_defer_bailout()` helper, which
publishes true before setting the thread-local pending flag. Disabled begin/end
wrappers can return before resolving TLS only while this process hint is false.
Separate noinline used continuations preserve the original TLS checks and the
end callback's fatal rethrow before its capture gate. This adds no runtime PHP
option and does not change Zend's observer registration or other observers.

On a thread that has active capture or a pending bailout, its own preceding
atomic store happens before its later atomic load. Atomic write/read coherence
therefore prevents that load from seeing the original false value. Other threads
share only the conservative hint, not their private flags. Acquire/release
ordering is retained. There is no decrement, overflow, thread registration or
fork reset: inherited true can add work, but cannot suppress required callbacks.
The hint deliberately cannot improve later disabled requests once that worker
process has started profiling. Getter-only APIs can retain the false fast path
when they neither start capture nor defer a native bailout. Vendor exception and
constructor cleanup uses its own guarded rethrow path rather than this pending
flag; it does not depend on the skipped observer callback for cleanup.

The NTS build compiles out the hint. Its begin and active-setter instructions
matched the prior build exactly on the tested arm64 PHP 8.3 installation; its end
callback differed only in the bailout diagnostic source-line immediate. The
native symbol table contained no hint or used-continuation objects. ZTS
disassembly confirmed global-load/branch/return wrappers without TLS resolution,
followed by tail calls to the original used gates. The final ZTS-only wrapper
instruction shapes matched the timed isolated candidate.

Fifteen randomized paired rounds per installation timed three million leaf calls
in never-started, getter-only and after-capture/reset states, and 100,000 exactly
counted enabled calls. This project's heavy jobs paused during timing; unrelated
host work remained, so these are qualified local measurements. CPU and wall time
are retained separately in `target/monotonic-hint-current/paired-{zts,nts}.json`.
The tables report paired CPU ratios, with descriptive bootstrap intervals; they
are not universal confidence guarantees.

| State | PHP 8.5.12 ZTS candidate/prior | PHP 8.3 NTS shared-hint experiment |
| --- | --- | --- |
| Never started | 0.91435 (0.90236–0.95708) | 0.99616 (0.92306–1.04935) |
| Getter only | 0.92418 (0.90892–0.97105) | 0.97335 (0.91756–1.04500) |
| After capture/reset | 1.01746 (0.99520–1.04756) | 1.01715 (0.91719–1.07545) |
| Enabled | 0.99353 (0.96001–1.01237) | 0.99791 (0.96315–1.02854) |

The experiment showed a modest ZTS never-used improvement and no established NTS
gain. The adopted NTS code consequently retains its previous gates. The ZTS
after-capture result allows a small regression; enabled measurements did not
establish a regression or improvement. The ZTS candidate's never-started median
CPU was still 1.39548 times absent execution, so the negligible-disabled-overhead
requirement remains unmet. This change does not establish production readiness.

Both matching PHP regression and real deferred-fatal suites passed the isolated
candidate. Four concurrent ZTS threads completed eighty requests with exact
reports and independent closure/runtime-cache identities, with OPcache off and
on. Private C unit cases additionally started before any profiler API: cold
callbacks left the hint false; each of the four real guarded bailout producers
published it, and the end callback rethrew under a C-owned catch. A source-level
negative control removing only the helper's hint publication failed all four
checks. The promoted private transport also deliberately constructs the invalid
false-hint/pending state to keep the lost-rethrow oracle explicit; that test-only
state construction is absent from release builds. Raw sources, binaries,
disassembly, paired samples and positive/negative checks remain under
`target/monotonic-hint-current`. Final whole-workspace coverage, mutation and
compatibility refreshes qualify the promoted source separately.

## Execute-hook alternative

Replacing `zend_execute_ex` at startup is not a JIT-preserving alternative: PHP
8.5's [JIT support check](https://github.com/php/php-src/blob/PHP-8.5/ext/opcache/jit/zend_jit.c#L3772-L3786)
explicitly disables JIT when it differs from `execute_ex`. Installing the hook
only at runtime also misses already compiled specialized calls:
[DO_UCALL](https://github.com/php/php-src/blob/PHP-8.5/Zend/zend_vm_def.h#L4170-L4193)
enters the VM directly and
[DO_ICALL](https://github.com/php/php-src/blob/PHP-8.5/Zend/zend_vm_def.h#L4105-L4134)
calls the internal handler directly. The
[compiler's opcode choice](https://github.com/php/php-src/blob/PHP-8.5/Zend/zend_compile.c#L3929-L3959)
depends on hook state while compiling. This audit found no supported late
invalidation mechanism that restores complete call coverage without changing
those contracts. Downloaded primary-source snapshots and checksums are retained
under `target/overhead-api-audit`; no execute-hook replacement was implemented.
