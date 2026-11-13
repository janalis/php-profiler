# Validation and release gates

Production readiness is **not established**. Verification is local; remote CI
has not run. Disabled tiny-call wall ratios remain 1.3910–1.4481 cold and
1.3773–1.5651 after a stopped/reset capture. Exact default allocation
counts/volume/session peaks and unbiased CPU sampling remain unavailable.
See [measurement limits](capabilities-feasibility.md) and
[measured overhead](observer-overhead.md).

## Current startup-hardened implementation

The frozen snapshot contains 333 canonical inputs:
`target/final-startup-native-source-receipt.json`. All 11 runtime Rust/C files
are unchanged from the previously validated implementation. The hardening changes
private helper startup cleanup, sanitizer diagnostic checks, external-object
relink freshness and their regression tests.

| Gate | Observed local result | Evidence |
| --- | --- | --- |
| Runtime Rust coverage | 5,686/5,686 lines, 535/535 functions, 638/638 branches; unchanged runtime source | `target/rust-mutation-sampling-final/cohesive-hostfix-coverage/run-Pb6Ajr3b/run.json` |
| Fresh NTS C coverage | 398/398 lines, 64/64 functions, 186/186 branches | `target/startup-c-coverage/nts/run-GOlcWZyr/run.json` |
| Fresh ZTS C coverage | 409/409 lines, 66/66 functions, 194/194 branches | `target/startup-c-coverage/zts/run-XIZUe7uU/run.json` |
| Runtime Rust mutation | 940 candidates; all 681 viable candidates caught; 259 confirmed compiler rejections | `target/rust-mutation-sampling-final/nonincremental-replay/accepted-rust-evidence.json` |
| Fresh full NTS C mutation | 392 generated; raw 370 killed/22 survived; 22 context-audited equivalent survivors; adjusted 370/370 | `target/startup-native-validation/nts-result/provenance/verified-receipt.json` |
| Fresh full ZTS C mutation | 395 generated; raw 374 killed/21 survived; 21 context-audited equivalent survivors; adjusted 374/374 | `target/startup-native-validation/result.json` |
| Fresh PHP 8.3/8.4/8.5 NTS/ZTS SDKs | Six cells passed formatting, strict workspace/all-target Clippy and 117 tests with private flags unset; separate private library builds and real PHP probes also passed | `target/startup-sdk-validation/acceptance.json` |
| Ordinary compatibility | Nine CLI/ZTS/FPM full cells passed on byte-identical ordinary binaries; earlier source checkpoint | `target/compatibility-sampling-cohesive/summary.json` |
| Fresh ordinary release identity | Nine candidate builds passed ABI/private exports and binary identity; final native-ledger-only metadata delta qualified | `target/minit-startup-audit/ordinary-release-identity/summary.json` |
| Published JSON schemas | Five genuine native version 1/version 2 reports passed on unchanged ordinary binaries | `target/sampling-schema-cohesive/receipt.json` |
| Fresh macOS private build | PHP 8.5 ZTS private build and four actual probe groups passed | `target/minit-startup-audit/result.json` |
| Performance | Nine cells, 114 workloads, seven retained samples/mode; prior byte-identical ordinary binaries, strict independent analysis | `target/performance-sampling-ownership/final-summary.json` |
| Validation tooling | 86 Python tests, formatting, ShellCheck and Actionlint passed | `target/minit-startup-audit/python-main.log`; `target/startup-native-validation/result.json` |

Raw coverage counters reach 100% for lines, functions and branches in the scoped
first-party runtime registry, without adjusted counters, meaningful exclusions
or combining failed/source-mismatched runs. Third-party dependencies, generated
Zend bindings and build/test tooling are outside that registry. The two vendored
exception-ownership patches have native functional, OOM and sanitizer regression
evidence.

The C gates reach 100% after subtracting surviving audited equivalents only.
Raw outcomes remain unchanged. Compiler, PHP ABI, libc, actual native commands,
fixture symbols and consumer/proof hashes guard each classification. Ordinary
and private-test inventories match exactly, with unique IDs, clean exits,
unchanged source and zero unclassified survivors. The mutex-lock regression is
killed on both ABIs. Earlier raw outcomes, including known-equivalent sites
reported killed without per-test explanations, are retained without relabeling
or speculative causes.

