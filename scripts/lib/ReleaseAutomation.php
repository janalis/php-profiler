<?php

declare(strict_types=1);

namespace PhpProfiler\Tooling;

use Closure;
use InvalidArgumentException;
use RuntimeException;

require_once __DIR__ . '/ReleaseRunner.php';
require_once __DIR__ . '/Toml.php';
require_once __DIR__ . '/VersionTransition.php';

final class ReleaseTimeout extends RuntimeException {}

/** Coordinates exact-SHA quality dispatch, protected candidate merges and PIE releases. */
final class ReleaseAutomation
{
    public const string REPOSITORY = 'janalis/php-profiler';
    private const array METADATA = ['Cargo.toml', 'Cargo.lock', 'CHANGELOG.md', 'tests/native/c-equivalents.json'];
    private const string BRANCH = '/\Arelease\/0\.\d+\.\d+-alpha\.[1-9]\d*-[0-9a-f]{12}\z/';

    /** @var Closure(string, string, string): void */
    public Closure $validateCandidate;

    public function __construct(
        private readonly ReleaseRunner $runner,
        private readonly string $root,
    ) {
        $this->validateCandidate = $this->validateCandidateMetadata(...);
    }

    private function command(string ...$arguments): string
    {
        return trim($this->runner->output($arguments));
    }

    private function check(array $command): void
    {
        $status = $this->runner->run($command);
        if ($status !== 0) {
            throw new ProcessFailedStatus($command, $status, '');
        }
    }

