# Unsafe integration inventory and review boundaries

This inventory records reviewed assumptions and evidence, not a whole-extension safety clearance. The Zend-independent engine uses safe Rust. The native adapter depends on the exact PHP build and the ext-php-rs 0.16.4 / derive 0.12.4 behavior, including the local exception-ownership patch documented in `vendor/README.profiler-patch.md`.

## Native boundary inventory

| Boundary | Required invariant | Current handling and remaining obligation |
| --- | --- | --- |
| Zend observer registration | Registration occurs during module startup; handler code remains loaded for the engine's observer lifetime. | C registers function and fiber observers from MINIT. Runtime `dl()` unloading is not established as supported. |
| Function entry names/scopes | Zend strings remain valid throughout the synchronous callback; lengths describe valid allocated byte ranges. A null scope is never dereferenced. | C always supplies a non-null name or literal `{main}`. Rust reads the scope only when its length is positive, copies names, and rejects oversized symbols. Public invalid-byte identifiers use reversible hex encoding and remain subject to the core symbol limit. Private generated-name keys retain bounded raw bytes rather than lossy expanded text. |
| Frame/fiber/closure identities | Encoded addresses are compared only as identities; they are never dereferenced in Rust or serialized as addresses. Address reuse must not merge unrelated lifetimes. | Adapter maps frame and fiber addresses to monotonically assigned IDs, removes ended frames/destroyed contexts, and filters pre-session frame exits. Closure identities use monotonic process-wide IDs stored with `memcpy` in a public per-function runtime-cache extension word. Cache lifetime, rather than recycled opcode addresses, defines this identity; rebound closures may receive distinct rows. UID exhaustion fails capture without raw-name fallback. C additionally checks `ZEND_USER_FUNCTION` and excludes fake closures before reading the op-array union; verify these ABI/flag assumptions for every supported PHP version. |
| Metadata strings | PHP version/SAPI pointers are non-null, NUL-terminated and valid while copied. INI values may be absent or arbitrarily long. | Version/SAPI are engine-owned module-lifetime C strings scanned only up to 129 bytes; oversized values are omitted before copying with `execution_metadata_complete=false`. INI lookup checks for null and uses exact lengths; values over 128 input bytes are omitted before Rust copying, with `configuration_metadata_complete=false`. Selected OPcache/JIT configuration does not prove actual JIT execution. |
| Clock/heap snapshots | Calls read the current PHP thread and cannot invoke user code or allocate Zend objects. | C uses monotonic/thread CPU clocks and `zend_memory_usage(false)`. OS failure state invalidates reports. Snapshot components are sequential observations, not simultaneous hardware measurements. |
| Rust observer callbacks | Rust panics must not cross the C ABI; request state must not be mutably reentered. | `ffi_guard` catches panics for entry, exit and fiber callbacks and marks capture failed. `try_borrow_mut` prevents nested mutable borrows and marks capture failed whenever an observation is skipped because state is already borrowed. This does not catch allocator aborts, signals or Zend longjmp. Module/request lifecycle callbacks have separate non-reentrancy assumptions. |
| Report array/string conversion | A Zend allocation failure must return to C before bypassing Rust-owned values. | First-party C `zend_try` blocks catch bailout, set a thread-local pending flag and return a null zval. The next observer-end boundary rethrows before control-method filtering, after the Rust method has returned. This relies on observer-end execution for supported PHP invocation paths. |
| Filesystem policy and virtual CWD | Paths must respect PHP open_basedir and Zend virtual CWD, including ZTS; no user error handler may run below Rust. | C suppresses open_basedir warnings and resolves paths into a capacity-checked caller buffer, with C-owned bailout guards. Fatal-deferred results must be distinguished from ordinary denial/failure so Rust does not construct a new allocating PHP exception before the pending fatal is rethrown. |
| Returned zvals | Rust and C agree on the zval ABI and ownership; return buffers outlive C consumption. | ext-php-rs bindings provide the layout. Borrowed JSON bytes remain alive until conversion returns. Array conversion receives explicit length plus a trailing NUL. A failed partial decode is not resumed as a usable application value. |

