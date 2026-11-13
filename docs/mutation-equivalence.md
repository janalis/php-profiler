# Audited equivalent mutations

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
