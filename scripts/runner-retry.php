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

$get = static fn(string $path): mixed => json_decode(
    Process::output(['gh', 'api', "repos/{$repository}/{$path}"]),
    true,
    flags: JSON_THROW_ON_ERROR,
);
$branchHead = static function (string $branch) use ($repository): ?string {
    if ($branch === '') {
        return null;
    }
    $result = Process::run([
        'gh',
        'api',
        "repos/{$repository}/branches/" . rawurlencode($branch),
        '--jq',
        '.commit.sha',
    ]);
    return $result['status'] === 0 ? trim($result['stdout']) : null;
};
echo
    RunnerRetry::retry(
        (int) $runId,
        $repository,
        $get,
        $branchHead,
        static fn() => Process::check([
            'gh',
            'api',
            '--method',
            'POST',
            "repos/{$repository}/actions/runs/{$runId}/rerun-failed-jobs",
        ]),
        static fn() => Process::check([
            'gh',
            'workflow',
            'run',
            'release.yml',
            '--repo',
            $repository,
            '--ref',
            'main',
            '-f',
            "quality_run_id={$runId}",
        ]),
        static fn(int $seconds) => sleep($seconds),
    ),
    "\n"
;
