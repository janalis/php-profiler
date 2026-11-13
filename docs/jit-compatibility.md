# Tracing JIT and observer compatibility

Tracing JIT requires an upstream PHP build containing the GH-22158 fix. For
PHP 8.4, use **8.4.24 or later**; for PHP 8.5, use **8.5.9 or later**.
These are source-verified patch floors, not claims that every later configuration
has been tested. Vendor backports require separate verification of the fix.
Ordinary extension version support does not establish JIT support on older patches.
The extension does not change or disable application JIT configuration.

## Upstream defect and exact source checks

[GH-22158](https://github.com/php/php-src/issues/22158) describes tracing-JIT
dispatch selecting the internal-function observer cache slot for a user function
at a megamorphic callsite. An extension reserving its own op-array cache word can
make those slot indices differ. This profiler does so for closure identity, so
the reported condition is relevant even when capture is disabled. The upstream
[fix](https://github.com/php/php-src/commit/19f595f) corrects the IR PHI operands.

Checked immutable tags show the old operand order in
[8.4.22](https://github.com/php/php-src/blob/php-8.4.22/ext/opcache/jit/zend_jit_ir.c#L4818)
and [8.4.23](https://github.com/php/php-src/blob/php-8.4.23/ext/opcache/jit/zend_jit_ir.c#L4818),
and the corrected order in
[8.4.24](https://github.com/php/php-src/blob/php-8.4.24/ext/opcache/jit/zend_jit_ir.c#L4818).
The 8.4 NEWS entry alone is insufficient: it lists the fix under 8.4.23 despite
that tag retaining the old expression. Likewise,
[8.5.8](https://github.com/php/php-src/blob/php-8.5.8/ext/opcache/jit/zend_jit_ir.c#L4690)
has the old order and
[8.5.9](https://github.com/php/php-src/blob/php-8.5.9/ext/opcache/jit/zend_jit_ir.c#L4690)
has the correction. No local historical-version crash reproduction is claimed.

PHP 8.3's examined x86/ARM64 backends call the generic observer entry rather
than this IR selection path; see the
[x86 backend](https://github.com/php/php-src/blob/PHP-8.3/ext/opcache/jit/zend_jit_x86.dasc#L10333).
That distinction concerns this specific defect, not universal PHP 8.3 JIT safety.

## Dedicated runtime regression

`scripts/jit-megamorphic-tests.py` runs one hot callsite over five distinct final
receiver classes with a two-type polymorphic limit. It requires cached source,
actual tracing JIT, accepted threshold configuration and code-buffer consumption
measured immediately after the hot loop. Captured calls must equal the exact
iteration count for every method, and the application checksum must match.

An independent test-only observer also checks its own begin/end counts. Cases
cover absent profiling, the independent observer alone, loaded-disabled and
enabled profiling, and both observer load orders. The fixture never changes
Zend's global observer state. The auxiliary observer is not a runtime dependency.

Local Linux arm64 CLI image receipts:

| PHP | Passing cases | Generated JIT bytes across cases |
| --- | --- | --- |
| 8.3.36 NTS | 8/8 | 8,784–9,104 |
| 8.4.27 NTS | 8/8 | 11,148–11,996 |
| 8.5.12 NTS | 8/8 | 10,660–11,492 |

Raw cases, image IDs, extension/fixture hashes and a consolidated summary are in
`target/observer-activation-research/fixed-jit-summary.json` and
`jit-mega83.log`, `jit-mega84.log`, `jit-mega85.log`. These receipts use the
existing capacity-final image artifacts; they do not certify a later rebuilt
extension. This runner uses CLI, including when executed inside an FPM image.
Actual FPM/preload and pthread evidence has its own
[source-qualified scope](closure-identity-validation.md).

The final 285-input matrix independently reran all eight combinations in each of
nine freshly rebuilt PHP 8.3/8.4/8.5 NTS, ZTS and FPM images: **72 passing cases**.
These current-runtime receipts are
`target/compatibility-symbol-buffer-final/result.json` and the per-cell
`*-megamorphic-jit.json` files. FPM-image cases still use the CLI companion;
actual FPM workers have separate preload/JIT/concurrency receipts in that matrix.

```sh
PROFILER_EXTENSION=/absolute/path/libphp_profiler.so \
python3 scripts/jit-megamorphic-tests.py --out /tmp/jit-megamorphic.json
```

The runner detects built-in OPcache or loads the local `opcache` extension. Use
matching `--php`, `--php-config` and extension binaries. It fails when JIT is
unavailable; a configured INI value alone is not treated as execution evidence.
