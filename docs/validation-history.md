# Historical validation receipts

This document preserves earlier source-qualified experiments. These results predate the final bounded-memory/indexed engine, cache-lifetime closure identities and frozen stop facts. They are diagnostics and historical evidence, not results for the current binary. See [current validation](validation.md).

Provisional diagnoses below are retained as history. In particular, the GCC `ABNORMAL_DISPATCHER` explanation was superseded: the final 67/68 raw branch gap came from an inlined Zend header's constant `persistent=false` path. The current validation document records the accepted methodology.

# Validation and release gates

Production readiness is **not established**. The project targets 100% meaningful
line, branch, function and mutation coverage, with no hidden first-party exclusions.
The raw coverage gates remain below 100%. The recorded core, adapter and C
mutation runs have no survivors; their individual scopes are documented below.
Separate vendored-patch natural-OOM experiments on macOS PHP 8.5 and Linux
PHP 8.3 retain three interpreter-path survivors; they do not inherit the macOS
PHP 8.3 results.
CI enforces these requirements strictly; defining a workflow does not mean remote
CI has run or passed.

## Native execution evidence

Local execution used arm64 macOS/PHP 8.5 ZTS and arm64 Linux PHP 8.3–8.5 images.
Windows is unsupported; local results do not certify untested architectures.

The Docker build context excludes nested Rust targets and Python caches. A local
context export was inspected: 308 files, with no nested build artifacts, mutation
output, Python caches or local home-directory paths. Earlier compatibility-image
snapshots predate this packaging correction; their native test results retain
their own source manifests.

The full Docker baseline covered CLI NTS, CLI ZTS and FPM for all three PHP
versions, with normal, OPcache and JIT runs. Runtime status checks confirmed JIT
was actually active. Later incremental matrices rebuilt changed sources and
exercised the affected ownership, filesystem, capacity and control paths. Each
matrix preserves its own source hashes and logs under `target/compatibility-*`;
these snapshots must not be combined into an unsupported claim about one binary.
The final frozen nine-cell runner exited successfully after full native fixtures,
clock fault checks, exception ownership probes and FPM recovery. Its source
manifest predates the final vendored clone-error ownership fix; separate PHP
8.3/8.5 injected-bailout allocator probes qualify that narrow dependency change. The baseline also predates the final private serialization-module move. That delta relocates unchanged report output bodies and six existing tests, preserving all 42 core tests, Rust/PHP APIs and schema. Its fresh two-file coverage/mutation checks and post-move native/sanitizer probes are separate evidence; the earlier nine-cell results are not relabeled as a matrix run of the relocated source.

Native fixtures cover recursion, nested/default session selection, exception
outcomes, generators, fibers, same-line closure identities, invalid UTF-8 symbol
identity, anonymous-source privacy, real wall/CPU clock bounds and seconds
rollovers, retained heap observations, fiber heap isolation, bounded timelines,
2,000 repeated-call aggregation, and 1,000 worker jobs. Four simultaneous CLI
processes exercise independent publication. SQLite and loopback HTTP fixtures
check measured client-call counts and that SQL/URL/token/body values are absent.
Missing required drivers fail explicitly.

FPM tests send 32 concurrent requests through one reused worker. Fatal recovery
checks then alternate uncaught exceptions, explicit exits and JSON/array report
OOM with healthy requests: the PID stays the same, healthy sessions restart at
ID 1, and unfinished reports are persisted as incomplete/request-shutdown. All
three PHP FPM versions passed eight fatal/healthy alternations per worker.
An additional auxiliary-module allocator test passed eight direct/fallback OOM
requests per reused worker on all three versions, with stable exact Rust byte
counts on every OOM request after priming cleanup-stack bookkeeping; see the
unsafe inventory.
These tests exercise recoverable soft-limit OOM. A native hard-limit process
termination cannot provide request-shutdown recovery or guaranteed persistence.

A real ZTS embedding test ran four pthreads with 20 requests each. It validated
80 unique reports and measured four overlapping leaf intervals. This establishes
same-process thread isolation for that tested setup, beyond loading a ZTS CLI.
The CI job requires a matching ZTS embed library and fails if it is absent.

Filesystem checks verify actual OS results for permitted paths, `open_basedir`,
symlink escapes, runtime restriction tightening, PHP virtual working directories
and filesystem path bytes. Failed writes retain the in-memory report. Isolated
checks exercise unavailable Zend MM accounting and unsupported runtime `dl()`:
Zend's late module-startup failure remains fatal 255, no classes are registered,
and a fresh startup-loaded process still profiles normally.