Generated closure and anonymous-class report labels are opaque request-local identifiers. Their private adapter lookup keys still contain copied engine names, potentially including source locations. They are not report fields. This distinction matters for both retained-memory accounting and process/core-dump privacy.

## ext-php-rs constructor and exception boundary

The generated PHP wrappers are not wholly unguarded: derive emits `zend::run_handler`, which invokes the dependency's C-backed `try_catch`. That boundary reraises a bailout and runs the dependency's registered `BailoutGuard` cleanups. It does not automatically run destructors for every ordinary Rust local skipped by longjmp.

Two dependency paths therefore remain explicit review obligations:

- Returning a `Session` object calls `ZendClassObject::internal_new`, including `ext_php_rs_zend_object_alloc`, `zend_object_std_init` and `object_properties_init`.
- Returning `PhpResult::Err` calls `PhpException::throw(self)`, where `ZendStr::new` and `zend_throw_exception` can allocate. The local dependency patch registers exception/fallback owners with `BailoutGuard`; direct and fallback native OOM regressions now verify exact ownership reclamation in the auxiliary fixture.

The dependency's C allocation shims directly call Zend allocation routines. A surrounding `try_catch` alone is not proof that all skipped owned values are registered for cleanup. Review generated argument/result locals and failure cleanup for these paths, retain the established direct/fallback exception OOM regressions, and assess constructor failure and retained resources across reused FPM requests. Successful normal constructors and ordinary exceptions do not establish OOM safety. The first-party report conversion guards do not clear this separate obligation.

## Direct local evidence

On the local macOS arm64 / PHP 8.5.12 ZTS debug extension, the following checks were rerun during this inventory review:

- `scripts/test-report-oom.py`: both JSON-string and structured-array report conversion exhausted the configured Zend memory limit, exited fatally with code 255, and produced neither PHP catch nor continuation markers.
- `tests/php/generated_symbols.php`: distinct closure sites and anonymous-class methods retained distinct opaque labels; the report omitted the fixture's source path.
- A manual `json_last_error()` probe retained error code 4 across a successful structured `getReport()` call.

These observations concern the loaded local artifact and tested paths. They do not certify generated object/exception OOM handling, every PHP invocation mechanism, NTS, FPM, all PHP versions, allocator exhaustion in Rust, sanitizers, or abnormal process termination. Core line/branch/function coverage and cargo-mutants results do not replace this native evidence.

## First-party requested-capacity admission limit

The PHP adapter now shares one 64 MiB `Budget` owner per request across its
first-party profiling collections, every retained session/engine/report, copied
report metadata, serialized JSON and temporary publication path. The budget is
created lazily when capture is first requested. Reservations are admitted before
fallible allocation and stay with their owners until drop. Capture and report
materialization can coexist only while their combined reservation fits. The
pinned `triomphe` budget-control allocation is included.

This is a precise **first-party requested heap-capacity admission limit**, not a
process RAM limit or a promise that all native allocations are intercepted.
It excludes allocator bookkeeping/rounding, OS resources, fixed stack/static TLS
storage, caller-owned PHP arrays/strings, Zend-managed instrumentation cache slots, third-party framework exception
transport, and standard-library filesystem/error scratch. Those exclusions must
remain explicit. The adapter's inline path buffers, scalar identities and stack
symbol formatter have fixed compile-time bounds. Framework/OS allocation
failure and PHP hard termination still require their separate safety evidence.

`Budget.used()` and `Budget.peak()` are reservation-ledger counters. They include
admission charges made before allocator calls and transient failed attempts.
They must not be described as measured native allocated bytes, actual RAM, RSS,
or a measured allocation peak. The owned collection capacities themselves are
checked as `capacity * size_of::<T>()`; dynamic text and JSON retain their own
reservations. Static report vocabulary is borrowed instead of cloned.

