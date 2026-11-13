# Core requested-capacity budget

`Budget` shares one request quota between allocation owners. Admission reserves
bytes before a fallible allocation. A reservation stays with its storage and
releases its charge when that storage is freed. The counter includes its pinned
`triomphe` control allocation. The default is 64 MiB. This is an accounting limit
on first-party collection, capture, report, serialization and path-buffer
capacities. It is not a total native-memory or process RSS limit. Fixed framework
error transport, standard-library I/O scratch, allocator bookkeeping, fixed TLS
and stacks, and returned PHP values are outside this byte ledger. The adapter and output paths now use the same request owner. Their native
execution and standard-library scratch ownership require separate verification;
core-only tests do not establish a total process-memory claim.

Capture uses seven fallibly allocated `BudgetVec` pools. Their charge is exactly
`capacity * size_of::<T>()` for each pool, with checked multiplication:

- symbols contain a 1,024-byte inline UTF-8 name and aggregate metrics;
- edges contain symbol indices and metrics;
- frame slots contain a live frame or a vacant-slot link, with parent indices into the shared pool;
- suspended contexts contain an ID, frame-chain head and skipped depth;
- optional timeline records contain symbol indices and scalar measurements;
- a symbol lookup index contains fingerprints and stable symbol-record indices;
- an edge lookup index contains fingerprints and stable edge-record indices.

Vacant frame slots form an intrusive free chain. Admission removes its head in
constant time; closing a frame returns that slot to the chain. No separate free-
index allocation exists. On the tested 64-bit layout, the slot enum remains
160 bytes, exactly the previous `Option<Frame>` size, so all seven capture-pool
charges remain unchanged. The engine carries one additional fixed `Option<usize>`
head. Real adapter session-table capacity is still charged from its actual type
size; it is not assumed to retain an earlier compiler layout.

All contexts share the frame pool. Switching fibers cannot retain historical
per-context vector capacity. Symbol, edge, frame, context and timeline capacity
limits are independent; repeated calls to existing graph records consume no
additional storage. Arithmetic diagnostics use an inline fixed registry. Calls,
exits, exceptions, fiber switching/destruction, operation classification and
arithmetic-error recording allocate no native heap storage. A separate allocator
integration executable measures this directly.

`Engine::try_with_timeline` returns admission/allocation errors. Finite capture
capacity exhaustion skips balanced subtrees or timeline entries, preserves
already recorded aggregates, and reports truncation. Public Rust convenience
constructors retain their panic-on-error contract; the PHP adapter must use the
fallible constructor.

`Engine::try_finish(&mut self, ...)` finalizes measurements once and then builds
the report using fallibly allocated maps, vectors and copied text. The report
owns independent reservations; capture and destination overlap are charged
simultaneously. Known report vocabulary borrows static strings. Top-20 ranking
uses stack indices. On failure, partial output storage releases its quota and
the engine retains the finalized graph and original stop snapshot for a retry.
Further instrumentation boundaries leave that finalized capture unchanged.
`Engine::is_recording()` is false after finalization, including allocation
failure, so retaining retryable data does not keep observer collection enabled.

`BudgetVec` charges its backing allocation; nested owned payloads must carry
their own reservations. Profiler records use inline fields or these ownership
wrappers. `BudgetVec` keeps a logical capacity even for zero-sized elements, refuses pushes
and byte extensions beyond it, and does not expose vector growth. `BudgetMap`
serializes sorted entries as the same JSON objects. `BudgetText` charges copied
UTF-8 bytes and borrows static vocabulary. These ownership types deliberately do
not provide infallible cloning. PHP report fields and schema remain unchanged;
the internal/public Rust container and JSON buffer return types have changed.

The allocation-failure integration executable returns null from the real global
allocator at individual budget-control, capture-pool and report construction
allocations. It verifies errors, exact charge release, retained measurements and
retry snapshots without exhausting host RAM. The JSON probe also checks one
charged output allocation, the trailing Zend-parser NUL and release after use.
These tests complement quota-boundary tests; they do not replace the full PHP
matrix or prove coverage of unrelated interpreter/dependency allocations.

The large-profile integration test holds sixteen 512-record sessions and all
materialized reports with 1,024-byte symbols, plus an explicitly allocated and
charged 16 MiB sibling buffer. It checks successful output after a deliberate
quota refusal and release, with peak accounting bounded by the shared 64 MiB
limit. The sibling buffer is test data, not an estimated adapter layout.

## Indexed lookup and measured tradeoff

Symbols and edges stay in append-only typed pools. Each lookup index admits a
power-of-two slot table with at least twice the record limit, so normal load is
at most one half. Probing is bounded even for a full table. `DefaultHasher` uses
stack state; full record keys are checked when fingerprints match. Fingerprints
are lookup hints and never merge distinct profile identities. No dependency,
unsafe implementation or capture-time allocation was added. Existing frame
records retain their established edge index for direct exit aggregation.

Both dictionaries use one shared policy: linear lookup for up to eight records,
indexed lookup afterward. Every new record is indexed immediately, so crossing
the threshold requires no rebuild. At the default 512-record limit, the two slot
tables add 32,768 requested bytes per engine on the tested 64-bit layout. Charges
come from actual typed capacities and `size_of`, including this extra storage;
all sixteen-session, quota-refusal and allocator-ledger checks remain enforced.

