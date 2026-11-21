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
];