| Component | Storage and admission contract |
| --- | --- |
| Capture | Seven fixed-capacity typed pools: inline 1024-byte symbols, indexed edges, shared frame slots, context heads, optional timeline records, and two lookup indexes. Their actual requested capacities are charged before capture. PHP permits capacities 1..8192, with default 512 and shared quota admission. Repeated calls update scalar metrics without growing a history. |
| Adapter identities | Two 8192-slot typed identity tables for frames and contexts, and two 8192-slot generated-name tables. Generated keys retain at most 1024 raw bytes inline; their opaque labels are formatted on the stack. Normal frame lookup scans from the newest entry; removal reuses a slot. |
| Sessions and paths | A charged 16-slot session vector contains inline input paths of at most 4096 bytes and published paths of at most 4352 bytes. The default path lives inline in fixed request state. Borrowed PHP inputs are validated before copying. Native C path checks, virtual-CWD resolution and generated basenames use bounded stack buffers. |
| Reports | Fallible fixed-capacity maps/vectors and budget-owned copied text preserve the JSON schema. Finalization reserves both source and destination storage simultaneously; failure retains the finalized capture and original stop snapshot for retry. Further boundaries do not modify that finalized capture. |
| Metadata | Selected version/SAPI identities and INI values are limited to 128 raw bytes before copying. Oversized values are omitted with completeness flags. Invalid bytes use lossless ASCII hex. Dynamic report text is admitted under the same budget. The `php_version` value comes from build headers, not an independent runtime patch-version query. |
| JSON access | A counting pass enforces the separate 16 MiB encoded report limit. The second pass allocates one exact charged output buffer, including the decoder's required NUL sentinel. Its owner survives until Zend's array/string copy returns. |
| File output | Streaming serialization avoids a whole-report JSON string. The private same-directory publication path retains a charged byte buffer. Standard-library filesystem conversion/error scratch is outside the first-party capacity domain. |

Quota exhaustion and allocator denial return explicit errors rather than using
an aborting global allocator as a quota mechanism. Capture cardinality exhaustion
still reports truncation or an adapter interruption, preserving recorded data.
On report admission failure, reset another retained session to release storage,
then retry stopping the preserved session, or reset it explicitly. No partial
report is labeled complete or published on an unsuccessful finalization.
Reset releases session-owned captures/reports; fixed adapter slots may remain
reserved for reuse until request shutdown, so repeated worker jobs need not
reallocate those tables. The request's final teardown releases their owner.

The native adapter storage tests deny **every** subsequent Rust allocation after
admission and exercise all 8192 identity/generated-name entries, repeated
lookups/removals, capacity rejection, symbol formatting and inline paths. They
observe zero allocation attempts in that phase. Core allocator-denial/ledger
integration tests separately verify capture, report, retry and JSON ownership.
These tests complement real PHP lifecycle/fiber/path/OOM fixtures; they do not
establish whole-interpreter allocator behavior, every possible OS failure or
process RSS bounds. See [core budget contracts](core-memory-budget.md).

## Exception ownership: source defect, local fix and native evidence

The upstream 0.16.4 source had a concrete resource-retention defect: `PhpException::throw(self)` held an ordinary Rust `String` while `throw_with_code` performed Zend allocations. The generated outer `try_catch` runs registered guards, but upstream did not register that `self`. A Zend bailout skipped the message's Rust destructor, and Zend request-heap cleanup could not reclaim its Rust allocator buffer.

The local patch registers the exception, escaped-message and formatted-fallback owners with the dependency's existing `BailoutGuard` cleanup stack. The class name is borrowed `&str`, and the error variants reachable on this pinned fallback path have no heap-owned payload, so their unnecessary boxes were removed. It takes an optional attached object through the guard. It preserves normal return, pending-exception and fatal-error semantics. The patch is isolated and described in `vendor/README.profiler-patch.md`; no registry cache is modified.

`python3 scripts/test-vendored-exception.py` now tests this defect directly using `tests/support/exception-fixture`, an auxiliary extension excluded from the production module. Its counting Rust global allocator forwards every allocation/reallocation/deallocation to `System` and measures outstanding requested bytes. The fixture primes fallback cleanup capacity before the baseline, constructs an actual 8 MiB Rust exception message, forces a real Zend memory-limit failure while throwing, and reads the outstanding Rust bytes in a PHP shutdown callback. Both direct and fallback paths must exit fatally with code 255 without catch or continuation markers and must have exactly equal before/after counters.