The source/binary-qualified comparison in `target/core-focused-bench` uses the
same release driver with archived baseline and indexed core code. Nine focused
pairs time one million calls per case for one and sixteen symbols; three broad
pairs time 200,000 calls for one, sixteen, 128 and 512 symbols, using 13- and
64-byte names. Variant and case order are randomized per pair. Admission,
dictionary priming, report construction and count checks are outside each timed
sample. Every capture is checked as complete with exact expected counts.

The accepted 33.26-second run held this project's heavy jobs, but unrelated host
workloads remained active. It is not a globally idle or isolated CPU result. At
512 symbols, paired median speedups were 38.52x for short names and 29.05x for
64-byte names; every broad paired indexed/baseline ratio was between 0.025 and
0.039. One-symbol focused paired medians were 6.28% and 1.09% slower; broad medians
were 2.54% and 6.46% slower. The broad variant medians differed by 2.4–3.4 ns.
Focused ratios
were noisy, so these are observed tradeoffs, not universal overhead guarantees.
These core-only results do not measure PHP, Zend or disabled-extension overhead.
The earlier comparison remains unchanged and separately annotated as potentially
loaded because a release rebuild overlapped it.

## Frame-depth lookup tradeoff

The original vacant-frame scan was a measured depth-dependent bottleneck. An
initial thread-CPU diagnostic with explicit measurement clocks and exact complete
call counts measured 20.47 ns per leaf call at depth zero and 5,559.69 ns beneath
8,191 live ancestors. Admission, ancestor setup and report finalization were
outside the timed region. These are core-only measurements under concurrent
host activity, not PHP or disabled-extension measurements.

The accepted same-binary comparison keeps both source versions, shuffles case
and variant order, and verifies every count. Seven paired rounds of 100,000 calls
at each depth measured a new/prior median ratio of 0.006145 at depth 8,191,
roughly a 163-fold improvement; every pair was below 0.00785. New-path CPU time
remained approximately 67–70 ns at depths 8 through 8,191 in that run. A longer
fifteen-pair check of three million calls found a root-depth tradeoff: paired
median ratio 1.13139, with variant medians 40.92 ns before and 45.24 ns after.
Depth eight's paired median ratio was 1.00197 with noisy individual pairs.
The free chain is therefore not universally faster: it exchanges roughly four
observed root-call nanoseconds for constant-time frame admission. Whole-PHP
instrumentation overhead requires a separate benchmark.

This project's compiler/test jobs paused during the accepted timings; unrelated
host activity remained uncontrolled. Raw samples, immutable binaries, input
fingerprints and qualifications are retained in `target/frame-depth-probe`.
A failed early paired-build attempt ran the old binary; its missing variant
field and incorrect sample count were detected, and that diagnostic is retained
separately rather than counted as a successful comparison.

The free-chain focused checks passed 75 unit tests and three allocation/overlap
integration tests. They cover zero/full capacity, repeated slot reuse, discarded
entries, partial exception unwinds, fiber holes/destruction, corrupted-chain
rejection, fallible allocation and sixteen-session quota overlap. The real
8,192-deep, maximum-symbol timeline fixture also completed under the decorated
64 MiB quota after this change. Final source-qualified coverage, mutation and
compatibility refreshes remain separate gates; the older checkpoint below does
not certify this newer source.

## Requested-capacity proof and toolchain scope

The stable Rust 1.99.1 standard library used for the allocator-ledger runs has
`Global::alloc_impl` return the requested layout size, and `RawVecInner` retains
the exact requested capacity for an initial `try_reserve_exact`. A system allocator
may retain additional private slack; it is outside the requested-capacity ledger.
The implementation stores its logical limit separately, including for zero-sized
elements, and exposes no collection growth after admission. The ledger tests
compare real `GlobalAlloc` layout requests with live reservations, rather than
assuming process-memory growth equals a vector's logical length. Toolchain updates
must keep these tests passing; the proof does not authorize arbitrary custom Rust
allocators or a total-memory claim.

The pinned `triomphe` 0.1.17 control allocation uses a reference count followed by
the budget payload. `Budget::control_bytes()` computes its padded layout; a unit
test checks the actual payload offset, and the allocator integration checks the
real request size. `Arc::try_new` makes this initial allocation fallible on stable
Rust, without introducing a handwritten reference-count implementation.

## Larger opt-in profiles

The PHP API accepts `max_events` from 1 through 8,192; the default remains 512.
This is the capacity of each capture structure, rather than a limit on repeated
calls to existing graph records. Timeline storage is optional and independently
bounded. The same 64 MiB request ledger still governs admission and overlapping
captures, report materialization, JSON buffers and output-path storage. Sixteen
session slots do not guarantee that sixteen maximum-capacity captures or reports
fit concurrently. Quota refusal preserves earlier captures; a finalized engine
can retry report materialization after unrelated storage is released.

