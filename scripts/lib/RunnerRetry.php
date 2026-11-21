<?php

declare(strict_types=1);

namespace PhpProfiler\Tooling;

use Closure;

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
            if (in_array($conclusion, ['success', 'skipped'], true)) {
                continue;
            }
            $neverRan =
                $conclusion === 'cancelled'
                && (string) ($job['runner_name'] ?? '') === ''
                && ($job['steps'] ?? []) === []
                && ($job['run_attempt'] ?? null) === $run['run_attempt'];
            if ($neverRan) {
                $unassigned++;
            } elseif (!in_array($job['name'] ?? null, self::FOLLOWERS, true)) {
                // A job that ran and failed, or one still pending, is not a missing runner. The
                // followers fail because of the others, unless they themselves never ran.
                return false;
            }
        }
        return $unassigned > 0;
    }

    /**
     * Reruns run $runId while shouldRerun() holds, waiting for each new attempt to complete.
     * Events caused by the default token start no workflow, so a rerun's completion may not
     * reach this workflow or the release: this job waits for it, and continues the release
     * itself when a main push run passes on a rerun, as release-continuation does for
     * dispatched runs. Returns what happened.
     *
     * @param Closure(string): mixed $get GETs a repository API path
     * @param Closure(string): ?string $branchHead the branch's current head, null when gone
     * @param Closure(): void $rerun reruns the run's failed jobs
     * @param Closure(): void $continueRelease dispatches the release for this run
     * @param Closure(int): void $sleep waits that many seconds
     */
    public static function retry(
        int $runId,
        string $repository,
        Closure $get,
        Closure $branchHead,
        Closure $rerun,
        Closure $continueRelease,
        Closure $sleep,
        int $polls = 330,
    ): string {
        $reran = false;
        while (true) {
            $run = $get("actions/runs/{$runId}");
            $attempt = is_array($run) ? $run['run_attempt'] ?? null : null;
            if (!is_int($attempt)) {
                return "quality run {$runId}: no attempt to inspect";
            }
            $jobs = $get("actions/runs/{$runId}/attempts/{$attempt}/jobs?per_page=100");
            if (!is_array($jobs) || !is_array($jobs['jobs'] ?? null)) {
                return "quality run {$runId} attempt {$attempt}: no jobs to inspect";
            }
            if (!self::shouldRerun(
                $run,
                $jobs['jobs'],
                $repository,
                $branchHead((string) ($run['head_branch'] ?? '')),
            )) {
                break;
            }
            $rerun();
            $reran = true;
            for ($poll = 0;; $poll++) {
                if ($poll === $polls) {
                    return "quality run {$runId}: attempt " . ($attempt + 1) . ' did not complete in time';
                }
                $sleep(60);
                $next = $get("actions/runs/{$runId}");
                if (
                    is_array($next)
                    && is_int($next['run_attempt'] ?? null)
                    && $next['run_attempt'] > $attempt
                    && ($next['status'] ?? null) === 'completed'
                ) {
                    break;
                }
            }
        }
        $outcome = "quality run {$runId} attempt {$attempt}: " . ($run['conclusion'] ?? 'unknown');
        if (!$reran) {
            return "quality run {$runId} attempt {$attempt}: no rerun";
        }
        if (
            ($run['conclusion'] ?? null) === 'success'
            && ($run['event'] ?? null) === 'push'
            && ($run['head_branch'] ?? null) === 'main'
        ) {
            $continueRelease();
            return $outcome . '; release continued';
        }
        return $outcome;
    }
}
