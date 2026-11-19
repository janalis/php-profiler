# Contributing

Use PHP 8.3 or newer with matching development headers and stable Rust. Run `make doctor`, then `make check`. Use `make fmt` before submitting changes. PHP uses Mago 1.28.0 with Symfony-aligned settings; this is not full Symfony rule enforcement. C uses clang-format 23.1.3; Rust uses rustfmt. Do not format vendored upstream sources.

Use Conventional Commit pull request titles: `fix:`, `feat:`, `docs:`, `test:`, `style:`, `chore:`, or `ci:`. Explain behavior changes and include meaningful regression tests. Record user-visible changes under Unreleased in CHANGELOG.md.

Run affected native integration, coverage, mutation, and compatibility gates before claiming completion. Source changes invalidate source-bound evidence: never refresh equivalent-mutant hashes without revalidating the proof. Preserve historical receipts. Never weaken gates or claim unavailable results.

Run `make bootstrap` to install checksum-verified Mago, ShellCheck, Actionlint and clang-format in `target/tools`, plus the Node development dependencies. This requires network access during development only. Run `make hooks` to opt into repository-local pre-commit formatting checks. `make verify` runs the extended local gates; the GitHub `quality-gate` additionally requires every platform-specific job.

Development tooling also requires Git, Make, curl, unzip, a PHP 8.3 or newer CLI with the FFI, pcntl and posix extensions (`scripts/php-tool.sh` runs every tool with them and no host INI), and Node.js 24 with npm. Native builds require a C compiler and matching libclang. Extended coverage and mutation gates require the exact LLVM/Mull cohorts documented in [validation](docs/validation.md); compatibility checks require Docker. PIE installation checks use the verified PIE PHAR in `target/tools/pie.phar` and never modify the host PHP installation.
