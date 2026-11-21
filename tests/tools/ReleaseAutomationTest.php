<?php

declare(strict_types=1);

require_once __DIR__ . '/support.php';
require_once ROOT . '/scripts/lib/ReleaseAutomation.php';

use PhpProfiler\Tooling\ProcessFailedStatus;
use PhpProfiler\Tooling\ReleaseAutomation;
use PhpProfiler\Tooling\ReleaseRunner;
use PhpProfiler\Tooling\ReleaseTimeout;

/** A successful protected merge remains resumable after main dispatch fails. */
final class ReleaseTransport implements ReleaseRunner
{
    public string $base;
    public string $source;
    public string $merged;
    public string $branch;
    public string $main;
    public bool $branchExists = true;
    public bool $failDispatch = true;
    public int $merges = 0;
    public array $dispatches = [];
    public array $statuses = [];
    public array $qualityReceipts = [];
    public array $calls = [];
    public array $sleeps = [];
    /** @var list<array<string, mixed>> the pull request runs GitHub lists for the candidate */
    public array $pullRequestRuns = [];
    public bool $failRunList = false;
    public array $failDeletes = [];
    public array $deleted = [];
    public string $tree;
    public array $pr;
    public array $quality;

    public function __construct()
    {
        $this->base = str_repeat('a', 40);
        $this->source = str_repeat('b', 40);
        $this->merged = str_repeat('c', 40);
        $this->tree = str_repeat('d', 40);
        $this->branch = 'release/0.1.0-alpha.1-' . substr($this->base, 0, 12);
        $this->main = $this->base;
        $this->pr = [
            'number' => 17,
            'headRefOid' => $this->source,
            'baseRefName' => 'main',
            'author' => ['login' => 'github-actions[bot]'],
            'state' => 'OPEN',
            'mergeCommit' => null,
        ];
        $this->quality = [
            'status' => 'completed',
            'name' => 'quality',
            'conclusion' => 'success',
            'head_sha' => $this->source,
            'head_branch' => $this->branch,
            'head_repository' => ['full_name' => ReleaseAutomation::REPOSITORY],
            'event' => 'workflow_dispatch',
            'actor' => ['login' => 'github-actions[bot]'],
        ];
    }

    public function output(array $command, ?string $directory = null): string
    {
        $this->calls[] = $command;
        $repository = 'repos/' . ReleaseAutomation::REPOSITORY . '/';
        return match (true) {
            $command === ['git', 'rev-parse', 'HEAD'] => $this->main . "\n",
            $command === ['git', 'rev-parse', $this->source . '^'],
            $command === ['git', 'rev-parse', $this->merged . '^'],
                => $this->base . "\n",
            $command === ['git', 'rev-parse', $this->merged . '^{tree}'] => $this->tree . "\n",
            $command === ['git', 'rev-parse', $this->source . '^{tree}'] => str_repeat('d', 40) . "\n",
            $command === ['gh', 'api', $repository . 'actions/runs/42'] => json_encode(
                $this->qualityReceipts !== [] ? array_shift($this->qualityReceipts) : $this->quality,
            ),
            $command === ['gh', 'api', $repository . 'git/ref/heads/main'] => json_encode(['object' => [
                'sha' => $this->main,
            ]]),
            $command === ['gh', 'api', $repository . 'git/ref/heads/' . $this->branch] => $this->branchExists
                ? json_encode(['object' => ['sha' => $this->source]])
                : throw new LogicException('Deleted branch must not be queried during recovery'),
            $command === [
                'gh',
                'api',
                $repository . "actions/runs?event=pull_request&head_sha={$this->source}&per_page=100",
            ]
                => $this->failRunList
                ? throw new ProcessFailedStatus($command, 1, '')
                : json_encode(['workflow_runs' => $this->pullRequestRuns]),
            array_slice($command, 0, 3) === ['gh', 'pr', 'list'] => $command[array_search('--state', $command, true)
                + 1] === 'all'
                    ? json_encode([$this->pr])
                    : throw new LogicException('pull request list must include merged requests'),
            array_slice($command, 0, 3) === ['gh', 'pr', 'view'] => json_encode($this->pr),
            default => throw new LogicException('Unexpected output command: ' . json_encode($command)),
        };
    }

