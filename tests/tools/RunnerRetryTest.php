<?php

declare(strict_types=1);

require_once __DIR__ . '/support.php';
require_once ROOT . '/scripts/lib/RunnerRetry.php';

use PhpProfiler\Tooling\RunnerRetry;

const RETRY_REPOSITORY = 'owner/repository';

const RETRY_SHA = '0123456789abcdef0123456789abcdef01234567';

/** A failed first attempt of a quality run on the current branch head. */
function retryRun(array $changes = []): array
{
    return array_replace([
        'name' => 'quality',
        'status' => 'completed',
        'conclusion' => 'failure',
        'head_sha' => RETRY_SHA,
        'head_branch' => 'main',
        'head_repository' => ['full_name' => RETRY_REPOSITORY],
        'run_attempt' => 1,
    ], $changes);
}

function retryJob(string $name, string $conclusion, array $changes = []): array
{
    $ran = !in_array($conclusion, ['cancelled', 'skipped'], true);
    return array_replace([
        'name' => $name,
        'conclusion' => $conclusion,
        'run_attempt' => 1,
        'runner_name' => $ran ? 'GitHub Actions 1000020387' : '',
        'steps' => $ran ? [['name' => 'Set up job', 'conclusion' => 'success']] : [],
    ], $changes);
}

/** The macOS job GitHub never assigned, beside passing jobs and the failed gate. */
function unassignedJobs(): array
{
    return [
        retryJob('coverage', 'success'),
        retryJob('zts-thread-isolation', 'cancelled'),
        retryJob('quality-gate', 'failure'),
        retryJob('release-continuation', 'skipped'),
    ];
}

/**
 * Runs RunnerRetry::retry() against attempts that follow one another on each rerun; each entry
 * is [run changes, jobs]. Returns the outcome, the reruns, the release dispatches and sleeps.
 */
function retryScenario(array $attempts, array $run = [], int $polls = 330, bool $finishes = true): array
{
    $state = ['attempt' => 0, 'reruns' => 0, 'releases' => 0, 'sleeps' => 0];
    $current = static function () use (&$state, $attempts, $run, $finishes): array {
        [$changes] = $attempts[$state['attempt']];
        $number = $state['attempt'] + 1;
        $status = $finishes || $state['attempt'] === 0 ? 'completed' : 'in_progress';
        return retryRun([...$run, ...$changes, 'run_attempt' => $number, 'status' => $status]);
    };
    $get = static function (string $path) use (&$state, $attempts, $current): mixed {
        if ($path === 'actions/runs/7') {
            return $current();
        }
        $number = $state['attempt'] + 1;
        same("actions/runs/7/attempts/{$number}/jobs?per_page=100", $path);
        return ['jobs' => array_map(static fn(array $job): array => [
            ...$job,
            'run_attempt' => $number,
        ], $attempts[$state['attempt']][1])];
    };
    $outcome = RunnerRetry::retry(
        7,
        RETRY_REPOSITORY,
        $get,
        static fn(string $branch): ?string => $branch === 'gone' ? null : RETRY_SHA,
        static function () use (&$state): void {
            $state['reruns']++;
            $state['attempt']++;
        },
        static function () use (&$state): void {
            $state['releases']++;
        },
        static function (int $seconds) use (&$state): void {
            same(60, $seconds);
            $state['sleeps']++;
        },
        $polls,
    );
    return [$outcome, $state['reruns'], $state['releases'], $state['sleeps']];
}

/** Jobs where every job passed. */
function passedJobs(): array
{
    return [
        retryJob('coverage', 'success'),
        retryJob('zts-thread-isolation', 'success'),
        retryJob('quality-gate', 'success'),
    ];
}