    public function api(string $path): array
    {
        return json_decode(
            $this->command('gh', 'api', 'repos/' . self::REPOSITORY . '/' . $path),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    public function dispatch(string $branch, string $source): void
    {
        $this->check([
            'gh',
            'workflow',
            'run',
            'ci.yml',
            '--repo',
            self::REPOSITORY,
            '--ref',
            $branch,
            '-f',
            'expected_sha=' . $source,
        ]);
    }

    /** Allows the dispatching quality job to finish; never accepts partial success. */
    public function completedRun(int $runId, int $attempts = 24, int $delay = 5): array
    {
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $run = $this->api("actions/runs/{$runId}");
            if (($run['status'] ?? null) === 'completed') {
                if (($run['conclusion'] ?? null) !== 'success') {
                    throw new InvalidArgumentException('Quality workflow completed without success');
                }
                return $run;
            }
            if (!in_array($run['status'] ?? null, ['queued', 'in_progress', 'waiting', 'pending', 'requested'], true)) {
                throw new InvalidArgumentException('Unexpected quality workflow status');
            }
            if (($attempt + 1) < $attempts) {
                $this->runner->sleep($delay);
            }
        }
        throw new ReleaseTimeout('Quality workflow did not complete before the release continuation deadline');
    }

    /** Projects verified full quality into the pull-request-compatible commit status API. */
    private function publishQualityStatus(string $source, int $runId): void
    {
        $this->check([
            'gh',
            'api',
            '--method',
            'POST',
            'repos/' . self::REPOSITORY . "/statuses/{$source}",
            '-f',
            'state=success',
            '-f',
            'context=quality-gate',
            '-f',
            'target_url=https://github.com/' . self::REPOSITORY . "/actions/runs/{$runId}",
            '-f',
            'description=Full exact-SHA quality workflow passed',
        ]);
    }

    public function validateRun(array $run, string $source): string
    {
        if (
            ($run['name'] ?? null) !== 'quality'
            || ($run['conclusion'] ?? null) !== 'success'
            || ($run['head_sha'] ?? null) !== $source
            || ($run['head_repository']['full_name'] ?? null) !== self::REPOSITORY
            || !in_array($run['event'] ?? null, ['push', 'workflow_dispatch'], true)
        ) {
            throw new InvalidArgumentException('Expected a successful exact-SHA quality run from this repository');
        }
        $branch = $run['head_branch'] ?? '';
        if (
            $branch !== 'main'
            && !(
                preg_match(self::BRANCH, $branch)
                && $run['event'] === 'workflow_dispatch'
                && ($run['actor']['login'] ?? null) === 'github-actions[bot]'
            )
        ) {
            throw new InvalidArgumentException('Untrusted release branch or dispatch actor');
        }
        return $branch;
    }

    private function validateCandidateMetadata(string $parent, string $candidate, string $version): void
    {
        $changed = array_values(array_filter(explode("\n", $this->command(
            'git',
            'diff',
            '--name-only',
            $parent,
            $candidate,
        ))));
        if ($changed === [] || array_diff($changed, self::METADATA) !== []) {
            throw new InvalidArgumentException('Candidate modifies files outside release metadata');
        }
        // Only the trusted main checkout's helper is loaded, never candidate branch code.
        $reader = fn(string $revision): Closure => fn(string $name): string => $this->runner->output([
            'git',
            'show',
            "{$revision}:{$name}",
        ]);
        [$expected] = VersionTransition::expected($reader($parent), $reader($candidate), $version);
        if ($reader($candidate)(VersionTransition::LEDGER) !== $expected) {
            throw new InvalidArgumentException(
                'Candidate ledger differs from the independently derived version transition',
            );
        }
    }

    public function main(int $qualityRunId): void
    {
        chdir($this->root);
        $checkout = $this->command('git', 'rev-parse', 'HEAD');
        $run = $this->completedRun($qualityRunId);
        $source = $run['head_sha'] ?? '';
        $branch = $this->validateRun($run, $source);
        $mainSha = $this->api('git/ref/heads/main')['object']['sha'];
        if ($checkout !== $mainSha) {
            echo "Trusted main checkout is stale; retry on current main\n";
            return;
        }
        if ($branch !== 'main') {
            $this->continueCandidate($branch, $source, $mainSha, $qualityRunId);
            return;
        }
        if ($mainSha !== $source) {
            echo "Main advanced; a newer quality run will continue release preparation\n";
            return;
        }
        $plan = json_decode($this->command('node', 'scripts/release-plan.mjs'), true, flags: JSON_THROW_ON_ERROR);
        $current = Toml::parse((string) file_get_contents('Cargo.toml'))['workspace']['package']['version'];
        $version = $plan['version'];
        if ($version === null) {
            // A previous attempt may already have created the tag and release before Packagist
            // indexed it. Only resume when that tag still identifies HEAD.
            if ($plan['previous'] !== $current || $this->command('git', 'rev-parse', 'v' . $current) !== $source) {
                return;
            }
            $version = $current;
        }
        $tool = 'scripts/php-tool.sh';
        if ($current === $version) {
            if (getenv('PUBLISHING_ENABLED') !== 'true') {
                throw new InvalidArgumentException(
                    'Publication disabled until exact quality gates and Packagist ownership are verified',
                );
            }
            $this->check([$tool, 'scripts/package-source.php']);
            $this->check([
                $tool,
                'scripts/finalize-release.php',
                $version,
                'target/php-profiler-source.tar.gz',
                '--quality-run-id',
                (string) $qualityRunId,
            ]);
            return;
        }
        $branch = "release/{$version}-" . substr($source, 0, 12);
        $existing = array_values(array_filter(
            $this->api('git/matching-refs/heads/' . $branch),
            static fn(array $ref): bool => $ref['ref'] === 'refs/heads/' . $branch,
        ));
        if ($existing !== []) {
            $candidate = $existing[0]['object']['sha'];
            if ($this->command('git', 'rev-parse', $candidate . '^') !== $source) {
                throw new InvalidArgumentException('Existing candidate branch has another parent');
            }
            ($this->validateCandidate)($source, $candidate, $version);
        } else {
            $this->check(['cargo', 'fetch', '--locked']);
            $this->check([$tool, 'scripts/prepare-release.php', $version]);
            $this->check(['git', 'config', 'user.name', 'github-actions[bot]']);
            $this->check(['git', 'config', 'user.email', '41898282+github-actions[bot]@users.noreply.github.com']);
            $this->check(['git', 'add', ...self::METADATA]);
            $this->check(['git', 'commit', '-m', 'chore(release): prepare ' . $version]);
            $candidate = $this->command('git', 'rev-parse', 'HEAD');
            $this->check(['gh', 'auth', 'setup-git']);
            $this->check(['git', 'push', 'origin', "HEAD:refs/heads/{$branch}"]);
        }
        $prs = json_decode(
            $this->command(
                'gh',
                'pr',
                'list',
                '--repo',
                self::REPOSITORY,
                '--head',
                $branch,
                '--base',
                'main',
                '--state',
                'open',
                '--json',
                'number,headRefOid',
            ),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        if ($prs !== [] && (count($prs) !== 1 || $prs[0]['headRefOid'] !== $candidate)) {
            throw new InvalidArgumentException('Unexpected existing candidate pull request');
        }
        if ($prs === []) {
            $this->check([
                'gh',
                'pr',
                'create',
                '--repo',
                self::REPOSITORY,
                '--base',
                'main',
                '--head',
                $branch,
                '--title',
                'chore(release): prepare ' . $version,
                '--body',
                'Automatic alpha candidate. Full required quality checks must pass on these exact source bytes. Native proof ledgers are never automatically rewritten.',
            ]);
        }
        // GITHUB_TOKEN writes intentionally do not trigger ordinary push or pull request
        // workflows; workflow_dispatch is the supported explicit exception.
        $this->dispatch($branch, $candidate);
    }

    private function continueCandidate(string $branch, string $source, string $mainSha, int $qualityRunId): void
    {
        // A previous attempt may have merged successfully before dispatch failed. Pull
        // request records survive automatic source-branch deletion.
        $prs = json_decode(
            $this->command(
                'gh',
                'pr',
                'list',
                '--repo',
                self::REPOSITORY,
                '--head',
                $branch,
                '--base',
                'main',
                '--state',
                'all',
                '--json',
                'number,headRefOid,author,baseRefName,state,mergeCommit',
            ),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        if (
            count($prs) !== 1
            || $prs[0]['headRefOid'] !== $source
            || ($prs[0]['baseRefName'] ?? null) !== 'main'
            || !in_array($prs[0]['author']['login'] ?? null, ['github-actions[bot]', 'app/github-actions'], true)
        ) {
            throw new InvalidArgumentException('Expected one bot pull request for this exact candidate and main base');
        }
        $pr = $prs[0];
        if ($pr['state'] === 'MERGED' && ($pr['mergeCommit']['oid'] ?? null) !== $mainSha) {
            throw new InvalidArgumentException('Merged candidate no longer identifies current main');
        }
        if (!in_array($pr['state'], ['OPEN', 'MERGED'], true)) {
            throw new InvalidArgumentException('Candidate pull request is closed without a merge');
        }
        // Deleted branches may no longer be in the checkout's fetched objects; GitHub
        // retains the immutable pull request head. Never execute candidate scripts.
        $this->check(['git', 'fetch', 'origin', "refs/pull/{$pr['number']}/head"]);
        $parent = $this->command('git', 'rev-parse', $source . '^');
        if (!str_ends_with($branch, '-' . substr($parent, 0, 12))) {
            throw new InvalidArgumentException('Candidate branch does not identify its immutable base');
        }
        $version = substr(
            substr($branch, strlen('release/')),
            0,
            (int) strrpos(substr($branch, strlen('release/')), '-'),
        );
        ($this->validateCandidate)($parent, $source, $version);
        if ($pr['state'] === 'MERGED') {
            if (
                $this->command('git', 'rev-parse', $mainSha . '^') !== $parent
                || $this->command('git', 'rev-parse', $mainSha . '^{tree}') !== $this->command(
                    'git',
                    'rev-parse',
                    $source . '^{tree}',
                )
            ) {
                throw new InvalidArgumentException('Recorded merge differs from the validated squash candidate');
            }
            $this->dispatch('main', $mainSha);
            return;
        }
        if ($this->api('git/ref/heads/' . $branch)['object']['sha'] !== $source) {
            throw new InvalidArgumentException('Candidate branch advanced after quality validation');
        }
        if ($parent !== $mainSha) {
            echo "Main advanced; this candidate is obsolete\n";
            return;
        }
        $this->publishQualityStatus($source, $qualityRunId);
        $this->check([
            'gh',
            'pr',
            'merge',
            (string) $pr['number'],
            '--repo',
            self::REPOSITORY,
            '--squash',
            '--match-head-commit',
            $source,
        ]);
        $merged = json_decode(
            $this->command(
                'gh',
                'pr',
                'view',
                (string) $pr['number'],
                '--repo',
                self::REPOSITORY,
                '--json',
                'state,mergeCommit',
            ),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        if ($merged['state'] !== 'MERGED') {
            throw new InvalidArgumentException('Protected merge did not complete');
        }
        $this->dispatch('main', $merged['mergeCommit']['oid']);
    }
}