## Coverage, mutation and memory safety

Production builds use stable Rust. Branch coverage uses a pinned nightly because
Rust branch instrumentation is unstable. `scripts/native-coverage.sh` combines
Rust tests and real PHP subprocesses with matching LLVM C instrumentation. The
checker requires every first-party `crates/*/src` file represented in the raw
artifact to pass; external Zend-header counters remain available separately.
See [cargo-llvm-cov's documented limitations](https://github.com/taiki-e/cargo-llvm-cov).

The post-move core run preserves all 42 tests and measured 1,320/1,320 lines,
97/97 functions and 94/94 branches: `lib.rs` contributes 1,147 lines, 81 functions
and 90 branches; `serialization.rs` contributes 173 lines, 16 functions and four
branches, all covered. The fresh isolated artifact is
`target/core-cov-module-move/run-0DfMszM7`. The separate region measure is
2,231/2,234 (99.87%); no regions or meaningful code were excluded. The fresh
mutation run in `target/mutation-core-module-move` produced 138 mutants:
135 caught, three unviable, no survivors or timeouts. Formatting, all-target
Clippy and the 42 core tests passed. These results qualify the module-move
source hashes `bd6aba4f490f4b7b4bb5d0bdec624c8b4ca9259144f5a32e7dbcaa4f12ecf9d6`
(`lib.rs`) and `bb2939f8fe87f17ef447c6100ebc562784f0107847140316ffa4c9cc7e8b2047`
(`serialization.rs`).

The earlier frozen adapter run produced 226: 73 caught, 153 unviable, no survivors
or timeouts. Preserve each snapshot's source scope when subsequent changes are made. Many unviable Rust
mutants are invalid constructor substitutions for the `PhpResult` alias; they
are not counted as caught. [Cargo-mutants outcome definitions](https://mutants.rs/using-results.html).

The native Rust test harness rebuilds the current mutated extension before
loading it, avoiding stale unmutated artifacts. C mutation uses matching Mull
and Clang independently. Fresh Elements reports must contain mutants, and every
non-killed result fails the gate. Surviving/equivalent mutants require individual
proof and raw counts; no blanket suppression or adjusted score is an unconditional
whole-project MSI claim.

The final GCC debug probe covered **91/91 lines, 26/26 functions and 67/68 raw
branch outcomes**. It is not 100% raw branch coverage. The previously stated
ABNORMAL_DISPATCHER explanation was incorrect: merely finding that node in an
early CFG does not locate the uncovered outcome. The measured `gcno` and an
analyzed rebuild match `profiler_string`'s function ID, line checksum and CFG
checksum. Its block 6 assigns the header's `persistent` SSA chain to zero, then
branches on `persistent != 0` in `Zend/zend_string.h:176`. The untaken true edge
is attributed to the first-party `ZVAL_STRINGL` callsite at bridge line 96.
The actual setjmp outcomes at line 95 both executed. This is a constant-parameter
instantiation of an upstream-header branch, not a proven missing catch execution.

`scripts/analyze-c-coverage.py` records raw counts, evidence-file hashes and the
specific checksum/SSA-backed explanation. It requires an unambiguous raw source
block and matching rebuilt CFG signature; ambiguous, stale or nonconstant inputs
remain unresolved. It neither removes outcomes nor produces an adjusted coverage
percentage, and it still fails when the raw gate fails. Seven analysis fixtures
exercise those fail-closed rules. The local proof is retained under
`target/c-coverage-analysis-raw`. Actual native `-O1` and `-O2` experiments both
passed functional assertions but measured 98/98 lines, 26/26 functions and only
55/58 raw branch outcomes. Optimization alone did not solve the raw gate.

The fresh mixed run `target/native-cov/run-ADGvfQSC/run.json` reports unchanged
source inputs. Its raw Rust adapter result is 615/659 lines, 105/112 functions,
114/126 branch outcomes; native C is 108/115 lines, 26/26 functions and 58/70
branch outcomes. Both strict LLVM gates therefore still fail. The combined and
native-library LLVM exports also disagree for identical named Rust callback
regions: `profiler_enter`, `profiler_exit` and `profiler_fiber_switch` have combined
counts zero but native-library counts 56,861, 56,861 and 16,471. This identifies
an export-attribution discrepancy; it does not establish that every remaining
adapter gap is a compiler artifact. Raw reports and the diagnostic comparison
are retained, without an adjusted percentage or a whole-project coverage claim.

Every `scripts/native-coverage.sh` invocation now creates a fresh
`run-XXXXXXXX/build` tree. Profraw-only cleanup cannot prevent stale coverage maps
from obsolete object/test binaries inflating denominators. Before/after input
snapshots, tool versions, raw report hashes and `run.json` identify the run;
source changes make it fail, and `latest.json` points to the latest attempt.
Previous artifacts are retained. Coverage CI must upload the nested run reports.

The final post-module-move/post-clone-fix mixed LLVM run preserved matching
before/after input hashes (`target/native-cov/run-B7gPwTos/run.json`). Its four
first-party files total 2,043/2,094 lines, 228/235 functions and 266/290 branch
outcomes; the strict gate fails. The core remains 100% on both files.

The frozen C Mull run generated 101 mutants and killed all 101 with zero
survivors; the independent checker passed. Real private guard-contract probes
cover fatal flags, decode status, INI boundary conditions and exact output-buffer
capacity. Provenance is retained in
`target/c-mutants83-contract/results/run-JzCKjLVh/1794433752.json` and its manifest.
This C mutation result is independent of, and does not turn, the failing raw
coverage gates into passes. Whole-project production readiness remains unverified.

AddressSanitizer has passed the native harness with first-party Rust/C instrumented
on macOS/PHP 8.5 ZTS and matching LLVM 23. The interpreter and bundled extensions
were not rebuilt with ASAN. This does not prove interpreter-wide memory safety,
LeakSanitizer coverage or production traffic behavior. The vendored exception
ownership fix has separate normal/OOM checks and isolated guard-removal mutation
experiments for PHP 8.3/8.5. The deterministic native-boundary contract suite
rejects all five changed-hunk mutants on Linux PHP 8.3/8.5 and macOS PHP 8.5; natural
soft-OOM survivors remain separately recorded. This is targeted patch evidence, not an upstream-wide
coverage or mutation claim.

## Benchmarks and reproduction

Both benchmark drivers compare absent, loaded-disabled and enabled modes with
warmups, randomized paired order, checked application results and raw samples.
CLI workloads include calls, CPU, sleep, memory and worker session lifecycles.
FPM uses private Unix-socket FastCGI batches and includes full request/report-access
cost. Truncated captures are flagged; FPM rejects incomplete counts. Source and
binary hashes identify measured artifacts. No cloud collector is involved.

The idle 100,000 tiny-call CLI workload measured a disabled median ratio of 1.48815
and enabled ratio 21.5559: about 9.919 additional disabled nanoseconds per call.
Other workload ratios differ substantially. These observations do not establish
a universal negligible-overhead or 2% claim. See `target/benchmarks/cli-final*.json`
for sample distributions and exact provenance.

The final idle FPM run used PHP 8.3.36 NTS, OPcache/JIT off, 11 paired batches of
10 requests and 100,000 calls per request. Median batches were 13,461,866 ns
absent, 20,653,387 ns disabled (1.53421×), and 483,624,687 ns enabled (35.9255×).
Enabled reports were complete with exact call counts. Raw distributions and
source/binary hashes are in `target/benchmarks/fpm-final-idle.json`. This measures
a tiny-call workload through a reused worker; it does not generalize to other
applications. Earlier loaded-host FPM smoke results remain separate artifacts.

```sh
cargo fmt --all -- --check
cargo clippy --workspace --all-targets -- -D warnings
cargo test --workspace
scripts/php-tests.sh
scripts/native-fault-tests.sh
scripts/native-sanitizers.sh
scripts/docker-matrix.sh
cargo mutants --workspace --test-workspace true --timeout 120
MULL_PLUGIN=/path/to/matching/mull-plugin scripts/c-mutation.sh
```

For real-report schema validation, development-only dependencies are pinned:

```sh
python3 -m venv target/dev-venv
target/dev-venv/bin/pip install -r requirements-dev.txt
PROFILER_EXTENSION="$PWD/target/debug/libphp_profiler.so" target/dev-venv/bin/python scripts/schema-tests.py
```

Use `.dylib` on macOS. The schema validator resolves no remote references. Python,
Mull, Clang coverage tools, embedding harnesses and local fixture servers are test
infrastructure, not runtime extension dependencies. Release requires passing the
remaining strict gates and a source-qualified record of the final checks.