Coordinated native validation reported:

| Configuration / source | Observed ownership result |
| --- | --- |
| PHP 8.3.35 NTS, unmodified read-only crates.io control | The control ownership assertion failed: 489 bytes before, 8,389,097 after, an exact 8 MiB retained allocation. This establishes the original defect. |
| PHP 8.3.35 NTS, final three-guard patch | Both direct and fallback cases returned to exactly 545 bytes before / 545 after. PHP 8.3 exercises immediate Zend bailout cleanup. |
| PHP 8.5.12 ZTS, final three-guard patch | Both direct and fallback cases returned to exactly 593 bytes before / 593 after. The fixture adjusts only its test process's hard-limit percentage so PHP 8.5's soft-OOM shutdown callback can run. |

These are native ownership-test results, not profiler measurements, RSS measurements or performance benchmarks. The allocator counts only the auxiliary module's Rust allocations, not the entire PHP process or Zend heap. Its normal-path fixture additionally checks ordinary, pending, attached-object, invalid-object, NUL-message and abstract-class exception behavior.

Repeated-worker ownership is now checked by `scripts/fpm-vendor-ownership-test.py` on Linux PHP 8.3, 8.4 and 8.5. One static worker processes eight direct/fallback exception OOM requests, each followed by a healthy auxiliary request. Its PID remains unchanged. The fixture primes maximum cleanup-stack bookkeeping before the first snapshot. Exact outstanding Rust counts are equal before/after every OOM request and at each following healthy request: 666 bytes in the PHP 8.3 run and 730 bytes in the 8.4/8.5 runs. These are auxiliary-module allocator counts, not interpreter/native allocations or RSS. Separate profiler FPM recovery fixtures verify fresh session IDs and call counts after fatal requests.

The OOM workload deliberately crosses PHP's soft memory limit while remaining below its hard limit. The interpreter can terminate the entire worker with `_exit(137)` on a hard-limit breach, bypassing request shutdown; the profiler cannot promise cleanup or report persistence in that case. See the [PHP upstream hard-limit discussion](https://github.com/php/php-src/issues/24492).

Constructor handling remains a separate, narrower assessment. This extension's returned `SessionHandle` contains only an integer; its output path has already moved into request-owned state before return, and these non-variadic methods use the dependency's fast handler path. A generic `T`-payload leak in `ZendClassObject::internal_new` is not established for the current session type. A targeted session-object allocation-failure test must still verify fatal behavior and clean request teardown. Generic upstream object allocation can protect owned `Option<T>` with registered cleanup; simply returning null from the allocation shim or converting fatal OOM into a PHP exception would change semantics and is not an acceptable fix.


## Targeted ownership-patch mutation evidence

`scripts/vendor-patch-mutation.py` performs isolated native mutation experiments,
not a blanket dependency MSI calculation. The initial five-guard source produced
five viable mutants: three ownership failures and two survivors, with no build
failures or timeouts. Inspection proved the survivors wrapped a borrowed class
name and heap-free reachable error variants; the production patch was simplified
to remove those two unnecessary boxes. Raw five-run results remain recorded.

The final three owned-buffer guards were each removed independently in an
isolated copy. All three mutants were rejected by the exact PHP 8.3 native
ownership assertion; none survived, failed to build or timed out. Removing the
exception or escaped-message guard leaked exactly 8 MiB; removing the formatted
fallback guard leaked 8 MiB plus 66 bytes. This evidence is scoped to the changed
ownership guards. It does not establish whole-upstream coverage, every possible
Zend allocation failure, the constructor gate or the
separate first-party capacity-admission contract described above. See
`vendor/README.profiler-patch.md` for the
reachability proof and reproduction commands.

The manual patch runner additionally replaces the changed
`exception.object.take()` transfer with `None` in an isolated copy. Its dedicated
normal attached-object probe rejects loss of the original exception object.
The completed PHP 8.3 run contains four viable targeted mutants: three rejected
by ownership assertions and one by attached-object semantics, with zero
survivors, unviable builds or timeouts. The three-owned-guard claim remains
separate; this is not a fourth cleanup guard or a whole-dependency MSI.