return [
    'a job that never got a runner is rerun' => static function (): void {
        same(true, RunnerRetry::shouldRerun(retryRun(), unassignedJobs(), RETRY_REPOSITORY, RETRY_SHA));
        // A null runner name, as the API gives for jobs that never queued, counts as none.
        $jobs = unassignedJobs();
        $jobs[1]['runner_name'] = null;
        same(true, RunnerRetry::shouldRerun(retryRun(), $jobs, RETRY_REPOSITORY, RETRY_SHA));
        same(true, RunnerRetry::shouldRerun(
            retryRun(['run_attempt' => 2]),
            array_map(static fn(array $job): array => [...$job, 'run_attempt' => 2], unassignedJobs()),
            RETRY_REPOSITORY,
            RETRY_SHA,
        ));
    },
    'a failure in code that ran is never rerun' => static function (): void {
        foreach ([
            'failed job' => retryJob('integration (8.3, nts)', 'failure'),
            'cancelled after starting' => retryJob('zts-thread-isolation', 'cancelled', [
                'runner_name' => 'GitHub Actions 1000021951',
            ]),
            'cancelled with steps' => retryJob('address-sanitizer', 'cancelled', [
                'steps' => [['name' => 'Set up job', 'conclusion' => 'success']],
            ]),
            'timed out' => retryJob('mutation', 'timed_out'),
            'still pending' => retryJob('mutation', 'cancelled', ['conclusion' => null]),
            'earlier attempt' => retryJob('address-sanitizer', 'cancelled', ['run_attempt' => 2]),
        ] as $case => $job) {
            $jobs = [...unassignedJobs(), $job];
            same(false, RunnerRetry::shouldRerun(retryRun(), $jobs, RETRY_REPOSITORY, RETRY_SHA), $case . ':');
        }
        // A failed gate alone, with every job it needs successful, is not a missing runner.
        same(false, RunnerRetry::shouldRerun(
            retryRun(),
            [
                retryJob('coverage', 'success'),
                retryJob('quality-gate', 'failure'),
            ],
            RETRY_REPOSITORY,
            RETRY_SHA,
        ));
        same(false, RunnerRetry::shouldRerun(retryRun(), [], RETRY_REPOSITORY, RETRY_SHA));
    },
    'only a failed, current quality run of this repository is rerun' => static function (): void {
        foreach ([
            'superseded run' => [retryRun(['conclusion' => 'cancelled']), RETRY_SHA],
            'successful run' => [retryRun(['conclusion' => 'success']), RETRY_SHA],
            'running' => [retryRun(['status' => 'in_progress']), RETRY_SHA],
            'other workflow' => [retryRun(['name' => 'automatic-alpha-release']), RETRY_SHA],
            'fork' => [retryRun(['head_repository' => ['full_name' => 'fork/repository']]), RETRY_SHA],
            'no fork repository' => [retryRun(['head_repository' => null]), RETRY_SHA],
            'retries spent' => [retryRun(['run_attempt' => RunnerRetry::MAX_ATTEMPTS]), RETRY_SHA],
            'missing attempt' => [retryRun(['run_attempt' => '1']), RETRY_SHA],
            'newer commit' => [retryRun(), str_repeat('f', 40)],
            'deleted branch' => [retryRun(), null],
        ] as $case => [$run, $head]) {
            same(false, RunnerRetry::shouldRerun($run, unassignedJobs(), RETRY_REPOSITORY, $head), $case . ':');
        }
        // The last attempt's own unassigned jobs are not rerun again.
        $last = RunnerRetry::MAX_ATTEMPTS;
        $jobs = array_map(static fn(array $job): array => [...$job, 'run_attempt' => $last], unassignedJobs());
        same(false, RunnerRetry::shouldRerun(retryRun(['run_attempt' => $last]), $jobs, RETRY_REPOSITORY, RETRY_SHA));
    },
    'an unassigned follower alone is rerun' => static function (): void {
        $jobs = [retryJob('coverage', 'success'), retryJob('quality-gate', 'cancelled')];
        same(true, RunnerRetry::shouldRerun(retryRun(), $jobs, RETRY_REPOSITORY, RETRY_SHA));
    },
    'a main push run that passes on a rerun continues the release' => static function (): void {
        $failed = [['conclusion' => 'failure'], unassignedJobs()];
        $passed = [['conclusion' => 'success'], passedJobs()];
        [$outcome, $reruns, $releases, $sleeps] = retryScenario([$failed, $passed], ['event' => 'push']);
        same([1, 1, 1], [$reruns, $releases, $sleeps], $outcome);
        same('quality run 7 attempt 2: success; release continued', $outcome);
        // A second missing runner gets the last attempt.
        [$outcome, $reruns, $releases] = retryScenario([$failed, $failed, $passed], ['event' => 'push']);
        same([2, 1], [$reruns, $releases], $outcome);
    },
    'reruns stop at the last attempt and never release a failure' => static function (): void {
        $failed = [['conclusion' => 'failure'], unassignedJobs()];
        [$outcome, $reruns, $releases] = retryScenario([$failed, $failed, $failed], ['event' => 'push']);
        same([2, 0], [$reruns, $releases], $outcome);
        same('quality run 7 attempt 3: failure', $outcome);
    },
    'only a main push run is released by the retry' => static function (): void {
        $failed = [['conclusion' => 'failure'], unassignedJobs()];
        $passed = [['conclusion' => 'success'], passedJobs()];
        // Dispatched runs continue through their own release-continuation job.
        foreach ([
            ['event' => 'pull_request'],
            ['event' => 'workflow_dispatch'],
            ['event' => 'push', 'head_branch' => 'feature'],
        ] as $run) {
            [$outcome, $reruns, $releases] = retryScenario([$failed, $passed], $run);
            same([1, 0], [$reruns, $releases], $outcome);
        }
    },
    'a run that needs no rerun is left alone' => static function (): void {
        [$outcome, $reruns, $releases, $sleeps] = retryScenario([[['conclusion' => 'success'], passedJobs()]], [
            'event' => 'push',
        ]);
        same([0, 0, 0], [$reruns, $releases, $sleeps]);
        same('quality run 7 attempt 1: no rerun', $outcome);
        [$outcome, $reruns] = retryScenario([[['conclusion' => 'failure'], unassignedJobs()]], [
            'head_branch' => 'gone',
        ]);
        same(0, $reruns, $outcome);
    },
    'a rerun that never completes stops the wait' => static function (): void {
        $failed = [['conclusion' => 'failure'], unassignedJobs()];
        [$outcome, $reruns, $releases, $sleeps] = retryScenario([$failed, $failed], ['event' => 'push'], 2, false);
        same([1, 0, 2], [$reruns, $releases, $sleeps]);
        same('quality run 7: attempt 2 did not complete in time', $outcome);
    },
    'unexpected API answers stop without a rerun' => static function (): void {
        $calls = 0;
        $never = static function () use (&$calls): void {
            $calls++;
        };
        foreach ([
            static fn(string $path): mixed => ['message' => 'Not Found'],
            static fn(string $path): mixed => str_ends_with($path, '/7') ? retryRun() : ['message' => 'rate limited'],
            static fn(string $path): mixed => null,
        ] as $get) {
            $outcome = RunnerRetry::retry(
                7,
                RETRY_REPOSITORY,
                $get,
                static fn(): string => RETRY_SHA,
                $never,
                $never,
                $never,
            );
            same(true, str_starts_with($outcome, 'quality run 7'), $outcome);
        }
        same(0, $calls);
    },
    'the wait ignores the previous attempt until the new one completes' => static function (): void {
        // Right after a rerun, the API can still show the completed previous attempt.
        $runs = [
            retryRun(['event' => 'push']),
            retryRun(['event' => 'push']),
            retryRun(['event' => 'push', 'run_attempt' => 2, 'status' => 'in_progress', 'conclusion' => null]),
            retryRun(['event' => 'push', 'run_attempt' => 2, 'conclusion' => 'success']),
        ];
        $counts = ['reruns' => 0, 'releases' => 0, 'sleeps' => 0];
        $get = static function (string $path) use (&$runs): mixed {
            if ($path === 'actions/runs/7') {
                return count($runs) > 1 ? array_shift($runs) : $runs[0];
            }
            return ['jobs' => str_contains($path, '/attempts/1/') ? unassignedJobs() : passedJobs()];
        };
        $outcome = RunnerRetry::retry(
            7,
            RETRY_REPOSITORY,
            $get,
            static fn(): string => RETRY_SHA,
            static function () use (&$counts): void {
                $counts['reruns']++;
            },
            static function () use (&$counts): void {
                $counts['releases']++;
            },
            static function () use (&$counts): void {
                $counts['sleeps']++;
            },
        );
        same(['reruns' => 1, 'releases' => 1, 'sleeps' => 3], $counts, $outcome);
    },
];
