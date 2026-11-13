<?php

declare(strict_types=1);

use PhpProfiler\Profiler;

function zts_profiled_leaf(int $token): int
{
    usleep(50000);
    return $token + 1;
}

function profiler_worker_shared_factory(): Closure
{
    return static function (int $token): int {
        return $token + 7;
    };
}

function profiler_worker_eval_factory(int $value): Closure
{
    return eval('return static function(): int {return ' . $value . ';};');
}

function profiler_worker_exercise(int $token, ?string $output = null): array
{
    if (Profiler::isEnabled()) {
        throw new RuntimeException('enabled state leaked across requests');
    }
    $session = Profiler::start($output, ['timeline' => true]);
    $probe = profiler_worker_shared_factory();
    $first = $probe($token);
    $storage = function_exists('profiler_test_closure_storage')
        ? profiler_test_closure_storage($probe)
        : (function_exists('profiler_embed_closure_storage') ? profiler_embed_closure_storage($probe) : null);
    $result = zts_profiled_leaf($token);
    $checksum = $result + $first;
    for ($i = 0; $i < 12; $i++) {
        $closure = $i === 0 ? $probe : profiler_worker_shared_factory();
        $checksum += $closure($token);
        if ($i !== 0) {
            $checksum += $closure($token);
        }
        unset($closure);
    }
    for ($i = 0; $i < 20; $i++) {
        $closure = profiler_worker_eval_factory(($token * 100) + $i);
        $checksum += $closure() + $closure();
        unset($closure);
    }
    $session->stop();
    $report = $session->getReport();
    $rows = array_filter(
        $report['functions'],
        static fn(array $metrics, string $name): bool => preg_match('/(?:^|::)\{closure#\d+\}$/', $name) === 1,
        ARRAY_FILTER_USE_BOTH,
    );
    $counts = array_count_values(array_column(array_values($rows), 'calls'));
    ksort($counts);
    if ($session->getId() !== 1 || $result !== ($token + 1) || $checksum !== ((4025 * $token) + 549)) {
        throw new RuntimeException('request state or application result leaked');
    }
    if (
        $report['status'] !== 'complete'
        || count($rows) !== 21
        || $counts !== [2 => 20, 24 => 1]
        || array_sum(array_column($rows, 'calls')) !== 64
        || ($report['functions']['zts_profiled_leaf']['calls'] ?? 0) !== 1
        || ($report['functions']['usleep']['calls'] ?? 0) !== 1
    ) {
        throw new RuntimeException('closure cache lifetimes or request call counts leaked: ' . json_encode($counts));
    }
    $status = function_exists('opcache_get_status') ? opcache_get_status(false) : false;
    return [
        'pid' => getmypid(),
        'id' => $session->getId(),
        'token' => $token,
        'result' => $result,
        'checksum' => $checksum,
        'report' => $report,
        'path' => $session->getReportPath(),
        'storage' => $storage,
        'opcache_enabled' => is_array($status) && ($status['opcache_enabled'] ?? false),
        'shared_file_cached' => function_exists('opcache_is_script_cached') && opcache_is_script_cached(__FILE__),
        'jit' => $status['jit'] ?? null,
    ];
}