The authoritative Rust run recorded 680 caught and 260 unviable. Review found
259 compiler rejections and one missing incremental object. The infrastructure
candidate was replayed without incremental compilation and caught, then caught
again after a clean 117-test baseline. A 940-to-940 exact proposal bijection
qualifies the formatting-only correction; all original outcomes remain intact.

Source qualification remains explicit. Fresh native full mutation, C coverage,
SDK and static checks use the 333-input startup snapshot. Rust runtime sources
are unchanged. All nine fresh candidate release binaries equal the previously
qualified ownership binaries; only the final native-ledger context metadata
differs from that candidate snapshot. The prior ordinary matrix, schema and
114-workload benchmarks therefore remain executable-qualified component results,
not new whole-snapshot executions. The matrix includes 72 megamorphic
JIT/coobserver cases, sampling/JIT probes, FPM reuse/recovery/preload and
four-worker closures. The new private bootstrap instead has fresh six-SDK
compilation/probes, full native mutation and sanitizer-specific evidence.

## Sanitizer startup failure and owned cleanup

Fresh normal ASan/LSan public/private suites pass, including 39 controller modes,
36 defensive native cases and a report larger than 38 MB. Fourteen configuration/
foreign-collision checks and two late sampler-publication collision checks pass
their declared ownership/continuation oracles. Of these sixteen cases, five
normal/observer-continuation cases exit cleanly with no leaks. Eleven deliberate
startup failures retain genuine LSan failures: four match the PHP baseline, and
seven preserve a foreign fixture allocation plus that baseline. Test helpers now publish only
after successful native initialization and remove only current-module entries
whose names and handlers match their own tables. Foreign entries are preserved.
Actual helper-owned startup allocations were removed: the linked control had
21,192 bytes/662 leaks; corrected forced failure matches the independently
measured PHP baseline of 20,232 bytes/632 allocations.

The combined failed-MINIT sanitizer cases still **fail**. PHP's fatal startup
path exits before normal engine teardown. No global PHP cleanup or sanitizer
suppression is used. Both actual combined drivers reject genuine ANSI-colored
LSan diagnostics even alongside expected PHP failure text. Normal remainders
and deliberate startup failures are recorded separately; this does not establish
a globally clean sanitizer run. Earlier 20,968-byte/655-allocation evidence
belongs to an older private fixture and is preserved. Detailed new source/
experiment qualifications: `target/minit-startup-audit/result.json`.

External native fault objects now use actual-content hashes in their linker
paths. Real object-only edits force Cargo to relink; unchanged object bytes
retain a stable path. The compiler receives the actual safely parsed CFLAGS,
and SDK/compiler/Cargo errors propagate explicitly. Controlled tool-transport
regressions accompany actual object/ELF freshness evidence. Sanitizer diagnostics
are checked independently of expected PHP exit status; quoted informational
PHP text remains distinguishable from anchored real tool headers.

Concurrent ZTS embedding and earlier normal sanitizer checks have unchanged-
runtime source qualifications under `target/zts-sampling-final-315/` and
`target/sampling-asan-formatted319-linux/`. They are not fresh 333-input embed
executions. All previous failed C runs and snapshots remain intact.

Dependencies are acquired using locked manifests during preparation; runtime
acceptance containers have networking disabled. Remote Actions still needs an
authorized repository/ref and source publication: this workspace has no Git
repository or remote, and the workflow accepts push/PR triggers.

Targeted source privacy, license and local-link reviews retain their explicit
scope; they are not whole-project security or legal clearance.

## Historical 285-input checkpoint

The following results predate the current sampler, ZTS disabled fast path,
frame free-list and expanded native test hosting. They are retained as historical
evidence and do not establish current release readiness. References to the
checkpoint's "final" snapshot below apply only to that 285-input checkpoint.

