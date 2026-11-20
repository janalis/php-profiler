<?php

declare(strict_types=1);

// Reruns a failed quality run's jobs when GitHub never assigned them a runner.
require_once __DIR__ . '/lib/Arguments.php';
require_once __DIR__ . '/lib/Fail.php';
require_once __DIR__ . '/lib/Process.php';
require_once __DIR__ . '/lib/RunnerRetry.php';

use PhpProfiler\Tooling\Arguments;
use PhpProfiler\Tooling\Process;
use PhpProfiler\Tooling\RunnerRetry;

$usage = 'runner-retry.php <repository> <quality-run-id>';
['positionals' => $positionals] = Arguments::parse($argv, [], usage: $usage);
if (
    count($positionals) !== 2
    || !preg_match('/\A[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+\z/D', $positionals[0])
    || !preg_match('/\A\d+\z/D', $positionals[1])
) {
    Arguments::fail('expected a repository and one quality run id', $usage);
}
[$repository, $runId] = $positionals;

$api = static fn(string $path): array => json_decode(
    Process::output(['gh', 'api', "repos/{$repository}/{$path}"]),
    true,
    flags: JSON_THROW_ON_ERROR,
);
$run = $api("actions/runs/{$runId}");
$jobs = $api("actions/runs/{$runId}/attempts/{$run['run_attempt']}/jobs?per_page=100")['jobs'];
$branch = (string) ($run['head_branch'] ?? '');
$branchHead = null;
if ($branch !== '') {
    $status = Process::run([
        'gh',
        'api',
        "repos/{$repository}/branches/" . rawurlencode($branch),
        '--jq',
        '.commit.sha',
    ]);
    $branchHead = $status['status'] === 0 ? trim($status['stdout']) : null;
}
if (!RunnerRetry::shouldRerun($run, $jobs, $repository, $branchHead)) {
    echo "quality run {$runId} attempt {$run['run_attempt']}: no rerun\n";
    exit(0);
}
Process::check(['gh', 'api', '--method', 'POST', "repos/{$repository}/actions/runs/{$runId}/rerun-failed-jobs"]);
echo "quality run {$runId} attempt {$run['run_attempt']}: rerunning jobs that never got a runner\n";
