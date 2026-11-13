# ext-php-rs bailout ownership patch

`ext-php-rs/` is the published ext-php-rs 0.16.4 source, licensed under its
included MIT and Apache-2.0 license files. The crates.io archive SHA-256 is
`af52a43b4a06ff1aa4068018c15e7480f7cab2ecb1231710497686587a964156`.

The workspace patches `PhpException::throw` in `src/exception.rs` and the
uncloneable-object error branch in `src/zend/handlers.rs`. The upstream implementation retained Rust-owned
exception and fallback strings on the stack while Zend could allocate and
bail out. A Zend longjmp does not run their Rust destructors. The local patch
registers the exception, escaped message, and formatted fallback with
ext-php-rs's existing bailout cleanup mechanism. The class name is a borrowed
`&str`; reachable fallback errors have no heap-owned payload, so neither
requires an ownership box.
Normal return and already-pending exception behavior remain unchanged.
No cargo registry cache files are edited.

This patch relies on the existing `run_handler` / `try_catch` bailout frame
used by the framework's generated PHP handlers. Observer callbacks never
throw PHP exceptions. The changed function must remain in local modification
coverage and mutation accounting; the rest is unchanged third-party source.
The patch should be removed only after verifying an upstream release provides
equivalent ownership protection.

## Regression validation

Run `python3 scripts/test-vendored-exception.py`. Its auxiliary extension lives
under `tests/support/exception-fixture`, is never part of the production PHP
module, and exercises normal, pending, attached-object, invalid-object,
NUL-message, and abstract-class exception paths. A test-only counting Rust
allocator measures outstanding allocations during PHP shutdown after a real
8 MiB exception allocation failure. Cleanup capacity is primed before the
baseline; both direct and fallback OOM cases require exactly equal before/after
Rust allocation counts and fatal exit 255, without PHP catch or continuation.

On local PHP 8.3.35 NTS, the final three-guard patch measured exactly 545
bytes before and 545 after, in both direct and fallback OOM cases. PHP 8.5.12
ZTS measured exactly 593 before and 593 after for both cases. The normal-path
fixture passed on both builds. An isolated control built from the unmodified,
read-only crates.io source failed the ownership assertion: 489 before and
8,389,097 after, an exact 8 MiB leak. These are native regression results,
not profiler measurements, RSS measurements or performance benchmarks.
PHP 8.5 has deferred soft OOM and a separate hard kill; the fixture raises only
its test process's hard-limit percentage so its soft-limit shutdown path can
run. PHP 8.3 exercises immediate Zend bailout cleanup. Reused-FPM OOM validation
is a separate gate and is not established by these CLI subprocesses.

## Targeted patch mutation accounting

Run `python3 scripts/vendor-patch-mutation.py --php /path/to/php83 --php-config
/path/to/php83-config`. The runner copies the fixture and vendor source into
an isolated temporary workspace. It rebuilds every mutant, saves source diffs,
build/test logs and raw JSON results under `target/vendor-patch-mutation`, and
requires each remaining ownership-guard removal to fail the native ownership
assertion. Neither the checked-out vendor file nor Cargo's registry cache is
mutated. A failed baseline, build failure, timeout or survivor fails the gate.

The first five-guard prototype generated five targeted mutants: three were
rejected by ownership assertions and two survived; none were unviable or timed
out. Raw results are retained at `target/vendor-patch-mutation-five/results.json`.
The two survivors were reviewed rather than concealed:

- `ClassEntry::name()` returns `Option<&str>` (`src/zend/class.rs`), so its guard
  only boxed a borrowed engine-owned name. Removing that guard cannot leak a
  Rust-owned class-name buffer.
- `throw_object` can return `Error::Object`; `throw_with_code` can return
  `InvalidException`, `InvalidCString` or `InvalidPointer`. Those reachable
  variants carry no owned heap payload. Removing the error guard removes both
  its temporary box and its cleanup without changing ownership semantics.

The unnecessary error/class guards were removed from production as a KISS
refactor. The final patch therefore contains three owned-buffer guards. Its
strict PHP 8.3 targeted run generated three mutants and rejected all three by
exact ownership assertions: zero survivors, unviable mutants or timeouts.
Artifacts are at `target/vendor-patch-mutation-three-83/results.json`.
Removing the exception or escaped-message guard leaked exactly 8 MiB; removing
the formatted-fallback guard leaked 8 MiB plus 66 formatting bytes. No mutant
was relabeled by the runner or excluded while its guard remained in production.

