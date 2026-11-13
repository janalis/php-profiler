<?php

declare(strict_types=1);

use PhpProfiler\Profiler;

function fpm_benchmark_leaf(int $value): int
{
    return ($value * 3) % 97;
}

$mode = $_SERVER['PROFILE_MODE'];
$iterations = (int) $_SERVER['PROFILE_ITERATIONS'];
$sampling = ($_SERVER['PROFILE_SAMPLING'] ?? '0') === '1';
if ($mode === 'enabled') {
    $session = Profiler::start(null, ['sampling' => $sampling]);
}
$usageBefore = getrusage();
$started = hrtime(true);
$sum = 0;
for ($i = 0; $i < $iterations; ++$i) {
    $sum += fpm_benchmark_leaf($i);
}
$elapsed = hrtime(true) - $started;
$usageAfter = getrusage();
$cpu =
    (
        (
            (
                $usageAfter['ru_utime.tv_sec'] - $usageBefore['ru_utime.tv_sec'] + $usageAfter['ru_stime.tv_sec']
                - $usageBefore['ru_stime.tv_sec']
            )
            * 1000000
        )
        + $usageAfter['ru_utime.tv_usec']
        - $usageBefore['ru_utime.tv_usec']
        + $usageAfter['ru_stime.tv_usec']
        - $usageBefore['ru_stime.tv_usec']
    )
    * 1000;
$report = null;
if ($mode === 'enabled') {
    $session->stop();
    $report = $session->getReport();
}
header('Content-Type: application/json');
echo json_encode([
    'sum' => $sum,
    'php' => PHP_VERSION,
    'zts' => PHP_ZTS,
    'sapi' => PHP_SAPI,
    'elapsed_ns' => $elapsed,
    'cpu_ns' => $cpu,
    'opcache' => ini_get('opcache.enable'),
    'jit' => ini_get('opcache.jit'),
    'status' => $report['status'] ?? null,
    'sampling_statuses' => isset($report['sampling']) ? [$report['sampling']['status'] => 1] : [],
    'sampling_samples' => $report['sampling']['captured_samples'] ?? 0,
    'sampling_reports' => isset($report['sampling']) ? [$report['sampling']] : [],
    'calls' => $report['functions']['fpm_benchmark_leaf']['calls'] ?? null,
], JSON_THROW_ON_ERROR);
