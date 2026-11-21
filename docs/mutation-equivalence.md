# Audited equivalent mutations

The narrative proof sections retain their historical source coordinates. The
[current coordinate translation](#2026-11-13-formatting-renewal) and the exact
ledger identify the renewed source.

Raw tool outcomes are never rewritten. A generated mutant may be classified as
behaviorally equivalent only through an exact, reviewed ledger entry tied to its
source file hash, mutator, source span and replacement. A changed file, location,
replacement or additional survivor invalidates the gate. Meaningful source is
never excluded from mutation discovery.

## C closure UID allocator: initial CAS guess replaced with 42

The observed Mull mutation is `cxx_init_const`, `bridge.c` line 47, columns 14–22,
replacement `42`. In the audited Clang 19/Mull 0.34.2 clone, the original relaxed atomic
load still executes, and the initial store to `previous` becomes 42; it leaves the saturating guard, compare/exchange loop, desired value,
retry condition and return unchanged.

C compare/exchange updates the expected argument from the current atomic value
on failure, and a weak operation may also fail spuriously. These are standard
semantics, not a test transport assumption. See the WG14 C11 committee draft,
[§7.17.7.4 paragraphs 2 and 4](https://www.open-std.org/jtc1/sc22/wg14/www/docs/n1570.pdf#page=301).

For this specific allocator:

1. If the current value equals 42, a successful comparison increments it to 43
   and returns 43. The original algorithm permits exactly that transition.
2. If it differs, the comparison does not change the atomic counter and updates
   `previous` to the actual value. The next iteration is an ordinary iteration of
   the original algorithm. This includes a concurrently advanced value.
3. If the counter has reached `UINT64_MAX`, the failed comparison updates
   `previous` to that value; the next guard returns zero without changing it.
4. Spurious failures preserve the same retry semantics. Neither version promises
   a bounded retry count under arbitrary contention or spurious failure.

Consequently both versions produce the same permitted monotonic, unique,
saturating allocation transitions and retain the same existing cache identities.
The mutant may perform an extra failed relaxed comparison before learning the
counter. That changes retry cost, not the allocator's observable identity contract.
A timing threshold would not provide a reliable correctness distinction.

The original initial load avoids that unnecessary comparison. The production
algorithm is retained rather than redesigned to make this equivalent candidate
fail. Boundary, spurious-retry, real cache-lifetime and four-pthread uniqueness
tests remain mandatory for both ordinary and mutated builds.

The raw Mull result remains `Survived`. Reports distinguish raw killed/generated
counts from killed/non-equivalent counts. This classification does not excuse
other survivors or tool timeouts.

## Configuration and consumer guards

Compiler-specific artifacts require schema 2 ledger entries. Each entry pins exact
source span/replacement, source and consumer hashes, proof bytes, and an audited
configuration. Missing or mismatched configuration grants no classification.
Compiler binary/version, Mull version/plugin, actual native compile-command tokens,
PHP version/ZTS/word size, target, Rust flags and loaded libc are recorded before
execution. Only the irrelevant `-o` object-output path is removed from compile
commands. A future compiler, consumer or native configuration requires a new audit.
Raw Elements outcomes are retained unchanged, including `Survived` for equivalents.

## NTS deferred-bailout publication with an empty publisher

The removed call at bridge.c 39:5–25 is an empty inline void function in the NTS
preprocessor branch. Its source body reads/writes nothing. The private compiler
entry/exit instrumentation ignores this function because it has no sampler
domain role, so those hooks also change no supported state. The subsequent
pending-bailout publication is unchanged. This proof is NTS-only: the
ZTS publisher performs an atomic store and is outside this classification.

## Actual noncanonical Boolean stores

The audited Clang 19.1.1/Mull 0.34.2 x86-64 O0 machine mutants store byte 42 where
the original stored byte 0. This is a tool-generated noncanonical Boolean
representation. It is **not** a C-source equivalence claim about `bool x = 42`.
The reviewed actual consumers mask bit 0 before branching or returning an integer;
42 and 0 therefore produce the same false value in that exact compiled closure.

The bridge sites have separate proofs:

- 78:64–65 changes clock_failed; its sole getter masks bit 0 then returns int 0.
  Genuine later failures still store true normally.
- 124:43–44 changes capture_active at the existing false publication. Every
  begin/end/Fiber and private native guard/UID reader masks bit 0. Later normal
  true/false publications are unchanged.
- 154:73–74 clears pending_bailout immediately before the same Zend bailout.
  Every subsequent pending-flag reader masks bit 0; the same bailout occurs.
- 234:19–26 initializes the local volatile allowed flag. Normal execution replaces
  it with the basedir result. The catch/return path also masks bit 0. Its local
  address never escapes.
- 78:104–105 clears pending_bailout during clock reset. The final NTS and ZTS
  emitted stores and supported logical consumers use the same bit-0 normalization.
  The NTS context was reviewed independently after the corrected full run.

No supported consumer copies or compares the raw flag object, exposes its address,
or binds its byte layout in Rust. Actual original and mutant ELF disassemblies,
source-reader enumeration, private guard readers and dynamic-symbol checks are
retained with hashes. These scoped proofs do not classify neighboring sites or
true-to-42 mutations, and do not reclassify any raw killed outcome.

The original 373-candidate NTS run reported bridge 78:104 killed, while the
375-candidate ZTS run reported it survived. That initial ledger deliberately
classified only the observed ZTS survivor. The corrected, complete 392-candidate
NTS run reports it survived. Independent review of that run's actual NTS mutant
store, ELF/IR logical readers, compiler context and dependency hashes establishes
the same bit-0 proof for NTS. The revised ledger adds only that exact NTS context;
it does not relabel the earlier killed outcome or explain its cause.

## Resolver nonzero success value

The bridge.c 250:80–81 mutation changes successful integer 1 to 42. Resolved bytes,
capacity checks, failure 0 and bailout handling are unchanged. All supported Rust
and C consumers test zero/nonzero or forward the result to such a consumer; no raw
integer is exposed through PHP. There is no canonical-1 API promise. The private
malformed-buffer provider has its own independent return 1 and does not impose
that value on the genuine resolver. Exact consumer hashes guard this proof.

## Unsigned ring arithmetic

The sampler's fixed unsigned ring uses `(head + 64 - tail) % 64`. Replacing that
first addition with subtraction changes the mathematical intermediate by 128.
Both 128 and the unsigned 32-bit wrap modulus are divisible by 64, so the final
remainder is identical for every representable head/tail pair. This proof applies
only to the exact unsigned types, fixed capacity 64 and this single operator. It
does not apply to the other arithmetic, remainder or queue mutations.

## Sampler false publications: worker and request lifetime

The sampler's 126:29–30 worker_live=false and 312:136–137 request_active=false
mutants are separate actual noncanonical-byte artifacts. The audited actual
Clang 19/Mull machine clones store byte 42; every supported reader masks bit 0.
The original false publication and the mutant therefore both prevent a further
join of an already joined worker or sampling after request retirement. New actual
worker creation and request initialization still publish their ordinary values.
The original and array-bounds-instrumented mutant/consumer ELF disassemblies are
retained separately. These proofs require the exact compiled consumer closure;
they do not apply to true publications or arbitrary compilers/optimization levels.

## Pinned glibc condition-attribute destructor

The exact 159:5–42 removed pthread_condattr_destroy call has no observable effect
with the audited glibc binary. Its actual implementation is endbr64, xor eax,eax,
ret: it returns zero without reading/writing the attribute or owning/releasing
resources. The production caller discards that return value. The loaded libc
bytes are pinned in the classification context (audited build ID
`a4a7992a8e66555c8141ab2a08a8465ff6e0ea65`). This is not a portable pthread API
claim. The ordinary production destroy call remains. In particular,
pthread_attr_destroy owns an allocated signal-mask extension here and is **not**
covered by this proof; its omitted-call mutation is killed by a real resource
retirement test.

## Nonzero stack truncation and unused frame classification

The separate sampler 209/211/219/250 mutations replace truncation integer 1 with
42. Supported production consumers convert stack_truncated using `!= 0`; the
private generator-marker accessor converts it to a PHP Boolean. Both values mean
true. No accepted report or public PHP integer exposes the original number.
The existing blank-generator test's exact-1 assertion remains mandatory. Actual
NTS and ZTS Zend-created marker witnesses show func=NULL and This type=IS_UNDEF,
reaching the unchanged 204 branch. This is a consumer-closure proof, not a new canonical-1
promise or permission to weaken that test.

The two sampler 243 mutations change only Frame.internal (equality inversion or
assignment 42). The currently supported Rust frame consumer does not read that
field; it uses name, scope and opaque closure identity. There is no serialization, raw-byte comparison, or supported external/private
test consumer of the field. Layout remains 48 bytes and production code is retained. A future
consumer invalidates the exact dependency-hash proof.

## Unavailable service initializer

At sampler 233:22–29, initial service 0 becomes 42. A successful monotonic read
overwrites either initializer. Only a failed/unrepresentable service clock can
leave a different initializer, and that same clock error is disclosed in both
versions. An observation with no accepted wakes returns before using service.
Every genuine queued tick has due = now + 10,000,000 ns (checked against overflow)
and wake >= due; therefore every accepted wake is at least 10,000,000 ns. Both 0
and 42 are strictly before the last wake. The Rust sampler consequently increments
the same invalid-observation/drop counters and returns before changing last_service,
recording a sample or consuming the lazy symbol iterator. No raw rejected service
value appears in JSON. This proof pins the period, producer invariants, adapter
and core consumer; it does not assert that 42 is a measured timestamp or relax any
clock-error requirement.

## Overwritten initial truncation output

The sampler 200:39–40 initializer writes 42 instead of 0 through the local
truncation output pointer. Every return path subsequently overwrites that output:
the four early returns assign 1 and the final exit assigns frame != NULL. There
is no output read or pointer escape before the required overwrite. Actual
production and private callers provide separate local output and frame arrays,
so aliasing cannot expose the initial store. Private function instrumentation
receives only function/caller code addresses; its entry precedes the initial
store and its exit follows the final store. It cannot observe the intermediate
output value. Every final output is identical. This does not cover walked's
separate, meaningful initializer.

This proof initially covered the observed ZTS survivor. The complete corrected
NTS run also reports this exact initializer survived. Its final NTS LLVM store
of integer 42, actual ELF, unchanged static walker, non-aliasing callers and
native context were independently reviewed before extending the ledger to NTS.

## Sampler registration false publication

Sampler 290:15–16 stores byte 42 instead of false during shutdown. Actual NTS and ZTS mutant/reader disassemblies and emitted IR show that every
registered reader, including private lifecycle guards, masks bit 0. The emitted-code audit also includes the
native join-error guard introduced by the current private transport. It does not apply to the true publication at 279:
that mutation can register duplicate atfork callbacks and is genuinely caught by
the two-cycle, actual-registration-count fixture. The classification pins that separately retained actual mutant/reader closure.

## Retry-yield: conditional valid-native unreachable arm

The restored sampler 124:13–26 mutation omits sched_yield after a failed join.
Its raw targeted result remains Survived. An independent ownership and pinned-libc
audit establishes a narrower equivalence: the retry body is unreachable for a
valid privately owned joinable worker on the audited libc with a conforming Linux
syscall implementation. This is not an unconditional scheduling-equivalence claim.

The creator uses default JOINABLE attributes, never detaches the worker, and
publishes worker_live only after successful creation. All start/stop/destructor/
atfork joiners serialize ownership through slots_lock. The worker neither joins
nor waits on its owner/registry, and its handle/control are retained until a real
successful join. Fork preparation joins workers before creating the child. Thus
valid operation has a distinct, still-valid joinable handle, one joiner and no
join dependency cycle.

The pinned glibc 2.39 binary passes NULL timeout and blocking=true to join. Its
invalid/detached/self/cycle error preconditions are excluded by those ownership
invariants. The actual waiter compare-exchange jumps to EINVAL on a successful
comparison; failure does not manufacture that error. The untimed futex path has
no timeout conversion/expiry. Its only propagated wait errors are timeout and
overflow, which cannot arise from the conforming NULL-timeout operation. Other
ordinary wait results retry inside libc; tid zero produces successful join.
Cancellation unwinds instead of returning a nonzero result. These conclusions
use the actual NTS/ZTS-identical libc bytes, not just an unused return value.

The normative [POSIX join contract](https://man7.org/linux/man-pages/man3/pthread_join.3p.html)
forbids EINTR; [Linux join documentation](https://man7.org/linux/man-pages/man3/pthread_join.3.html)
describes the invalid-handle/joinability/dependency errors, and
[Linux futex documentation](https://man7.org/linux/man-pages/man2/FUTEX_WAIT_BITSET.2const.html)
describes the absent timeout expiry with NULL. The classification assumes that
native syscall contract, not a verified copy of the running kernel source.

Injected errors deliberately returned for an otherwise valid handle, corrupt
native state, external detach/join/cancellation interference, invalid concurrent
PHP lifecycle, and manufactured seccomp/interposer errors are outside this proof.
Yielding can affect scheduling/CPU behavior there; no equivalence is claimed for
those effects. The production defensive call and all fault/recovery assertions
remain unchanged. Raw Survived is retained, with the exact source/caller/libc/
compiler/configuration hashes and explicit domain assumptions in its ledger entry.


## NTS context extension and unchanged raw evidence

The corrected full NTS collector generated all 392 ordinary-production identities
and recorded raw 374 Killed / 18 Survived. Its original strict gate failed because
the two NTS survivors above had only ZTS ledger contexts. That failed execution,
its immutable 330-input snapshot, raw report and source manifests remain intact.
The extension changes only the two existing context lists: the union remains 22
exact entries, applicable to 22 NTS sites and 21 ZTS sites.

Four previously listed NTS equivalents were reported raw Killed in that run.
The Elements report contains no per-test failure diagnostics establishing why.
No outcome is rewritten and no claim about flakiness or causation is made.
Classifier totals adjust only surviving audited equivalents; they do not pretend
that every known-equivalent raw Killed outcome was removed from the denominator.

A subsequent classification replay must use separately frozen audited metadata
and preserve the original failed full-run result. It may validate unchanged raw
reports, native contexts and exact runtime/consumer dependencies; it must not
represent a new mutation execution or claim that all raw mutants were killed.

## 2026-11-13 formatting renewal

The existing 22 entries were independently re-reviewed after first-party native
formatting and the extension registration name change to `php_profiler`.
Classification, replacement expressions, proof anchors, and ABI scopes remain
unchanged. The table records exact start/end coordinates, not approximate lines.

| Source | Mutator | Historical span | Renewed span | Replacement |
| --- | --- | --- | --- | --- |
| `bridge.c` | `cxx_assign_const` | 78:64–78:65 | 89:33–89:34 | `= 42;` |
| `bridge.c` | `cxx_assign_const` | 78:104–78:105 | 90:36–90:37 | `= 42;` |
| `bridge.c` | `cxx_assign_const` | 124:43–124:44 | 146:43–146:44 | `= 42;` |
| `bridge.c` | `cxx_assign_const` | 154:73–154:74 | 180:40–180:41 | `= 42;` |
| `bridge.c` | `cxx_assign_const` | 250:80–250:81 | 295:25–295:26 | `= 42;` |
| `bridge.c` | `cxx_init_const` | 47:14–47:22 | 50:14–50:22 | `42` |
| `bridge.c` | `cxx_init_const` | 234:19–234:26 | 279:19–279:26 | `42` |
| `bridge.c` | `cxx_remove_void_call` | 39:5–39:25 | 42:5–42:25 | `` |
| `sampling.c` | `cxx_add_to_sub` | 178:23–178:24 | 247:52–247:53 | `-` |
| `sampling.c` | `cxx_assign_const` | 126:29–126:30 | 165:30–165:31 | `= 42;` |
| `sampling.c` | `cxx_assign_const` | 200:39–200:40 | 294:16–294:17 | `= 42;` |
| `sampling.c` | `cxx_assign_const` | 209:58–209:59 | 311:28–311:29 | `= 42;` |
| `sampling.c` | `cxx_assign_const` | 211:84–211:85 | 316:28–316:29 | `= 42;` |
| `sampling.c` | `cxx_assign_const` | 219:30–219:31 | 328:24–328:25 | `= 42;` |
| `sampling.c` | `cxx_assign_const` | 243:31–243:32 | 375:32–375:33 | `= 42;` |
| `sampling.c` | `cxx_assign_const` | 250:46–250:47 | 388:35–388:36 | `= 42;` |
| `sampling.c` | `cxx_assign_const` | 290:15–290:16 | 443:16–443:17 | `= 42;` |
| `sampling.c` | `cxx_assign_const` | 312:136–312:137 | 479:29–479:30 | `= 42;` |
| `sampling.c` | `cxx_eq_to_ne` | 243:46–243:48 | 375:49–375:51 | `!=` |
| `sampling.c` | `cxx_init_const` | 233:22–233:29 | 349:22–349:29 | `42` |
| `sampling.c` | `cxx_replace_scalar_call` | 124:13–124:26 | 163:13–163:26 | `42` |
| `sampling.c` | `cxx_replace_scalar_call` | 159:5–159:42 | 219:5–219:42 | `42` |

The complete ordinary inventories map bijectively: 392 NTS and 395 ZTS mutation
identities, with no omitted or added identity. Both production C token streams
and the five changed C fixtures match the prior source after C line splicing,
comment removal, and whitespace removal. Zend declaration macros retain their
original tokens in explicitly guarded formatting blocks.

Fresh actual compiler, plugin, PHP ABI, native command, and libc contexts match
the audited contexts exactly. The emitted DSO hashes used for machine inspection
match those used by the full mutation collectors. Inspection covers 623 NTS and
632 ZTS emitted functions. After debug-location removal and bijective mutation
symbol translation, 620 NTS and 629 ZTS function bodies match. The only three
remaining differences per ABI are Zend's implicit `_zend_bailout` diagnostic line
argument changing from 154 to 181. This diagnostic coordinate changes in the
original and relevant clones; it does not change the Boolean-reader or allocator
proofs. No claim of complete binary identity is made.

Fresh complete raw outcomes are NTS 370 Killed / 22 Survived and ZTS 374 Killed /
21 Survived. Every survivor matches an existing translated entry; there are no
unknown outcomes or timeouts. All raw survivor statuses remain untouched. The
initial collector gates rejected the historical dependency hashes as intended;
renewed acceptance is a separate strict-checker result, not a rewritten collector
receipt. Dependency hashes are renewed only for the reviewed crate metadata,
module registration, formatted native sources/fixtures, and this proof document.

Local source-qualified evidence is retained under
`target/mutation-renewal-audit/`: `completed-review.json`,
`full-span-bijection.json`, `ir-comparison.json`, the raw reports, machine reader
inspection, immutable source manifests, and complete recovered daemon logs.
The authoritative ledger still requires exact sources and contexts. This renewal
does not approve unrelated future source, compiler, dependency, or ABI changes,
and does not describe a raw 100% mutation kill rate.

### Test-only frame-completion regression

A subsequent complete Rust mutation run recorded 680 caught, 259 confirmed
compiler rejections, and one timeout for replacing `Engine::close` with a no-op.
That historical run remains failed. A new unit test checks the completed leaf's
call/time/memory metrics and empty active-frame state immediately after `exit`,
before finalization can drain the stack. Nextest schedules this invariant first;
all other tests still run when it passes. The targeted real mutation is now
caught by an assertion with the original timeout unchanged. A fresh full Rust
mutation run remains mandatory.

The added code is entirely inside the existing `#[cfg(test)]` module. Production
bytes preceding that module are identical. The native C source, machine readers,
source spans, contexts and classifications are unchanged. Independent review
approved refreshing only this core source's dependency hash and this explanatory
proof document; it does not relabel the earlier timeout or approve future edits.

## HTTP request controls: consumer audit renewal

The HTTP-control change appends code after the historically audited 11,832-byte
`bridge.c` prefix (SHA-256
`42c3c4a77c07ee85a4bc7ee795aba1259869735983125c18fa53a3fdb3844d41`).
That prefix and all existing mutation coordinates are byte-identical; the sampler
source, allocator, clocks, flag publications and resolver are unchanged.

The new Rust lifecycle code starts captures through existing admission routines,
freezes sessions before persistence, and consumes bridge flags through the existing
typed FFI getters. It does not expose or inspect their byte representation.
The private automatic fault probes inspect only typed pending-bailout flags and
C return statuses. Each fault runs in an isolated process which exits through C
without resuming the abandoned Zend execution frame; exit handlers persist real
coverage counters. Reviewed Clang 19 NTS and ZTS disassemblies retain bit-0
normalization at the new flag readers. The original noncanonical-byte proofs
still apply only to the pinned compiler/consumer closure.

Three new Linux link wrappers intercept INI registration, auto-global
materialization and SAPI header submission. They inject failures only in private
contracts and otherwise delegate to the original APIs. They do not change the
old prefix's flag/allocator/resolver consumers or sampler ownership, period,
truncation and service-clock invariants. Ordinary production call expressions
remain intact, and the ordinary/private mutation inventory must match exactly.
The contexts add these three fixture symbols; compiler, plugin, libc, flags,
ABI and the two recorded runtime compilation commands remain pinned.

The renewed dependency closure includes the automatic adapter, private dependency
transports and their contracts. The native mutation runner executes the C boundary
contracts before the sampler and broader PHP workloads. Historical reports retain their original source
snapshots and raw statuses. Renewal grants no classification to additional
mutations unless their exact span, replacement and proof appear in the ledger.

### Automatic server materialization nonzero success

The exact `cxx_assign_const` mutation at `bridge.c` 318:17–18 replaces the
successful local integer assignment `1` with `42`. It retains the original
`zend_is_auto_global_str` call, its side effects, the initial zero, the bailout
catch/deferred-failure publication, and restoration of Zend's bailout pointer.
The local address does not escape. The static helper has one production caller,
which tests only logical zero/nonzero before inspecting the materialized server
array. The isolated private failure probes require zero only on the bailout path. Neither the raw success integer nor its representation reaches PHP/Rust
or a report. Thus 1 and 42 permit the same lookup; failures still return zero.
This proof covers only this successful assignment, not the initializer, API call,
header result or token comparison.

Actual ordinary NTS/ZTS Mull objects from PR #13's initial CI inventory contain
the integer `movl $0x2a` mutant store, an integer return, and a caller comparison
against zero. The exact objects are retained in that run's `c-mutation-nts` and
`c-mutation-ts` artifacts. Updated private NTS/ZTS objects were independently
reviewed for the typed flag readers and the unchanged status consumer. This is
an integer truthiness proof, not an additional noncanonical Boolean exception.
Raw Killed/Survived results remain collector outputs; the strict classifier
subtracts this entry only when this exact candidate is reported Survived.

### Review fixes: secret display, INI keywords and entropy

The review renewal keeps every recorded `bridge.c` coordinate. The appended blank
line before `PHP_INI_BEGIN()` became the static displayer prototype, the trigger
secret entry gained that displayer on its existing line, and the displayer body was
appended after all audited spans. The 318:17–18 materialization assignment and the
historical 11,832-byte prefix are byte-identical; only `source_sha256` values and
the bridge dependency hash changed.

The displayer prints a fixed mask for a nonempty secret and `no value` otherwise.
It reads only the registered entry value, never the SAPI or flag structures, and
does not participate in sampling, admission or report persistence. Its mutants are
not classified by this ledger; PHP integration checks of `php -i` output with a
configured and an unset secret must kill them.

The Rust adapter now accepts PHP's unquoted false keywords (passed as `""`) for
`mode` and the Boolean settings, rejects trigger secrets that no header could match,
uses `getentropy(3)` instead of opening `/dev/urandom`, and reuses the identity
capacity constant. These changes renew the automatic adapter and private contract
hashes only. They add no native mutation classification.

### Review fixes: trigger source, secret bytes and request-scoped logging

This renewal edits `bridge.c` only from line 321 onward, after every recorded
coordinate, so all audited spans and the 318:17–18 materialization assignment are
byte-identical. `profiler_trigger_matches` now refuses a trigger whenever the process
environment defines `HTTP_X_PHP_PROFILER_TRIGGER`, and returns -1 when no trusted
header exists, 0 for a non-matching header and 1 for a match. Its only call to the
materialization helper still tests logical zero/nonzero; a failure now returns -1
instead of 0, which no audited proof inspects.

The Rust adapter reads INI values as exact bytes and validates the trigger secret only
after a header arrives, so CLI requests never log secret errors. Request shutdown no
longer repeats the freeze already performed before automatic finalization. These
changes renew the source and dependency hashes only. They add no native mutation
classification; the private transport contract and the built-in server, phpdbg and
php-cgi integration checks must kill the new trigger mutants.

### Review fixes: environment diagnostics and startup snapshot

This renewal edits `bridge.c` only from line 324 onward, after every recorded
coordinate, so all audited spans are byte-identical. The process environment is read
once by `profiler_trigger_snapshot()` during module startup, before a ZTS request thread
can `putenv()`, instead of on every request. `profiler_trigger_matches` returns -2 when
that startup environment defines `HTTP_X_PHP_PROFILER_TRIGGER`; the Rust adapter logs
that as a configuration error instead of treating it as a missing header. The
adapter also rejects trigger secrets containing a NUL byte. These changes renew the
source and dependency hashes only and add no native mutation classification; the
bridge unit test, the phpdbg and php-cgi environment checks and the built-in server
NUL-secret case must kill the new mutants.

### Review fixes: distrust every SAPI but header-only ones

This renewal again edits `bridge.c` only after every recorded coordinate, so all audited
spans are byte-identical. Module startup records whether PHP inherited
`HTTP_X_PHP_PROFILER_TRIGGER` under a SAPI that is not known to read request headers only
(`fpm-fcgi`, `apache2handler`, `cli-server`, `litespeed`). The record fails closed: any other
SAPI, including unknown ones, refuses every trigger and the bridge logs a dedicated message
once per process through the same bailout-contained logger as the adapter's error message,
returning -1 like a request without a trigger. LiteSpeed's command-line mode, which sets no
request method, never reads a trigger. The SAPI names are matched by one delimited `strstr`,
so the bridge unit test's header-only and near-miss names exercise the whole comparison.
These changes renew the source and dependency hashes only and add no native mutation
classification; the built-in server case with an inherited copy of the secret, the phpdbg
and php-cgi environment checks and the bridge unit test must kill the new mutants.

### Review fixes: no trigger outside a request

`php-fpm -i` runs a request before any pool, while FPM's getenv still falls back to the
process environment. Module startup now also records whether the SAPI is header-only, and
such a SAPI without a request method returns -1 like the CLI, which generalizes the
LiteSpeed command-line rule. The edit is again after every recorded coordinate and renews
the source and dependency hashes only; the bridge unit test checks every header-only SAPI
without a request method and phpdbg without one, and the FPM suite runs `php-fpm -i`.

### Review fixes: headers from _SERVER unless getenv serves them

FrankenPHP's getenv serves only the process environment, so trigger headers never reached
the bridge there. Module startup now also records whether the SAPI's getenv answers
`HTTP_*` from the request (`fpm-fcgi`, `cgi-fcgi`, `apache2handler`, `litespeed`); every
other SAPI reads `_SERVER`, as the built-in server already did. The edit is again after every
recorded coordinate and renews the source and dependency hashes only; the bridge unit test
reads php-cgi through getenv and FrankenPHP through `_SERVER` with a differing getenv value,
and the inherited variable differs from the header in the header-only SAPI checks.

### Review fixes: one SAPI-name matcher, report once

Both SAPI lists now go through one delimited `strstr` helper evaluated at module startup.
The report of an inherited variable stays once per process even when logging bails out:
retrying would abort every request while logging keeps failing. The edit is again after
every recorded coordinate and renews the source and dependency hashes only; the isolated
`inherited-log` bailout mode checks the deferred bailout and that no later request logs.

### Review fixes: FrankenPHP headers from the request alone

FrankenPHP's `_SERVER` starts from the process environment and ends with its configured
`env` values, so neither stands in for a missing header any more. Module startup looks up
`frankenphp_request_headers()` (FrankenPHP 1.3+) under the `frankenphp` SAPI only. When it
exists, FrankenPHP is header-only, and the bridge calls it under the same bailout containment
as `_SERVER` materialization, looks the trigger up under Go's canonical header name and
releases the returned array after the comparison. Without it, FrankenPHP keeps the distrusted `_SERVER`
path. The edit is again after every recorded coordinate and renews the source and dependency
hashes only; the bridge unit test's stand-in header function (match, released result,
non-canonical and longer names, joined values, a missing header, a non-string value, no array, no request method,
phpdbg still refusing) and the isolated `headers` bailout mode must kill the new mutants.

### FrankenPHP worker requests

FrankenPHP's worker mode serves each HTTP request inside the PHP request that runs the worker
script and runs request hooks for FrankenPHP's own modules only. Under the `frankenphp` SAPI,
module startup now registers the internal `PhpProfiler\Internal\frankenphp_request()` callback
and swaps `frankenphp_handle_request()`'s handler for one that hands FrankenPHP that callback,
keeping the application's callback in a thread-local slot that nested calls save and restore.
The callback begins a profiler request, calls the application's callback and ends the request,
also before re-raising a bailout; module shutdown restores the handler. A callback name another
extension already took leaves the handler alone without failing module startup. The unit test
records worker boundaries through the private transports (`--wrap` on Linux), which then run the
adapter's own, so production call sites keep their ordinary mutation inventory; both audited
contexts list the two new `__wrap_profiler_worker_request_*` fixture symbols. A bailout is
re-raised from the catch block itself, and the callback's name is interned, so no flag or
release call exists for a mutant to drop unobserved. The Rust adapter's
request hooks become `begin_request`/`end_request`, and ending a request that is not open does
nothing. The bridge code is appended after every recorded coordinate and renews the source and
dependency hashes only; the bridge unit test's stand-in `frankenphp_handle_request()` (call
order, forwarded argument and result, exception, nested request, missing and non-callable
arguments, direct call, other SAPI, taken callback name, missing function, restored handler), the isolated `worker` bailout mode, the
adapter contract's worker requests past the session capacity, and the FrankenPHP worker
integration checks must kill the new mutants.

### Review fixes: FrankenPHP worker boundaries

`frankenphp_handle_request()`'s handler now ends whatever profiler request is open before it calls
FrankenPHP's own, so the worker script's start and any session started between requests end
before the worker waits; the callback only begins and ends its request. The callback disarms the
thread-local slot it reads, so a direct call from the application's callback is refused instead
of restarting the request. A bailout while reading FrankenPHP's request headers marks the result
undefined, so a half-built array is neither read nor released. The adapter's request end no longer
needs an open-request flag: ending twice finds nothing to freeze or complete and stops an already
stopped sampler. The edits are again after every recorded coordinate and renew the source and
dependency hashes only; the bridge unit test's call logs, its direct-call mode, the released
application callback, FrankenPHP returning or bailing out (isolated `worker-start` mode) without
calling the callback with the slot emptied, the stand-in header
function that bails out after filling the trigger header, and the FrankenPHP worker integration
checks (a session started between requests, the start's wall time after an idle wait) must kill
the new mutants.

## 2026-11-19 test tooling renewal

The C mutation test program and the native fault link moved from Python to PHP. No first-party
source, compile command, fixture symbol, context or equivalent changed: every source hash,
coordinate, replacement and proof anchor is the same. `scripts/c-mutation-tests.sh` now runs
`tests/native/c-boundaries.php` through `scripts/owned-run.php`, which owns the child's process
group exactly as the inline Python did, and calls `scripts/sampling-controller-tests.php` and
`scripts/native-bailout-tests.php`. `scripts/link-native-faults.sh` now compiles and
content-addresses the fault object through `scripts/native-fault-object.php`. The dependency
hashes renew for the two shell scripts. `scripts/native-bailout-tests.py` is replaced by
`scripts/native-bailout-tests.php`. `scripts/owned-run.php` and `scripts/native-fault-object.php`
are added, because they now hold logic that was inline in hashed shell scripts. The NTS and ZTS
C mutation runs must still classify the same 23 and 22 equivalents and kill every other mutant.

The first CI run of this renewal never finished: both C mutation jobs stopped progressing at about
mutant 286 for over three hours. Mull activates each mutant through an environment variable named
after its location, such as `cxx_replace_scalar_call:/source/crates/php-profiler/src/sampling.c:140:5:140:42`.
That switch was lost twice before reaching the PHP children. `scripts/php-tool.sh` ran under
`/bin/sh`, which is dash on Debian and drops variable names that are not shell identifiers. PHP's
`getenv()` without a name also rewrites `.` to `_`. The ported drivers and `scripts/owned-run.php`
therefore tested the unmutated extension. Mutants such as the removed `pthread_mutex_unlock()` at
`sampling.c:140` and `sampling.c:468` reached the unbounded shell steps, deadlocked there, and kept
Mull's output pipe open after its timeout. `scripts/php-tool.sh` now runs under bash, and every
tool reads the inherited environment through `Process::environment()`. Under that fix, both mutants
again fail the sampling controller step by its 30-second timeout, as they did under Python. The
dependency hashes renew for `scripts/native-bailout-tests.php` and `scripts/owned-run.php`, which
now build the children's environment that way.

## 2026-11-19 FrankenPHP calls outside worker mode

`frankenphp_handle_request()`'s handler used to end the open profiler request before calling
FrankenPHP's own, even outside worker mode, where FrankenPHP only refuses the call: the request's
capture ended just before the refusal. The handler now wraps the call only when the request may
belong to a worker. FrankenPHP's getenv hook answers from the worker script's environment from
1.13.4 only, which the handler learns from FrankenPHP's module version when it is installed. There,
a request is a worker's when that hook or `_SERVER` holds `FRANKENPHP_WORKER`, or when `_SERVER`
cannot be read. With an older, unknown or unparsable version, or without the hook, every call
still counts as a worker's. The new code is again after every recorded coordinate, so only the
source and dependency hashes renew. The bridge unit test's version boundaries, its stand-in
`frankenphp` module, its name-aware getenv stand-in (environment, `_SERVER` alone, neither, a
`_SERVER` that is not an array, an older version, no hook), the isolated `worker-materialize`
bailout mode, and the FrankenPHP classic-mode integration check must kill the new mutants.

## 2026-11-20 Signed FrankenPHP versions

`sscanf("%u")` accepts a sign or leading space in each field, so a FrankenPHP version such as
`-1.0.0` parsed as a huge major and counted as 1.13.4 or later. The parser now also requires the
parsed span to hold only digits and dots. The change is again after every recorded coordinate, so
only the source and dependency hashes renew. The bridge unit test's signed and spaced versions
(`-1.0.0`, `v-1.0.0`, ` 1.13.4`, `+1.13.4`, `1.-13.4`, `1.13. 4`) next to the exact `1.13.4`
boundary must kill the new comparison mutants.

## 2026-11-20 FrankenPHP worker wrapper clean-ups

`frankenphp_handle_request()`'s handler now wraps any argument count that FrankenPHP's registered
signature accepts, instead of exactly one. It resolves the application's callback once and calls
it through that cached resolution. Like PHP's own parameter parsing, it releases a `__call` or
`__callStatic` trampoline at once, so the call resolves it again. Whether the SAPI is FrankenPHP is
now read once at startup, by `profiler_trigger_snapshot()`, which `profiler_worker_register()` follows.
The new code is again after every recorded coordinate, so only the source and dependency hashes
renew. The bridge unit test's two- and three-argument calls, its `__callStatic` callback served with
and without FrankenPHP calling back, and its registration under a stale snapshot must kill the new
mutants.

## 2026-11-20 cc 1.11.2

`cc`, which `build.rs` uses to compile the bridge and sampling C sources, moves from 1.11.1 to
1.11.2. Its only fix keeps GCC from merging identical variables under AddressSanitizer; the C
mutation builds use clang, and no first-party source, compile command, fixture symbol, context
or equivalent changed. Only the `Cargo.lock` and documentation dependency hashes renew. The NTS
and ZTS C mutation runs must still classify the same equivalents and kill every other mutant.

## 2026-11-21 ext-php-rs-build 0.2.1

`ext-php-rs-build`, which the vendored `ext-php-rs` build script uses to map PHP's API version to
cfg flags, moves from 0.2.0 to 0.2.1. Its only source change widens the PHP 8.6 match from the
single alpha API number 20260924 to the range up to 20261112, the API of PHP 8.6.0, and adds a
unit test for those bounds. The match arms for PHP 8.3 to 8.5 are unchanged, so the C mutation
runs on PHP 8.3 get the same cfg flags and bindings. No first-party source, compile command,
fixture symbol, context or equivalent changed. Only the `Cargo.lock` and documentation dependency
hashes renew. The NTS and ZTS C mutation runs must still classify the same equivalents and kill
every other mutant.

## 2026-11-21 ext-php-rs 0.16.5

The vendored `ext-php-rs` moves from 0.16.4 to 0.16.5, with `ext-php-rs-derive` 0.12.5 and
`parking_lot` 0.13. The local exception patch carries over unchanged; upstream now fixes the
clone handler the way the local patch did, so that file is upstream source again. The other
upstream changes do not reach the profiler: it reads its options array with `get` and `len`,
never with the iterator whose key handling changed; it declares no `#[\SensitiveParameter]`
parameters, so the module startup skips the new marking; it uses no embed or eval helper; and
`parking_lot` 0.13 only drops `const_rwlock` and `const_mutex` for the now-const constructors.
`bridge.c` and `sampling.c` compile against the PHP headers, not ext-php-rs's wrapper. No
first-party source, compile command, fixture symbol, context or equivalent changed. The
`Cargo.lock`, `crates/php-profiler/Cargo.toml` and documentation dependency hashes renew. The
NTS and ZTS C mutation runs must still classify the same equivalents and kill every other
mutant.
