# Changelog

All notable changes are documented here using Semantic Versioning. See the README and compatibility documentation for measurement and platform limitations.

## [Unreleased]

### Added

- `php_profiler.observer` system INI setting (default `On`). `Off` registers no Zend fcall or fiber observer and no closure-identity cache slot, so a loaded but unused extension avoids the observer dispatch cost. `Profiler::start()` and `Profiler::enable()` then throw `PhpProfiler\ConfigurationException`; automatic capture (`php_profiler.mode=always|trigger`) captures nothing, and the first request that asks for it logs that once per process. The sampling interrupt hook stays registered but can never start.

## [0.1.0-alpha.17] - 2026-11-23

### Commits

- feat: add a report export script for callgrind, Chrome trace and folded stacks (#77)

### Added

- `scripts/export-report.php` converts a JSON report to Callgrind (KCachegrind, QCacheGrind), Chrome Trace Event JSON from the timeline (Perfetto, `chrome://tracing`) or folded stacks from sampling (`flamegraph.pl`, speedscope). It runs on a plain PHP 8.3+ CLI with no extension, omits unavailable metrics instead of printing zeros and records truncation in the output.

## [0.1.0-alpha.16] - 2026-11-21

### Commits

- feat: support PHP 8.6 (#62)

### Added

- PHP 8.6 support. The build accepts PHP 8.6.0's API version (20261112) through ext-php-rs-build 0.2.1, and CI tests 8.6 NTS and ZTS.

## [0.1.0-alpha.15] - 2026-11-20

### Commits

- fix: reject a signed or spaced FrankenPHP version (#48)

### Fixed

- A FrankenPHP module version with a sign or a leading space, such as `-1.0.0`, no longer counts as 1.13.4 or later: like any unparsable version, it keeps every `frankenphp_handle_request()` call treated as a worker's.

## [0.1.0-alpha.14] - 2026-11-20

### Commits

- fix: keep a request's capture when FrankenPHP refuses a worker call (#44)

### Fixed

- On FrankenPHP 1.13.4+, calling `frankenphp_handle_request()` outside worker mode no longer ends the current request's capture before FrankenPHP refuses the call: the report covers the whole request, including what runs after the refusal.

## [0.1.0-alpha.13] - 2026-11-19

### Commits

- fix: end FrankenPHP worker requests where they end (#37)

### Fixed

- End a FrankenPHP worker's start, and any session started between its requests, before the worker waits for its next request, so the start's report no longer counts that wait and sessions started between requests are no longer dropped.
- Never read the request headers FrankenPHP was still building when reading them bailed out.
- Refuse a direct call of `PhpProfiler\Internal\frankenphp_request()` from a worker's request handler instead of restarting the request and running the handler again.
- `frankenphp_request_headers()` exists from FrankenPHP 1.1, not 1.3: the documentation now says so.

## [0.1.0-alpha.12] - 2026-11-19

### Commits

- feat: capture each FrankenPHP worker request (#35)

### Added

- Capture each request a FrankenPHP worker serves through `frankenphp_handle_request()` as its own request, with its own `X-Php-Profiler-Id` header and report, instead of one session spanning the worker's life.

## [0.1.0-alpha.11] - 2026-11-18

### Commits

- fix: read FrankenPHP trigger headers from the request only (#34)

### Fixed

- Read FrankenPHP trigger headers from `frankenphp_request_headers()` (FrankenPHP 1.1+), which holds request headers alone: a trigger variable inherited from the process environment no longer makes FrankenPHP ignore every request, and one set with a Caddyfile `env` setting, which FrankenPHP copies over request headers, no longer captures every request whatever trigger header it carries.

## [0.1.0-alpha.10] - 2026-11-17

### Commits

- fix: refuse empty release notes before the version bump merges (#31)

### Fixed

- List commits that announce a breaking change only in their body among a release's commits, and refuse to prepare a release whose notes would be empty, instead of merging the version bump and then failing to publish it.
- Keep the changelog's closing note and fenced examples out of other versions' release notes.

## [0.1.0-alpha.9] - 2026-11-17

### Commits

- fix: publish only the released version's changelog section as release notes (#28)

### Fixed

- Publish only the released version's CHANGELOG section as its GitHub release notes, instead of the whole file, and repair the notes when finalization is retried.

## [0.1.0-alpha.8] - 2026-11-17

### Commits

- fix: read FrankenPHP trigger headers and ignore triggers outside requests (#25)

### Fixed

- Capture trigger requests under FrankenPHP outside worker mode, whose `getenv` serves only the process environment: SAPIs other than php-fpm, `php-cgi`, Apache and LiteSpeed now read the trigger header from `$_SERVER`.
- Ignore triggers when php-fpm, Apache, the built-in server, NGINX Unit or LiteSpeed run without a request method, as `php-fpm -i` does: FPM then reads the process environment, so an inherited `HTTP_X_PHP_PROFILER_TRIGGER` captured a profile.

## [0.1.0-alpha.7] - 2026-11-17

### Commits

- fix: let only environment-reading SAPIs refuse inherited triggers (#23)

### Fixed

- Accept trigger headers again when they equal an inherited `HTTP_X_PHP_PROFILER_TRIGGER` under php-fpm, Apache, the built-in server, NGINX Unit and LiteSpeed in server mode, which read request headers only: they no longer compare headers with the inherited value, so response timing reveals nothing about it. Every other SAPI (such as `php-cgi`, phpdbg, embed and FrankenPHP) refuses every trigger when PHP starts with that variable defined, and logs that once per process with a message naming it. LiteSpeed's command-line mode, like the CLI, never reads a trigger.

## [0.1.0-alpha.6] - 2026-11-17

### Commits

- fix: refuse only the inherited trigger value so FPM headers capture again (#21)

### Fixed

- Accept trigger headers under php-fpm, Apache and the built-in server again when the process environment PHP starts with defines `HTTP_X_PHP_PROFILER_TRIGGER`: only a value equal to the inherited one is refused, and that misconfiguration is logged once per process instead of on every request.

## [0.1.0-alpha.5] - 2026-11-17

### Commits

- fix: log trigger requests shadowed by the process environment (#19)

### Fixed

- Log a configuration error instead of silently ignoring trigger requests while the process environment defines `HTTP_X_PHP_PROFILER_TRIGGER`, and read that environment once at startup instead of on every request, where thread-safe builds could race with `putenv()`.
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
