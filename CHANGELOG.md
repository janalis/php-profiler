# Changelog

All notable changes are documented here using Semantic Versioning.

## [Unreleased]

### Added

- Local Rust PHP profiler with runtime control, call graphs, timelines, JSON reports, and supported-platform sampling.
- Repository development and pinned Rust/PHP/C formatting commands.
- Reproducible PIE source archives, isolated installation checks, and Linux/macOS package metadata.
- Protected quality gates and automatic alpha release candidates, checksums, and Packagist verification.

### Changed

- Register the native extension as `php_profiler`.
- Preserve evidence and installed development tools during build cleanup.

### Fixed

- Preserve same-line closure identity regression coverage through PHP formatting.
- Isolate coverage profiles by executable and preserve strict LLVM diagnostics.
- Keep private Zend helpers out of standalone Rust mutation test builds.
- Renew the audited native mutation coordinates after formatting, preserving raw outcomes.
- Update pinned GitHub Actions and release version tooling.

No version has been published. See the README and compatibility documentation for measurement and platform limitations.