    public function run(array $command, ?string $directory = null): int
    {
        $this->calls[] = $command;
        $repository = ReleaseAutomation::REPOSITORY;
        if ($command === ['git', 'fetch', 'origin', 'refs/pull/17/head']) {
            return 0;
        }
        if (
            count($command) === 5
            && array_slice($command, 0, 4) === ['gh', 'api', '--method', 'DELETE']
            && preg_match(
                '/\\Arepos\\/' . preg_quote($repository, '/') . '\\/actions\\/runs\\/(\\d+)\\z/D',
                $command[4],
                $m,
            )
        ) {
            same(0, $this->merges, 'held runs are deleted before the merge');
            $this->deleted[] = (int) $m[1];
            return in_array((int) $m[1], $this->failDeletes, true) ? 1 : 0;
        }
        if (array_slice($command, 0, 4) === ['gh', 'api', '--method', 'POST']) {
            same([
                'gh',
                'api',
                '--method',
                'POST',
                "repos/{$repository}/statuses/{$this->source}",
                '-f',
                'state=success',
                '-f',
                'context=quality-gate',
                '-f',
                "target_url=https://github.com/{$repository}/actions/runs/42",
                '-f',
                'description=Full exact-SHA quality workflow passed',
            ], $command);
            $this->statuses[] = $this->source;
            return 0;
        }
        if (array_slice($command, 0, 3) === ['gh', 'pr', 'merge']) {
            same('OPEN', $this->pr['state']);
            same(['--match-head-commit', $this->source], array_slice($command, -2));
            $this->merges++;
            $this->main = $this->merged;
            $this->pr = [...$this->pr, 'state' => 'MERGED', 'mergeCommit' => ['oid' => $this->merged]];
            $this->branchExists = false;
            return 0;
        }
        if (array_slice($command, 0, 3) === ['gh', 'workflow', 'run']) {
            same([
                'gh',
                'workflow',
                'run',
                'ci.yml',
                '--repo',
                $repository,
                '--ref',
                'main',
                '-f',
                'expected_sha=' . $this->merged,
            ], $command);
            $this->dispatches[] = $this->merged;
            return $this->failDispatch ? 1 : 0;
        }
        throw new LogicException('Unexpected mutation command: ' . json_encode($command));
    }

    public function fetch(string $url): string
    {
        throw new LogicException('no download expected');
    }

    public function sleep(int $seconds): void
    {
        $this->sleeps[] = $seconds;
    }

    public function timeNs(): int
    {
        return 0;
    }
}

/** Runs the automation once; validation is recorded instead of reading git objects. */
function automate(ReleaseTransport $transport, ?Closure $validate = null): array
{
    $automation = new ReleaseAutomation($transport, ROOT);
    $validated = [];
    $automation->validateCandidate = $validate ?? static function (string ...$arguments) use (&$validated): void {
        $validated[] = $arguments;
    };
    ob_start();
    try {
        $automation->main(42);
    } finally {
        ob_end_clean();
    }
    same([[$transport->base, $transport->source, '0.1.0-alpha.1']], $validated);
    return $validated;
}

function mergedTransport(): ReleaseTransport
{
    $transport = new ReleaseTransport();
    $transport->main = $transport->merged;
    $transport->branchExists = false;
    $transport->pr = [...$transport->pr, 'state' => 'MERGED', 'mergeCommit' => ['oid' => $transport->merged]];
    $transport->failDispatch = false;
    return $transport;
}

function callIndex(ReleaseTransport $transport, Closure $matches): int
{
    foreach ($transport->calls as $index => $call) {
        if ($matches($call)) {
            return $index;
        }
    }
    throw new LogicException('call not found');
}

function rejectsAutomation(ReleaseTransport $transport, string $message): void
{
    $error = throws(InvalidArgumentException::class, static fn() => automate($transport));
    same(true, str_contains($error->getMessage(), $message), $error->getMessage());
}

