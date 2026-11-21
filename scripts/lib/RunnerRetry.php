<?php

declare(strict_types=1);

namespace PhpProfiler\Tooling;

/**
 * Decides whether a failed quality run failed only because GitHub never assigned a runner to
 * some of its jobs, as happens when no hosted macOS runner is free. Those jobs are cancelled
 * with no runner and no step, and the quality gate fails without any code having been tested.
 */
final class RunnerRetry
{
    /** Attempts a run may reach through these reruns: the original and two retries. */
    public const int MAX_ATTEMPTS = 3;

    /** The jobs that only summarize or continue from the others. */
    private const array FOLLOWERS = ['quality-gate', 'release-continuation'];

    /**
     * @param array<string, mixed> $run the workflow run, as the REST API returns it
     * @param list<array<string, mixed>> $jobs the jobs of the run's latest attempt
     * @param ?string $branchHead the current head of the run's branch, or null when it is gone
     */
    public static function shouldRerun(array $run, array $jobs, string $repository, ?string $branchHead): bool
    {
        if (
            ($run['name'] ?? null) !== 'quality'
            || ($run['status'] ?? null) !== 'completed'
            || ($run['conclusion'] ?? null) !== 'failure'
            || ($run['head_repository']['full_name'] ?? null) !== $repository
            || ($run['run_attempt'] ?? self::MAX_ATTEMPTS) >= self::MAX_ATTEMPTS
            // A newer commit's run shares the concurrency group, and a rerun would cancel it.
            || ($run['head_sha'] ?? null) !== $branchHead
        ) {
            return false;
        }
        $unassigned = 0;
        foreach ($jobs as $job) {
            $conclusion = $job['conclusion'] ?? null;
            if (
                in_array($conclusion, ['success', 'skipped'], true)
                || in_array($job['name'] ?? null, self::FOLLOWERS, true)
            ) {
                continue;
            }
            if (
                $conclusion !== 'cancelled'
                || (string) ($job['runner_name'] ?? '') !== ''
                || ($job['steps'] ?? []) !== []
                || ($job['run_attempt'] ?? null) !== $run['run_attempt']
            ) {
                // A job that ran and failed, or one still pending, is not a missing runner.
                return false;
            }
            $unassigned++;
        }
        return $unassigned > 0;
    }
}