The source-qualified assessment in `target/core-capacity-probe` checked 32
combinations: 512, 2,048, 4,096 and 8,192 records; 64- and 1,024-byte symbols;
flat and deeply nested calls; and timeline capture enabled or disabled. All
captures were complete with exact function, edge and call counts. Flat captures
without timelines repeated every symbol three times to verify aggregation beyond
the logical record capacity. Every materialized report was written atomically
to a real local file. Input strings and diagnostic output belonged to the probe,
not the profiler ledger.

The probe admitted the actual adapter storage implementation's two identity
pools and two generated-name pools. It also charged a byte-equivalent 180,992-byte
buffer for sixteen session slots, using the compiler-measured 11,312-byte
`SessionRecord` layout from `target/layout-assessment/type-sizes.log`. Those
allocations and the actual budget control totalled 17,482,528 requested bytes.
This accounting does not estimate map-node overhead or substitute process RSS
for allocation capacity.

At 8,192 records, capture pools used 11,665,408 bytes without timelines and
12,517,376 bytes with timelines. The flat 64-byte-name timeline case peaked at
40,655,029 charged bytes, including the accessor buffer for 5,786,892 JSON bytes and its trailing NUL. The
deep 1,024-byte-name timeline case retained 36,794,257 additional report bytes and
peaked at 66,794,239 bytes while publishing its 37,875,248-byte file. That left
314,625 bytes under the quota, before actual extension metadata decoration and
configured-path storage. These exact probe totals describe the tested layout and
inputs; they are not a general guarantee for additional concurrent sessions.

The dedicated real-PHP fixture `tests/native/large-profile-boundary.php` then
verified the decorated extension path on PHP 8.5.12 ZTS. It defined 8,192 methods
in class `P`, each with a 1,021-byte name, producing 1,024-byte displayed symbols.
One deeply nested call returned exactly 8,192. The complete report retained
8,192 functions, edges and timeline events, exact parentage and matching measured
metrics, with no dropped calls, dropped events or incomplete returns. Atomic
publication produced a 38,413,931-byte JSON file without temporary-file residue.
Both PHP accessors threw the documented typed size refusal while preserving the
report path and file. This proves that this actual decorated workload completed
under the configured request ledger; no debug counter was read, so its exact
decorated peak is not reported. It is one native compatibility cell, not evidence
for every PHP version, SAPI or platform.

Atomic file serialization coalesces tokens in a fixed 8 KiB stack buffer. It
adds no heap allocation or requested-capacity reservation; this explicit stack
allowance belongs to the already excluded bounded stack domain. The writer drains
with `Write::write_all`, preserving short-write, interrupted-write and zero-write
handling. It flushes the final partial buffer and the underlying writer before
file synchronization and publication. Failed streams are discarded without an
implicit destructor flush or retry of partially written data. A concrete
100-symbol report retained identical 53,919 JSON bytes while reducing underlying
writer calls from 12,881 to 7; this counts writes, not elapsed-time performance.
The focused tests also exercise write-zero failure at a full-buffer drain and
at the final partial drain, final flush failure and a real read-only file error.

The PHP JSON and structured-data accessors independently limit JSON content to
16 MiB, including when enough native quota remains. Larger profiles use the local
streamed report file. Streaming avoids allocating the complete JSON text, but
still requires the bounded report DTO to coexist with capture storage during
finalization. The two generated-name identity tables and frame/context tables
also retain their independent 8,192-entry limits. Increasing graph capacity does
not make those tables or timeline retention unbounded.

Raw probe results, source/binary fingerprints and the native before/result/log
receipts remain in `target/core-capacity-probe`. The real native receipt records
extension SHA-256
`40d09444d111e8275c15cbf53ab55fd7648a73012c2d32aedc32ef2f246fc62f`
and confirms unchanged inputs throughout that run. The heavyweight boundary
fixture is separate from repeated mutation-test invocations.

## Local validation checkpoint

The earlier indexed core checkpoint, before the intrusive free chain, passed 70 unit tests and three allocation/large-
profile integration tests. Fresh isolated nightly instrumentation measured
2,686/2,686 lines, 245/245 functions and 186/186 branches across all five production
modules; every file individually passed the strict checker without exclusions.
The separate region measure is 4,167/4,182 (99.64%). Raw coverage and matching
input fingerprints are retained in `target/core-cov-index-verified/run-acUF7jmO`.

The full cargo-mutants/nextest run generated 346 mutants: 297 caught, 49
compiler-rejected, no survivors or timeouts. All 297 viable mutants were caught;
rejected constructors/trait substitutions are not reported as caught. The
rejections have compiler diagnostics E0277 (35), E0599 (8), E0308 (2), E0790 (1)
and E0271 (3). Raw outcomes and unchanged before/after source fingerprints are
retained in `target/mutation-core-index-verified`. Nextest runs all baseline tests
and uses immediate failure termination so an assertion failure does not wait for
another mutated test's nontermination. Earlier diagnostic failures and pre-index
results remain separate artifacts and are not relabeled as passes.

These are local core results, not a claim that remote CI or every extension
compatibility cell has run this snapshot. The allocator ledger covers the stated
requested-capacity domain, not total native memory or RSS.