The final validation runs use the bounded-memory engine with preallocated
indexes, request-runtime-cache closure identities, checked identifier exhaustion,
and native facts frozen at the first stop boundary. It supports an opt-in
8,192-symbol capture limit while retaining the default of 512. Inactive Fiber
destruction avoids measurement providers, and delayed report publication reuses
the frozen stop boundary. Reports retain the version 1 schema.

The final snapshot has 285 inputs. Fresh whole-runtime Rust mutation, raw Rust/C
coverage and the nine-cell matrix validate the corrected global-name boundary and
buffered persistence. The C bridge and its native measurement-contract assertions
are unchanged from the previous complete C mutation run. Its component evidence
is retained with exact hashes in
`target/symbol-buffer-c-component-qualification/qualification.json`; it is not
relabeled as a new whole-system C mutation execution.

| Gate | Checkpoint result | Evidence |
| --- | --- | --- |
| First-party C lines, functions, branches | **100%**, raw Clang 14: 152/152 lines, 31/31 functions, 92/92 branches | `target/clang-gcov-symbol-buffer-final/run-ufGHlE8l/run.json`; final snapshot matches |
| First-party Rust lines, functions, branches | **100%**, all seven files: 3,973/3,973 lines, 427/427 functions, 368/368 branches | `target/native-cov-symbol-buffer-final/run-5x57hYOd/run.json`; final snapshot matches |
| All runtime Rust mutation candidates | 726 generated: 490 caught, 236 compile-rejected, zero survivors or timeouts; all viable candidates killed | `target/rust-mutation-symbol-buffer-final/run-rtzvdd7s/run.json`; final snapshot matches |
| Unchanged runtime C mutation component | Raw 132/133 killed; one audited equivalent remains raw `Survived`; all 132 non-equivalent candidates killed | `target/c-mutants-capacity-abi-final/results/run-tSG81Cyp/provenance/classification.json`; exact component/contract qualification described above |
| PHP 8.3, 8.4, 8.5 × NTS CLI, ZTS CLI, FPM | Nine full cells passed, clean runner exit; actual OPcache/JIT/SDK labels and 72 megamorphic JIT/coobserver cases verified | `target/compatibility-symbol-buffer-final/result.json`; final snapshot matches |
| Python validation-gate regression tests | 48 passed | `target/symbol-buffer-acceptance/result.json` |
| Shell and workflow static analysis | ShellCheck 0.11.0 and Actionlint 1.7.12 passed | Pinned CI job; final sources checked locally |
| Workspace tests, formatting, strict lint, release build | 93 tests passed; all checks passed | `target/symbol-buffer-acceptance/result.json` |
| Rust/C AddressSanitizer native suite | Passed, including a separate 38,542,318-byte report boundary | `target/symbol-buffer-acceptance/`; PHP interpreter itself is not instrumented |
| Real report JSON schema | Three reports passed | `target/symbol-buffer-acceptance/result.json`; pinned local schema/development validator |

The completed matrix used an immutable Bash 3 compatible runner and identical
copies of all 285 final inputs. A dedicated eight-case tracing-JIT workload in each
image verifies five receiver types at one callsite, exact method counts and checksum,
and independent observer coexistence in both load orders. JIT status, cached code
and generated-code buffer consumption are asserted immediately around the hot loop.
Separate FPM receipts establish JIT and preload behavior in actual workers.
All nine ordinary images were checked
for copied host targets, `.DS_Store`, Python caches, mutation output and embedded
host build-directory bytes; none were found. Private fixture/coobserver exports
are absent from the ordinary extension. Image IDs, extension hashes and targeted
coobserver privacy review remain in
`target/compatibility-symbol-buffer-final/packaging/`.

The authoritative C run uses Linux ARM64/PHP 8.3.36 NTS, pinned Clang/LLVM 14.0.6
and atomic profiling counters. It covers every runtime C source discovered under
`crates/*/src`; generated Zend header records remain in the raw report.

