# Releases

The alpha channel starts at **0.1.0-alpha.1**. Fixes and features increment the alpha counter; breaking changes advance the minor base and restart at alpha.1. Maintenance-only commits do not release. Stable promotion requires a reviewed channel change. The planner only considers release tags reachable from the candidate commit.

Run `npm ci --ignore-scripts`, `node --test scripts/release-tools.test.mjs`, and `npm run release:plan --silent` in a Git checkout. Planning is read-only. Run `python3 scripts/prepare-release.py VERSION` only in a clean checkout to update the workspace version, both lockfile package entries, and changelog together. Preparation never rewrites mutation equivalence proofs.

## Automatic candidates and quality gates

After the complete `quality` workflow succeeds on the current main commit, `automatic-alpha-release` prepares a candidate pull request using `RELEASE_BOT_TOKEN`. Use a narrowly scoped GitHub App installation token or bot token with contents and pull-request write permissions. Actions must be permitted to create pull requests. The token must trigger ordinary pull-request checks; the default GITHUB_TOKEN is unsuitable for this step. No credential belongs in source control.

Candidate branches include the base source SHA. Retries reuse the same open candidate. Auto-merge uses squash; it never bypasses required checks. Configure main protection to require the stable `quality-gate` result and require branches to be up to date before merging. Configure squash-only merging and automatic merging. Source-bound equivalence proofs must be revalidated through the normal gates. An unresolved proof prevents automatic merging; do not mechanically refresh its hashes.

The merged candidate runs the full quality workflow again. Publication runs only after that exact commit passes and is still main's head. A newer main commit defers publication to its own quality run. Historical coverage, mutation, compatibility, and benchmark results do not approve a changed source tree.

## PHP packages and the external PECL blocker

PIE uses composer.json and packaging/config.m4. Both installation routes require Rust and matching PHP development headers. PECL needs an approved lead maintainer and accepted package name. Set the public repository variables `PECL_MAINTAINER`, `PECL_NAME`, and `PECL_EMAIL` only after verifying the account. Source packaging uses those public metadata fields and indexed source bytes:

```
python3 scripts/package-source.py --maintainer HANDLE --name 'PUBLIC NAME' --email PUBLIC_EMAIL
```

Validate package.xml and a clean installation before upload. Use exactly the validated archive for PECL; rebuilding with different metadata changes its hash.

**Fully automatic PECL upload is not implemented.** The official [release instructions](https://pear.php.net/manual/en/developers.release.web.php) describe a web upload form; the installed PECL CLI has no release/upload command. No supported authenticated upload protocol or existing maintainer session has been verified for this repository. Account/package acceptance and web upload remain external blockers. The workflow cannot resolve them by setting a flag or inventing an API.

Leave `PUBLISHING_ENABLED` unset until repository rules, registry ownership, package metadata, reproducible archives and installation gates have been verified. When explicitly enabled, finalization first downloads the public PECL archive and requires its SHA-256 to match the validated local bytes. Missing or differing PECL bytes fail before creating a tag.

## Resumable finalization

After PECL verification, `scripts/finalize-release.py` creates the immutable GitHub tag, then verifies that Packagist indexed that version with the exact candidate Git SHA and expected repository. Configure the Packagist GitHub integration/webhook for `janalis/php-profiler`. Delayed indexing leaves an explicitly partial publication (PECL and tag, no GitHub release); rerunning the failed workflow resumes verification. The tag must exist before Packagist can index it.

Finalization independently requires a clean tracked checkout, the matching committed workspace version, and every packaged source byte to match HEAD (including the two generated root build-file copies). Packagist verification uses its full package API, avoiding inherited fields in minified Composer p2 metadata.

Only then does finalization create the GitHub prerelease with the source archive and SHA-256 checksum. Retries verify existing tags and assets; they never force tags or overwrite differing assets. Missing assets on an existing matching release may be uploaded. Alpha publication does not establish production readiness, negligible overhead, unsupported allocation measurements, or a clean upstream failed-startup sanitizer baseline.