## Arithmetic range and metric availability

Metric accumulation now uses checked addition/subtraction and checked signed
heap-delta conversion. `metric_overflows` counts detected range errors. A report
with a range error is incomplete; a capped numeric value is retained only as an
explicitly unavailable placeholder. If the diagnostic counter itself saturates,
its own metric name is also unavailable rather than being claimed exact.

`unavailable_metrics` uses provenance-registry metric names, not JSON pointers.
Each name conservatively applies to every instance of that metric across
functions, graph edges, operations, hotspots and timeline records. Corresponding
provenance begins `unavailable:`. Consumers must ignore those numeric placeholders;
they must not treat a cap or zero as a measurement. Dependent derived values are
invalidated, and unavailable wall-time ranking produces no hotspot entries.

Native clock failure and numeric clock regressions invalidate timing fields and
make the report incomplete. Disabled Zend MM makes memory fields unavailable,
sets `boundary_memory=false`, and leaves the request peak null; it need not
invalidate otherwise valid timing/call-count data. Both paths use shared report
helpers so explicit stop and unfinished-request finalization agree.

Deterministic core fixtures include two recursive frames whose valid individual
wall/CPU spans are each `u64::MAX`, overflowing their aggregate, signed heap
extremes, active-segment accumulation, child time exceeding parent time and
idempotent native unavailability handling. These are synthetic boundary tests,
not claims that native PHP workloads consumed those durations or heap sizes.

## Session handle construction and clone audit

`Session` is final, nonserializable, and requires its native factory. Native
regressions reject direct construction, serialize/unserialize and reflection
construction without a constructor. Fifty failed clones must preserve the sole
original handle, its session report, and subsequent sessions after reset.

The dependency's direct clone callback originally retained a Rust-owned error
string across `zend_throw_error` without a native bailout frame. A test-only
interposer at that allocation boundary proved a 75-byte retained allocation on
PHP 8.3 (730 before / 805 after). The narrow local patch borrows the registered
Zend class name with a static format instead; both PHP 8.3 (730/730) and PHP 8.5
(794/794) preserve exact outstanding Rust allocation counts on injected bailout.
The fresh error-path placeholder remains unchanged. See
[vendor patch details](../vendor/README.profiler-patch.md) and
`python3 scripts/clone-bailout-tests.py`. This is an injected allocation-boundary
proof, not a naturally triggered clone OOM or whole-dependency safety claim.

The extended changed-hunk runner additionally restores the old owned clone-error
`CString`. PHP 8.3 rejected all five targeted mutations (three ownership guards,
attached-object transfer and clone-error ownership), with no build failures or
timeouts: `target/vendor-patch-mutation-five-83/results.json`. The separate macOS PHP
8.5 and Linux PHP 8.3 natural-OOM experiments rejected the attached-object and
clone mutations but retained the three guard mutations because those soft-limit
paths did not expose their ownership failure. Raw survivors remain recorded at
`target/vendor-patch-mutation-five/results.json` and
`target/vendor-patch-mutation-linux83/results.json`. These configurations are not
interchangeable evidence.

The final contract runner supplements natural soft-limit OOM with a real native
bailout at the exception-allocation boundary (`zend_throw_exception`) and the
clone-error boundary (`zend_throw_error`). Linux PHP 8.3/8.5 and macOS PHP 8.5 reject
all five changed-hunk mutants with zero survivors, unviable builds or timeouts,
recorded under `target/vendor-patch-mutation-contract-linux83` and
`target/vendor-patch-mutation-contract85` and
`target/vendor-patch-mutation-contract-linux85`. These test-only dependency transports
do not add production fault switches. Natural-OOM survivor results remain
separate evidence, rather than being relabeled.

## Closure cache identity lifecycle