The Rust registry likewise enumerates every runtime `.rs` source under
`crates/*/src`, including newly added modules. Native PHP assertions run against
the instrumented extension. Rust unit and allocator integration tests complement
real Zend execution. Missing files, zero measurements, duplicate records,
profile-map mismatches and changed validation inputs invalidate the gate.

## Coverage methodology

The final combined native-primary Rust export passes naturally; no component
fallback or counter union is required. The shared callback regression exercises
both success and a later contained panic through one reusable callable. Real
existing-file parent policy and root-directory permission-denial probes cover
the remaining native paths. Earlier aggregation failures remain historical.

Rust uses nightly branch instrumentation and LLVM source coverage. Native export
places the executing extension first, followed by exact Cargo-recorded test
executables: choosing an unexecuted unit object first can attribute unmangled C
callbacks to its zero-count map. Raw Cargo and native-primary exports are both
retained. LLVM instantiation summaries can take a maximum rather than a union of
source-line counts across different generic instances; combined raw diagnostics
are retained when comparing separately compiled unit and native instances.

C uses Clang's raw gcov edge counters because they accurately count the second
return from Zend's `setjmp`/`longjmp` guards. Earlier LLVM source-coverage exports
missed catch arms that native assertions demonstrably executed. GCC's remaining
67/68 raw branch gap came from an inlined Zend header's constant
`persistent=false` path, rather than an unexecuted first-party condition. The
earlier `ABNORMAL_DISPATCHER` diagnosis was superseded. Neither failed raw artifact
is edited or relabeled as passing. The installed Clang 14 backend measures
all first-party source conditions directly; no proprietary patches, adjusted
counters, source-line exclusions or unsupported compiler flags are used.

Final CI requires the combined native-primary raw Rust export to pass strict
line, function and branch checks for all seven runtime files. Separate component
artifacts were diagnostic evidence; no component fallback is implemented or used.
Percentages are never manufactured by combining individual covered counters from
different reports.

Private defensive contracts use explicitly enabled debug-only test translation
units/modules. They operate after genuine Zend startup, delegate ordinary calls
to real providers, and exercise bounded dependency failures without forged Zend
frames. Release builds reject the test flags. Actual compiled functions and
Closure objects cover direct and OPcache-mapped runtime cache storage; four real
pthreads verify 16,384 unique UID allocations. Forced counter exhaustion is
permanent within the request, including after `resetAll`; a fresh request restores
normal instrumentation without reusing stale identifiers.

The final native ABI regression uses a genuine active Fiber. An opt-in test
wrapper checks its incoming wall/thread-CPU observations against independent
POSIX clock brackets and checks heap usage directly against Zend MM before
forwarding the original arguments. The reference checks allocate no Zend memory.
This verifies the measurement contract even when Zend has already unwound the
Fiber's frames. The test arm is off by default and absent from ordinary builds.

## Native reliability and accuracy

Native fixtures verify runtime control, nesting, default-session ownership,
retained reports, recursion, exceptions, fibers, generator behavior, closure cache
lifetimes, invalid byte identifiers, privacy, measured clock ranges, thread CPU,
heap boundaries and peak-reset semantics. Real SQLite and loopback HTTP fixtures
verify operation categories and absence of SQL, URL, response or token payloads.
Missing required drivers are failures.

An authentic PHP regression first demonstrated that 1,023/1,024-byte global
function names were incorrectly rejected by a separator allowance intended for
methods. The corrected adapter adds `::` only for nonempty scopes. The regression
and oversized-name checks run in the ordinary native suite.

File persistence now coalesces serializer tokens through a fixed 8 KiB stack
buffer, without a new heap owner or a change to the 64 MiB requested-heap quota.
One byte-identical 53,919-byte JSON test reduced underlying writer invocations
from 12,881 to seven. This measures writer calls, not an inferred kernel syscall
count. Short writes, interruption, zero writes and final flush failures preserve
their actual I/O errors; failed streams are not published. Focused evidence is
`target/release-review/focused-changes-receipt.json`.

