# Changelog

All notable changes are documented here using Semantic Versioning.

## [Unreleased]

### Fixed

- Log a configuration error instead of silently ignoring trigger requests while the process environment defines `HTTP_X_PHP_PROFILER_TRIGGER`, and lock the environment while reading it on thread-safe builds.
- Reject trigger secrets containing a NUL byte as a configuration error, since no header can match them.

## [0.1.0-alpha.4] - 2026-11-17

### Commits

- fix: never take automatic capture triggers from the process environment (#17)

### Fixed

- Never take an automatic capture trigger from the PHP process environment (phpdbg, embed hosts and `php-cgi`'s environment fallback).
- Compare trigger secrets byte for byte instead of through a lossy UTF-8 conversion.
- Log unusable trigger secrets only for requests that carry the trigger header.

## [0.1.0-alpha.3] - 2026-11-16

### Commits

- fix: list automatic capture proof inputs in the release transition inventory (#15)
- feat: configure and trigger request profiling through HTTP headers (#13)

### Added

- Automatic whole-request profiling configured through administrator-only INI settings, with authenticated HTTP triggers, response correlation IDs, and private JSON report files.

## [0.1.0-alpha.2] - 2026-11-13

### Commits

- fix: verify fresh Packagist metadata after publication (#11)

### Fixed

- Refresh cached Packagist metadata when verifying an automatically published alpha.

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