return [
    'merge then failed dispatch retries without the deleted branch or a duplicate merge' => static function (): void {
        $transport = new ReleaseTransport();
        throws(ProcessFailedStatus::class, static fn() => automate($transport));
        same([1, false], [$transport->merges, $transport->branchExists]);
        $transport->failDispatch = false;
        automate($transport);
        same(1, $transport->merges);
        same([$transport->merged, $transport->merged], $transport->dispatches);
        same(22, count($transport->calls));
        same([$transport->source], $transport->statuses);
    },
    'actual main waits for overall quality completion before status or merge' => static function (): void {
        $transport = new ReleaseTransport();
        $transport->failDispatch = false;
        $transport->qualityReceipts = [[...$transport->quality, 'status' => 'in_progress', 'conclusion' => null]];
        automate($transport);
        same([5], $transport->sleeps);
        same([[$transport->source], 1], [$transport->statuses, $transport->merges]);
        $polls = array_keys(array_filter(
            $transport->calls,
            static fn(array $call): bool => $call === [
                'gh',
                'api',
                'repos/' . ReleaseAutomation::REPOSITORY . '/actions/runs/42',
            ],
        ));
        same(2, count($polls));
        same(
            true,
            end($polls) < callIndex(
                $transport,
                static fn(array $call): bool => array_slice($call, 0, 4) === ['gh', 'api', '--method', 'POST'],
            ),
        );
    },
    'actual main terminal failure cannot project success or merge' => static function (): void {
        $transport = new ReleaseTransport();
        $transport->quality['conclusion'] = 'failure';
        $error = throws(InvalidArgumentException::class, static fn() => automate($transport));
        same(true, str_contains($error->getMessage(), 'without success'));
        same([[], 0], [$transport->statuses, $transport->merges]);
    },
    'full candidate validation precedes status projection and protected merge' => static function (): void {
        $transport = new ReleaseTransport();
        $transport->failDispatch = false;
        automate($transport);
        $status = callIndex(
            $transport,
            static fn(array $call): bool => array_slice($call, 0, 4) === ['gh', 'api', '--method', 'POST'],
        );
        $branch = callIndex(
            $transport,
            static fn(array $call): bool => (
                end($call) === 'repos/' . ReleaseAutomation::REPOSITORY . '/git/ref/heads/' . $transport->branch
            ),
        );
        $merge = callIndex(
            $transport,
            static fn(array $call): bool => array_slice($call, 0, 3) === ['gh', 'pr', 'merge'],
        );
        same(true, $branch < $status && $status < $merge);
        same([$transport->source], $transport->statuses);
    },
    'failed metadata validation cannot publish a success status' => static function (): void {
        $transport = new ReleaseTransport();
        $error = throws(InvalidArgumentException::class, static fn() => automate(
            $transport,
            static fn() => throw new InvalidArgumentException('invalid metadata'),
        ));
        same('invalid metadata', $error->getMessage());
        same([[], 0], [$transport->statuses, $transport->merges]);
    },
    'wrong quality source cannot publish a success status' => static function (): void {
        $transport = new ReleaseTransport();
        $transport->quality['head_sha'] = str_repeat('e', 40);
        rejectsAutomation($transport, 'Expected one bot pull request');
        same([[], 0], [$transport->statuses, $transport->merges]);
    },
    'recovery rejects a foreign author, wrong head and wrong base' => static function (): void {
        foreach ([
            ['author', ['login' => 'someone-else']],
            ['headRefOid', str_repeat('e', 40)],
            ['baseRefName', 'other'],
        ] as [$field, $value]) {
            $transport = mergedTransport();
            $transport->pr[$field] = $value;
            rejectsAutomation($transport, 'Expected one bot pull request');
            same([[], 0], [$transport->dispatches, $transport->merges]);
        }
    },
    'recovery rejects a missing or unrelated merge and an advanced main' => static function (): void {
        foreach ([
            [null, str_repeat('c', 40)],
            [['oid' => str_repeat('e', 40)], str_repeat('c', 40)],
            [['oid' => str_repeat('c', 40)], str_repeat('e', 40)],
        ] as [$mergeCommit, $main]) {
            $transport = mergedTransport();
            $transport->pr['mergeCommit'] = $mergeCommit;
            $transport->main = $main;
            rejectsAutomation($transport, 'no longer identifies current main');
            same([], $transport->dispatches);
        }
    },
    'recorded merge must have the validated candidate tree' => static function (): void {
        $transport = mergedTransport();
        $transport->tree = str_repeat('e', 40);
        rejectsAutomation($transport, 'differs from the validated squash candidate');
        same([], $transport->dispatches);
    },
    'recovery preserves quality actor and repository guards' => static function (): void {
        foreach ([['actor', ['login' => 'someone-else']], ['head_repository', ['full_name' => 'other/repository']]] as [
            $field,
            $value,
        ]) {
            $transport = mergedTransport();
            $transport->quality[$field] = $value;
            throws(InvalidArgumentException::class, static fn() => automate($transport));
            same([[], 0], [$transport->dispatches, $transport->merges]);
        }
    },
    'completion waits a bounded number of polls and never accepts failure' => static function (): void {
        $transport = new ReleaseTransport();
        $transport->qualityReceipts = [
            ['status' => 'in_progress'],
            ['status' => 'completed', 'conclusion' => 'success'],
        ];
        same('success', (new ReleaseAutomation($transport, ROOT))->completedRun(42)['conclusion']);
        same([5], $transport->sleeps);
        foreach (['failure', 'cancelled', 'action_required', 'timed_out', null] as $conclusion) {
            $transport = new ReleaseTransport();
            $transport->quality = ['status' => 'completed', 'conclusion' => $conclusion];
            throws(InvalidArgumentException::class, static fn() => (new ReleaseAutomation(
                $transport,
                ROOT,
            ))->completedRun(42));
            same([1, []], [count($transport->calls), $transport->sleeps]);
        }
        $transport = new ReleaseTransport();
        $transport->quality = ['status' => 'in_progress'];
        throws(ReleaseTimeout::class, static fn() => (new ReleaseAutomation($transport, ROOT))->completedRun(42, 3, 2));
        same([3, [2, 2]], [count($transport->calls), $transport->sleeps]);
        $transport->quality = ['status' => 'unexpected'];
        $transport->sleeps = [];
        throws(InvalidArgumentException::class, static fn() => (new ReleaseAutomation($transport, ROOT))->completedRun(
            42,
        ));
        same([], $transport->sleeps);
    },
    'orchestration trusts exact source receipts and dispatches pinned quality runs' => static function (): void {
        $transport = new ReleaseTransport();
        $automation = new ReleaseAutomation($transport, ROOT);
        $source = str_repeat('a', 40);
        $run = [
            'name' => 'quality',
            'conclusion' => 'success',
            'head_sha' => $source,
            'head_branch' => 'main',
            'event' => 'push',
            'head_repository' => ['full_name' => ReleaseAutomation::REPOSITORY],
        ];
        same('main', $automation->validateRun($run, $source));
        foreach ([['head_sha', 'wrong'], ['event', 'pull_request'], ['conclusion', 'failure']] as [$key, $value]) {
            throws(InvalidArgumentException::class, static fn() => $automation->validateRun([
                ...$run,
                $key => $value,
            ], $source));
        }
        $candidate = [
            ...$run,
            'head_branch' => 'release/0.1.0-alpha.1-aaaaaaaaaaaa',
            'event' => 'workflow_dispatch',
            'actor' => ['login' => 'github-actions[bot]'],
        ];
        same(true, str_starts_with($automation->validateRun($candidate, $source), 'release/'));
        throws(InvalidArgumentException::class, static fn() => $automation->validateRun([
            ...$candidate,
            'actor' => ['login' => 'someone-else'],
        ], $source));
        $transport->failDispatch = false;
        $transport->merged = $source;
        $automation->dispatch('main', $source);
        same([
            'gh',
            'workflow',
            'run',
            'ci.yml',
            '--repo',
            ReleaseAutomation::REPOSITORY,
            '--ref',
            'main',
            '-f',
            'expected_sha=' . $source,
        ], end($transport->calls));
    },
    'held bot pull request runs are deleted before the merge and nothing else is' => static function (): void {
        $transport = new ReleaseTransport();
        $transport->failDispatch = false;
        $held = [
            'id' => 7,
            'name' => 'quality',
            'event' => 'pull_request',
            'status' => 'completed',
            'conclusion' => 'action_required',
            'head_sha' => $transport->source,
            'head_repository' => ['full_name' => ReleaseAutomation::REPOSITORY],
            'actor' => ['login' => 'github-actions[bot]'],
        ];
        $transport->pullRequestRuns = [
            $held,
            [...$held, 'id' => 8, 'name' => 'runner-retry'],
            [...$held, 'id' => 9, 'event' => 'push'],
            [...$held, 'id' => 10, 'conclusion' => 'failure'],
            [...$held, 'id' => 11, 'head_sha' => $transport->merged],
            [...$held, 'id' => 12, 'head_repository' => ['full_name' => 'someone/fork']],
            [...$held, 'id' => 13, 'actor' => ['login' => 'someone-else']],
            [...$held, 'id' => 'x14'],
            [...$held, 'id' => 15],
        ];
        automate($transport);
        same([[7, 15], 1], [$transport->deleted, $transport->merges]);
        same(
            true,
            callIndex($transport, static fn(array $call): bool => ($call[3] ?? null) === 'DELETE') > callIndex(
                $transport,
                static fn(array $call): bool => ($call[3] ?? null) === 'POST',
            ),
        );
    },
    'failing to list or delete held runs never stops the release' => static function (): void {
        $transport = new ReleaseTransport();
        $transport->failDispatch = false;
        $transport->failRunList = true;
        automate($transport);
        same([[], 1, [$transport->merged]], [$transport->deleted, $transport->merges, $transport->dispatches]);

        $transport = new ReleaseTransport();
        $transport->failDispatch = false;
        $held = [
            'id' => 7,
            'name' => 'quality',
            'event' => 'pull_request',
            'conclusion' => 'action_required',
            'head_sha' => $transport->source,
            'head_repository' => ['full_name' => ReleaseAutomation::REPOSITORY],
            'actor' => ['login' => 'github-actions[bot]'],
        ];
        $transport->pullRequestRuns = [$held, [...$held, 'id' => 8]];
        $transport->failDeletes = [7];
        automate($transport);
        same([[7, 8], 1, [$transport->merged]], [$transport->deleted, $transport->merges, $transport->dispatches]);
    },
    'an already merged candidate deletes nothing' => static function (): void {
        $transport = mergedTransport();
        automate($transport);
        same([[], 0], [$transport->deleted, $transport->merges]);
    },
];