The sealed-stop quota test reserves all remaining native budget, forces report
materialization to fail, and then executes unrelated PHP work. Frame/generated
identity counters do not advance while sealed. Later changes to INI values,
application heap peak, provider identity and observer-failure state do not change
the original facts. Releasing quota permits a report retry with its original
measurements, metadata, status and termination reason.

Filesystem tests cover atomic no-overwrite publication, concurrent writers,
symlinks, PHP virtual CWD, invalid bytes, `open_basedir` tightening and the case
where an exact output filename is allowed but its parent is denied. Persistence
failures retain the in-memory report. Native fatal/OOM probes preserve Zend fatal
semantics without executing PHP catch or continuation code.

Final FPM tests cover a reused worker, independent request IDs and call graphs,
fatal/OOM recovery, exact allocator ownership, and repeated closure-cache lifetimes
under disabled OPcache, OPcache, JIT, preload and preload with JIT. JIT claims
require actual runtime status and generated-code buffer consumption. True ZTS
embedding uses simultaneous pthread requests, separately from multiple CLI
processes. Each of the three FPM cells also verifies four distinct reused workers
and four overlapping measured leaf intervals under preload plus JIT. Actual FPM
PHP version, SAPI and ZTS mode must match the companion binary, SDK and matrix
label. UID identity claims are scoped to one process/cache lifetime.

The exact accepted release passed 160 embedded ZTS requests across OPcache-off/on
rounds (four threads × 20 requests per round), with four-way measured overlap,
21 closure identities and 64 closure calls per report. Shared code used one opcode
address and four distinct writable caches. See
`target/symbol-buffer-acceptance/result.json` and its adjacent ZTS receipt. This workload
does not claim concurrent Fiber-specific coverage.

All nine matrix cells separately run the heavyweight 8,192-deep, maximal-name
timeline case, producing a complete local JSON file larger than 16 MiB while both
bounded PHP accessors refuse it explicitly. This dedicated case is kept outside
the repeated mutation loop; ordinary native tests still exercise named functions,
closures and anonymous classes at 8,192 symbols plus repeated calls and default
512-symbol truncation. A separate ASAN run produced a 38,542,318-byte file with
exact graph/timeline attribution and successful atomic publication.

Tracing-JIT support also has source-verified upstream patch floors: PHP 8.4.24
and 8.5.9 for the affected branches. The new workload ran on later patch releases
(8.3.36, 8.4.27 and 8.5.12); it does not claim historical affected runtimes were
executed. See [JIT compatibility](jit-compatibility.md). These constraints are not
hidden runtime workarounds or changes to the application's JIT settings.

## Measured overhead

Current-runtime benchmarks cover five CLI workloads on PHP 8.5 ZTS and 8.3 NTS,
plus PHP 8.3 NTS FPM. All captures and exact binary/source snapshots were verified.
The shared host experienced substantial external contention; these are descriptive
measurements, not idle-machine, causal optimization or universal-overhead claims.

| Workload | Disabled / absent median | Enabled / absent median |
| --- | --- | --- |
| PHP 8.5 ZTS, one million CLI leaf calls | 1.85597× | 19.13541× |
| PHP 8.3 NTS, one million CLI leaf calls | 1.24707× | 7.61146× |
| PHP 8.3 NTS FPM, 20 requests × 10,000 leaf calls per batch | 1.12527× | 6.31915× |

FPM uses 11 repeats. Its timed region includes request processing, report
materialization and local FastCGI transport, excluding worker startup. Other CLI
workloads cover arithmetic, sleep, memory churn and repeated session lifecycle;
small differences under this host load are inconclusive.

Exact repetitions, capture validation and binary hashes are retained under
`target/release-review-benchmarks/`. The final-input qualification records three
unrelated JIT/matrix test files strengthened after those benchmark snapshots;
runtime sources and benchmark drivers/workloads are unchanged. Original hashes
are preserved, not relabeled. Earlier benchmarks remain historical evidence.
Negligible disabled overhead is still not established and remains a release
blocker. Statistical sampling and exact allocation-event counting remain explicit
unsupported capabilities.

## Mutation and safety gates

