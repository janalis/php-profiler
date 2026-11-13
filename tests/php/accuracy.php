<?php

declare(strict_types=1);

use PhpProfiler\Profiler;

function accuracy_sleep(): void
{
    usleep(30000);
}

function accuracy_allocation(int $bytes): string
{
    return str_repeat('m', $bytes);
}

function accuracy_cpu(): int
{
    $total = 0;
    for ($i = 0; $i < 200000; ++$i) {
        $total += ($i * 3) % 97;
    }
    return $total;
}

$before = hrtime(true);
$session = Profiler::start();
accuracy_sleep();
$result = accuracy_cpu();
$buffer = accuracy_allocation(1024 * 1024);
$session->stop();
$elapsed = hrtime(true) - $before;
$report = $session->getReport();
if ($report['status'] !== 'complete') {
    throw new RuntimeException('unexpected incomplete measurement');
}
if ($report['elapsed_wall_ns'] < 20000000 || $report['elapsed_wall_ns'] > ($elapsed * 1.2)) {
    throw new RuntimeException('session wall clock disagrees with elapsed runtime');
}
if ($report['thread_cpu_ns'] <= 0 || $report['thread_cpu_ns'] > ($elapsed * 1.2)) {
    throw new RuntimeException('thread CPU measurement unavailable or invalid');
}
if (!is_int($report['request_peak_bytes']) || $report['request_peak_bytes'] < $report['observed_boundary_peak_bytes']) {
    throw new RuntimeException('request-wide measured peak below an observed heap boundary');
}
if ($report['memory_delta_bytes'] < (1024 * 1024) || $report['observed_boundary_peak_bytes'] < (1024 * 1024)) {
    throw new RuntimeException('session heap observation missed retained allocation');
}
if ($report['functions']['accuracy_sleep']['inclusive_wall_ns'] < 20000000) {
    throw new RuntimeException('sleep function timing wrong');
}
if (
    $report['functions']['accuracy_cpu']['inclusive_cpu_ns'] <= 0
    || $result <= 0
    || strlen($buffer) !== (1024 * 1024)
) {
    throw new RuntimeException('CPU function measurement or semantics wrong');
}
foreach ($report['functions'] as $symbol => $metrics) {
    if (
        $metrics['inclusive_wall_ns'] > ($report['elapsed_wall_ns'] * 1.2)
        || $metrics['inclusive_cpu_ns'] > ($report['thread_cpu_ns'] * 1.2)
    ) {
        throw new RuntimeException('per-call clock exceeds session duration: ' . $symbol);
    }
}
$allocation = $report['functions']['accuracy_allocation']['memory_delta_bytes'];
if ($allocation < (1024 * 1024) || $allocation > ($report['memory_delta_bytes'] + 65536)) {
    throw new RuntimeException('per-call retained allocation observation invalid');
}
Profiler::resetAll();
Profiler::disable();
echo "real wall/CPU/heap accuracy bounds passed\n";