A native probe confirmed an opcode-address reuse defect: 20 sequential eval
closures with different bodies produced two ten-call rows on PHP 8.5, and a
second diagnostic observer logged the same two opcode addresses alternating
while runtime caches were fresh. PHP 8.3 reproduced the same reuse. The
replacement uses `zend_get_op_array_extension_handle` during MINIT and
`ZEND_OP_ARRAY_EXTENSION` only on an executing real user closure with an
initialized runtime cache. It never writes the op-array's reserved/immutable
fields or OPcache shared memory. The opaque cache word is read/written with
`memcpy`; a compile-time check requires a 64-bit PHP cache word.

The API documents that cache extension storage is request-initialized before a
function's first call. Closure construction shares an existing cache when
compatible, or allocates and zeros a fresh scope-specific cache. A global
saturating atomic sequence is deliberately never reset: even a retained cache
cannot collide with a subsequently assigned ID in that process. Binding-related
new caches identify separate callable/cache lifetimes, not a promise to group
all bindings by one source site. The stored ID is never serialized as an address
or exposed as the report's encounter-order label.

The additional word costs eight Zend-managed bytes per user-function runtime
cache; PHP 8.3 shares this extension layout with internal-function caches too.
Zend allocates/owns those caches under its memory-manager policy; this overhead is
outside the first-party Rust capacity quota and may exist while capture is off.
No identity is assigned and no atomic sequence operation runs on the disabled
callback path. UID zero, unavailable storage, invalid registration or exhaustion
never falls back to a recycled pointer/raw name. Registration failure rejects
module startup; runtime identity failure marks capture incomplete and leaves
PHP execution behavior intact.

Primary sources: [PHP 8.3 public cache-extension API](https://github.com/php/php-src/blob/PHP-8.3/Zend/zend_extensions.c#L252-L277),
[PHP 8.3 closure cache construction](https://github.com/php/php-src/blob/PHP-8.3/Zend/zend_closures.c#L706-L731),
[PHP 8.5 closure cache construction](https://github.com/php/php-src/blob/PHP-8.5/Zend/zend_closures.c#L736-L761),
and [request-map pointer access](https://github.com/php/php-src/blob/PHP-8.3/Zend/zend_map_ptr.h).


The [source-qualified cache lifetime integration receipts](closure-identity-validation.md)
cover real shared OPcache code across four ZTS request threads, and repeated
requests through one NTS FPM worker with actual JIT/preloaded functions. Their
artifact scope is explicit; they are not substitutes for final rebuilt matrix
or quality-gate results.

## Wall-timer sampler boundary

`crates/php-profiler/src/sampling.rs` isolates the native sampling ABI. Compile-time
size checks cover status, tick, frame and observation layouts. Nullable descriptor
pointers, counts and borrowed UTF-8/name bytes are validated before slices are
constructed; their lifetime ends with the synchronous PHP-thread callback. The
core stores sanitized, owned identities and numeric frame indices. No executor
pointer is serialized or dereferenced later. A contained callback panic stops
optional sampling without unwinding into C or marking independent graph facts
as measured samples.

`sampling.c` alone owns the worker and its opaque Zend interrupt atomic. The
worker accesses its fixed control, atomics, clocks and condition variable; it
never walks Zend frames or enters PHP/Rust. Execution and delegated-generator
nodes are walked read-only on the PHP thread with a shared 64-step budget. An
unreachable near end yields a truncated known prefix rather than guessed edges.
The interrupt hook invokes the prior handler only after Rust callback owners
have been dropped. The atomic interest gate prevents sampler clock/mutex/frame
work when no sampled owner remains.

Actual successful `pthread_join` precedes control/executor retirement. Request
startup also retires an abandoned worker; TSRM fallback and module shutdown cover
skipped request shutdown and DSO unload. Atfork registration is monotonic across
module cycles in one loaded DSO, and fork termination remains sticky until the
next request. Supported worker signal attributes never alter the PHP thread's
mask. Unsupported platforms compile inert stubs. The fixed controller and native
worker stack are a separate bounded resource domain from Rust requested-capacity
accounting; neither is described as a total process RSS bound. Fault, fork,
threaded executor and actual unload evidence and its scope are documented in
[sampling validation](sampling-validation.md). Fresh whole-source quality scores
must be taken from [validation](validation.md), not inferred from this inventory.
