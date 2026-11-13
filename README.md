# Local PHP profiler

A native PHP extension with a Rust measurement engine and self-contained local
JSON reports. It implements runtime sessions, function/method call graphs,
wall/thread-CPU timings, heap observations, fiber-aware attribution, optional
timelines and derived hotspots. **Production readiness remains unverified.**
See [validation](docs/validation.md) for source-qualified coverage, mutation,
compatibility results and remaining release blockers.

The extension has no collector, telemetry, runtime network dependency, dashboard
or dependency on Blackfire/Xdebug. Profiling is disabled by default and starts
only through PHP.

## Build and use

Build with stable Rust, 64-bit PHP 8.3–8.5 development headers, `php-config`, a C compiler
and libclang available to bindgen:

```sh
cargo build -p php-profiler
php -n -d extension="$PWD/target/debug/libphp_profiler.so" example.php
```

Use `.dylib` on macOS. Headers must match the binary's PHP version and NTS/ZTS
configuration. Linux and macOS are supported build targets; Windows is unsupported.
Load the extension during process startup. Runtime `dl()` loading is unsupported
because Zend observers must register at startup.

```php
<?php
use PhpProfiler\Profiler;

// An existing directory requests a unique report filename.
$session = Profiler::start(sys_get_temp_dir(), ['timeline' => true]);
try {
    application();
} finally {
    $session->stop();
}
$report = $session->getReport();
$json = $session->getReportJson();
$path = $session->getReportPath();
$session->reset();
Profiler::disable();
```

Omit the path for in-memory reporting unless a default was configured through
`Profiler::setOutputPath()`. Existing files are never overwritten. Relative paths
use PHP's working directory at write time, respecting `open_basedir`. Failed
persistence retains the in-memory report. Reports contain program identifiers;
treat them as private application metadata.

The [PHP stub](php/PhpProfiler.stub.php) documents signatures and exception types.
The static `Profiler` API provides enable/isEnabled/disable, start/stop/reset,
resetAll, output-path configuration and report/JSON/path retrieval. `start()`
returns an independent `PhpProfiler\Session` handle with equivalent instance
methods and `getId()`. Omitted handles select within the current fiber context;
`stop()` requires an active session. Nested measurements overlap and must not be
added together. Long-running workers should retrieve then reset completed sessions.

Options are `timeline` and `sampling` (both default false), and `max_events` (1–8192, default 512). Storage limits
bound graph entries, active frames and timeline records; repeated calls keep
aggregating. Truncation and unavailable metrics are explicit in the report.
Graphs cover Zend observer-visible calls. Builtins compiled to VM opcodes (for
example direct `strlen`) have no separate function-call event; their work remains
in surrounding observed-function or session time. See [measurement boundaries](docs/capabilities-feasibility.md).
The shared 64 MiB requested-capacity quota also applies to large or concurrent
sessions. JSON/PHP-array accessors are limited to 16 MiB encoded output; larger
reports can be written atomically to the configured file.

## Measurements and limits

Observed calls include user/internal functions, methods, recursion, closures,
generator resume segments, fibers and exception exits. Inclusive/exclusive wall
and thread-CPU times include instrumentation overhead. SQL/HTTP/I/O categories
measure supported internal client calls without collecting query strings, URLs,
arguments, response bodies or exception messages. Request-wide Zend heap peaks
are measured separately from session boundary observations; heap deltas are
not allocation counts or exact session peaks.

Optional [wall-timer safepoint sampling](docs/sampling.md) is available on Linux
glibc 2.32+ and emits version 2 reports; ordinary reports remain version 1.
Independent CPU sampling and exact allocation-volume accounting are unsupported.
Generator segments are not logical invocation counts. Optimized-away
calls and builtins compiled to dedicated VM instructions do not produce ordinary
call records. OPcache/JIT have real matrix evidence for the tested configurations;
that does not establish universal compatibility. Tracing JIT requires the
GH-22158 fix: upstream PHP 8.4.24+ or 8.5.9+ for those branches; see
[verified JIT patch limits](docs/jit-compatibility.md). SIGKILL/power loss cannot guarantee
a final report. PHP hard-memory-limit process termination can also bypass
request shutdown. See [report semantics](docs/report-format.md) and
[architecture/safety boundaries](docs/architecture.md).

## Development checks

```sh
cargo fmt --all -- --check
cargo clippy --workspace --all-targets -- -D warnings
cargo test --workspace
scripts/php-tests.sh
scripts/native-fault-tests.sh
cargo build --release -p php-profiler
python3 scripts/benchmark.py --extension target/release/libphp_profiler.so
python3 scripts/performance-evidence.py --extension target/release/libphp_profiler.so --output target/performance
```

Coverage, Rust/C mutation, schema, ASAN, FPM recovery and true ZTS pthread gates
are defined in CI. A workflow definition is not a passing remote CI run. Benchmark
scripts retain separate CPU/wall samples and provenance. `benchmark.py
--warm-disabled` measures disabled execution after an actual stopped/reset capture,
outside the timed region; allocator and observer caches are also warmed.
`performance-evidence.py --with-warm-disabled` adds CLI call-heavy and worker cases
for this state. The Docker CI cells run
five comparative CLI workloads and FPM request batches in FPM images, with
runtime networking disabled. Their checks require complete instrumentation captures,
valid sampling counters with boundary losses disclosed, and unchanged application results; they do not assert an unmeasured negligible-overhead limit.
Overhead varies by workload, so no
universal negligible-overhead claim is made. Third-party licenses and the targeted
ext-php-rs exception-ownership patch are retained under `vendor/`. MIT licensed.

## Repository development

See [contributing](CONTRIBUTING.md), [changelog](CHANGELOG.md), and [release procedures](docs/releasing.md). Run `make bootstrap` then `make check` for ordinary development. `make verify` runs extended local validation; platform-specific CI gates remain required before release. The initial release channel is alpha. No registry release is currently available.