This is targeted mutation evidence for the local ownership patch, not a claim
of full ext-php-rs mutation coverage or a whole-project MSI. Dependency updates
must review the reachable error payloads again rather than assuming this
pinned-version proof still applies.

The current runner also targets the changed attached-object transfer separately:
`match exception.object.take()` becomes `match None` in an isolated copy. A
normal attached-exception probe requires the original object/message to survive.
The PHP 8.3 four-mutant run rejected all four viable mutants: three by exact
ownership assertions, one by attached-object semantics, with zero survivors,
unviable builds or timeouts. Raw artifacts are at
`target/vendor-patch-mutation-four-83/results.json`. This additional semantic
check is not counted as a fourth ownership guard or as whole-dependency MSI.

## Uncloneable-object bailout ownership

The upstream clone handler constructs an owned Rust `CString` before calling
`zend_throw_error`. This handler is called directly by Zend, outside the
framework's native `try_catch` handler wrapper. A bailout while allocating the
error skips the Rust string destructor. The local patch uses a static printf
format and borrows the registered class's NUL-terminated Zend name instead.
There is no Rust-owned error message to clean up. The original class-name text,
error type, and fresh placeholder object returned on ordinary error paths are
preserved; the original object is never returned as the failed clone.

`python3 scripts/clone-bailout-tests.py` builds an auxiliary uncloneable class
and a test-only native fault at the actual `zend_throw_error` boundary.
On macOS it uses Mach-O interposition; on Linux the auxiliary module is built
with GNU `--wrap=zend_throw_error`, because the PHP executable's own definition
prevents reliable ELF preloading. The build flag affects only that auxiliary
module, never the production extension or PHP executable.
The fixture models allocation bailout before the error can be constructed;
it is never linked into the production extension. Fatal exit 255, shutdown
execution, absence of catch/continuation, and exact Rust allocation accounting
are all required. PHP 8.3.35 NTS with the original clone branch measured
730 bytes before and 805 after, proving a 75-byte retained Rust allocation.
The fixed branch measured 730/730 on that build and 794/794 on PHP 8.5.12 ZTS.
Real Linux Docker PHP 8.3 and 8.5 CLI builds measured 602/602 and 666/666
respectively using the GNU wrapper.
These are deterministic injected allocation-failure results, not a claim that
a natural clone-only OOM was observed. Ordinary clone errors are exercised
50 times in `tests/native/session-handle-safety.php`, which also checks
serialization/reflection rejection and continued use of the original session.
The changed handler hunk belongs in local modification coverage and mutation
accounting alongside the exception patch.

## Deterministic exception bailout versus natural OOM

`python3 scripts/exception-bailout-tests.py` independently tests the immediate
native-bailout contract with PHP `memory_limit=-1`. Both direct and formatted
fallback messages reach `zend_throw_exception` in the pinned implementation.
The test-only native transport forwards short priming exceptions unchanged,
then injects a real Zend bailout at that boundary for the fixture's 8 MiB
messages. macOS uses Mach-O interposition; Linux builds only the auxiliary
module with GNU `--wrap=zend_throw_exception`. A separate build flag selects
this test binary; production code has no fault hook. The probe requires fatal
exit 255, a shutdown counter record, no PHP catch or continuation, and identical
before/after outstanding Rust bytes for both cases 6 and 7.

The unmodified three-guard patch passed both cases on native PHP 8.3.35 NTS
(794/794 bytes) and PHP 8.5.12 ZTS (858/858 bytes), and on actual Linux Docker
PHP 8.3 CLI (666/666 bytes) and PHP 8.5 CLI (730/730 bytes). The expanded fixture
registers an additional class for clone regression, so these absolute baselines
differ from the earlier smaller fixture; equality is the ownership invariant.

This deterministic allocation-boundary proof supplements the separate natural
memory-limit tests; it does not replace their results. PHP 8.5 can defer soft
OOM until after an allocation call returns, allowing ordinary Rust destructors
to run. Therefore natural PHP 8.5 OOM alone cannot prove the cleanup guards are
required for immediate Zend bailout. Mutation runs must retain the distinction
between natural-OOM survivors and rejection by this injected native-bailout
proof. Neither test is a claim to control every possible PHP allocation site.