`scripts/rust-mutation.py` discovers every runtime Rust source, persists unfiltered
and production-scope candidate inventories, and executes all test code through
nextest with immediate failure cancellation. Test transports outside runtime
source are executed, not substituted for runtime candidates. The raw report
keeps compile-rejected mutants separate; survivors and timeouts fail.
Every one of the final 236 compile-rejected candidates was audited against its
build log: Rust type/trait constraints, a constant guard, or invalid let-chain
syntax rejected the replacement. No unexplained infrastructure, loader or linker
failure was accepted as an unviable mutant. The per-mutant audit is
`target/rust-mutation-symbol-buffer-final/run-rtzvdd7s/unviable-audit.json`.

`scripts/c-mutation.sh` generates Mull's include registry from all runtime C
sources and requires every non-equivalent mutant killed. One exact initializer
mutation has a [reviewed CAS equivalence proof](mutation-equivalence.md); its raw
`Survived` status is preserved. Source hash, mutator, span and replacement must
match the audited ledger. Duplicate matches, extra survivors and timeouts fail.
The reported non-equivalent score is distinct from the raw killed/generated ratio. Missing or empty file
entries fail. Before/after fingerprints include Rust/C, PHP fixtures, test
scripts, Docker recipes, dependency manifests and the patched vendor sources.

The original 120-second mutation baselines timed out during concurrent cold
builds after the capacity tests were added; those runs remain failed and unscored.
Explicit 240-second execution budgets passed and are recorded in the successful
run manifests and final CI configuration. Timeout outcomes still fail the gates.
The first complete 133-candidate C run exposed six untested observation-argument
substitutions. Its failed classifier is retained under
`target/c-mutants-capacity-final/results/run-mRiLUgr4/`. The new independent ABI
test rejects all six exact substitutions in an isolated native build; the final
full Mull run independently verifies every candidate.

Modified vendored dependency hunks have separate exact native-allocator proofs
and five targeted mutation trials. Natural OOM and injected native bailout are
distinct tests; a natural interpreter path that does not reach an immediate
bailout is not claimed to test that guard. Sanitizer evidence is scoped to the
instrumented extension and dependency, not an uninstrumented PHP interpreter.

## Reproduction

```sh
cargo fmt --all -- --check
cargo clippy --workspace --all-targets -- -D warnings
cargo test --workspace
python3 -m unittest discover -s tests/python
scripts/php-tests.sh
scripts/native-fault-tests.sh
python3 scripts/adapter-contract-tests.py
php -n -d extension=/path/to/extension tests/native/large-profile-boundary.php
CC=/path/to/matching/clang scripts/native-coverage.sh
scripts/clang-gcov.sh
python3 scripts/rust-mutation.py --timeout 240
MULL_TIMEOUT_MS=240000 MULL_PLUGIN=/path/to/matching/plugin scripts/c-mutation.sh
scripts/docker-matrix.sh
scripts/native-sanitizers.sh
shellcheck scripts/*.sh
actionlint .github/workflows/ci.yml
```

Clang-gcov coverage requires the pinned Linux development image described in
`tests/docker/ClangCoverage.Dockerfile`. Native LLVM branch coverage requires the
matching LLVM major used by nightly Rust. These tools are development dependencies;
the extension has no Python, compiler, external-agent or network runtime dependency.

Real-report schema validation uses the pinned development-only `jsonschema`
dependency in `requirements-dev.txt` and `scripts/schema-tests.py`. It resolves no
remote schema references. [Historical receipts](validation-history.md) retain
previous raw failures, scoped successes and benchmark results; they do not qualify
the final source. Final release also requires acceptable measured disabled overhead
and successful source-qualified completion of the gates above.

The pinned CI Mull 0.34.2 package was also installed and executed with matching
Clang 19 inside an Ubuntu 24.04 AMD64 container: its compiler plugin, runner and
required options worked on an instrumented tool smoke test. This is toolchain
viability evidence, not a PHP mutation score or a remotely executed workflow.
See `target/ci-viability/mull19/result.json`. Workflow and shell linters pass
locally; GitHub Actions itself remains unexecuted in this workspace.
