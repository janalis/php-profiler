# Changelog

All notable changes are documented here using Semantic Versioning.

## [Unreleased]

## [0.1.0-alpha.1] - 2026-11-13

### Commits

- fix: explicitly continue verified default-token releases (#9)
- fix: validate runtime versions against workspace metadata (#7)
- fix: validate PIE packaging and automatic release candidates (#5)
- feat: add local Rust PHP profiler and repository quality tooling

### Added

- Local Rust PHP profiler with runtime control, call graphs, timelines, JSON reports, and supported-platform sampling.
- Repository development and pinned Rust/PHP/C formatting commands.
- Reproducible PIE source archives, isolated installation checks, and Linux/macOS package metadata.
- Protected quality gates and automatic alpha release candidates, checksums, and Packagist verification.

### Changed

- Register the native extension as `php_profiler`.
- Preserve evidence and installed development tools during build cleanup.

### Fixed

- Resume main quality validation after a release candidate merge and dispatch failure.

- Assert frame completion before teardown so a broken close operation fails promptly.

- Preserve same-line closure identity regression coverage through PHP formatting.
- Isolate coverage profiles by executable and preserve strict LLVM diagnostics.
- Keep private Zend helpers out of standalone Rust mutation test builds.
- Renew the audited native mutation coordinates after formatting, preserving raw outcomes.
- Update pinned GitHub Actions and release version tooling.

See the README and compatibility documentation for measurement and platform limitations.
