<?php

declare(strict_types=1);

use PhpProfiler\ConfigurationException;
use PhpProfiler\Profiler;

const CPU_FIELDS = ['exclusive_cpu_ns', 'inclusive_cpu_ns', 'session.thread_cpu_ns', 'timeline.active_cpu_ns'];

function thread_cpu_work(): int
{
    $value = 0;
    for ($index = 0; $index < 2000000; ++$index) {
        $value += $index & 7;
    }
    return $value;
}

function thread_cpu_fiber_work(): int
{
    $fiber = new Fiber(static function (): int {
        $value = thread_cpu_work();
        Fiber::suspend();
        return $value + thread_cpu_work();
    });
    $fiber->start();
    $fiber->resume();
    return $fiber->getReturn();
}

function expect_unmeasured(array $report, string $case): void
{
    if ($report['unavailable_metrics'] !== CPU_FIELDS || $report['status'] !== 'complete') {
        throw new RuntimeException("{$case}: CPU must be explicitly unavailable, not a failure");
    }
    foreach (CPU_FIELDS as $field) {
        if (!str_starts_with($report['metric_provenance'][$field], 'unavailable:thread-cpu-disabled')) {
            throw new RuntimeException("{$case}: {$field} provenance must disclose the disabled option");
        }
    }
    if ($report['thread_cpu_ns'] !== 0) {
        throw new RuntimeException("{$case}: an unmeasured session CPU value must be a zero placeholder");
    }
    foreach ([...$report['functions'], ...array_column($report['edges'], 'metrics')] as $metrics) {
        if ($metrics['inclusive_cpu_ns'] !== 0 || $metrics['exclusive_cpu_ns'] !== 0) {
            throw new RuntimeException("{$case}: unmeasured CPU must not be attributed");
        }
    }
    foreach ($report['timeline'] as $event) {
        if ($event['active_cpu_ns'] !== 0) {
            throw new RuntimeException("{$case}: unmeasured timeline CPU must be a zero placeholder");
        }
    }
    if ($report['elapsed_wall_ns'] <= 0 || $report['functions']['thread_cpu_work']['inclusive_wall_ns'] <= 0) {
        throw new RuntimeException("{$case}: wall time must still be measured");
    }
}

function expect_measured(array $report, string $case): void
{
    if ($report['unavailable_metrics'] !== [] || $report['status'] !== 'complete') {
        throw new RuntimeException("{$case}: a CPU-measuring session must stay fully available");
    }
    $work = $report['functions']['thread_cpu_work'];
    if ($report['thread_cpu_ns'] <= 0 || $work['inclusive_cpu_ns'] <= 0 || $work['exclusive_cpu_ns'] <= 0) {
        throw new RuntimeException("{$case}: CPU-measuring session lost genuine CPU observations");
    }
    if ($work['inclusive_cpu_ns'] > $report['thread_cpu_ns']) {
        throw new RuntimeException("{$case}: function CPU exceeds the session's thread CPU");
    }
    if (!str_starts_with($report['metric_provenance']['inclusive_cpu_ns'], 'measured:')) {
        throw new RuntimeException("{$case}: measured CPU provenance changed");
    }
}

foreach ([1, 'false', null] as $invalid) {
    try {
        Profiler::start(null, ['cpu' => $invalid]);
        throw new RuntimeException('cpu accepted a non-boolean');
    } catch (ConfigurationException) {
    }
}
if (Profiler::isEnabled()) {
    throw new RuntimeException('a rejected cpu option started capture');
}

// Default and explicit true keep measuring CPU.
foreach ([[], ['cpu' => true]] as $options) {
    $session = Profiler::start(null, $options);
    thread_cpu_work();
    $session->stop();
    expect_measured($session->getReport(), 'default ' . json_encode($options));
    $session->reset();
}

// A lone unmeasured session, including fiber switches and its timeline.
$alone = Profiler::start(null, ['cpu' => false, 'timeline' => true]);
thread_cpu_fiber_work();
$alone->stop();
expect_unmeasured($alone->getReport(), 'alone');
$alone->reset();

// Overlap in both nesting orders: the measuring session never receives a skipped reading.
foreach ([false, true] as $outerCpu) {
    $outer = Profiler::start(null, ['cpu' => $outerCpu, 'timeline' => true]);
    thread_cpu_work();
    $inner = Profiler::start(null, ['cpu' => !$outerCpu, 'timeline' => true]);
    thread_cpu_fiber_work();
    $inner->stop();
    thread_cpu_work();
    $outer->stop();
    [$measured, $unmeasured] = $outerCpu ? [$outer, $inner] : [$inner, $outer];
    $case = $outerCpu ? 'measuring outer' : 'measuring inner';
    expect_measured($measured->getReport(), $case);
    expect_unmeasured($unmeasured->getReport(), $case);
    foreach ($measured->getReport()['timeline'] as $event) {
        if ($event['symbol'] === 'thread_cpu_work' && $event['active_cpu_ns'] <= 0) {
            throw new RuntimeException("{$case}: a measured invocation lost its CPU");
        }
    }
    Profiler::resetAll();
}

// The measuring session stops first; the unmeasured one keeps recording without CPU.
$unmeasured = Profiler::start(null, ['cpu' => false]);
$measured = Profiler::start(null, ['cpu' => true]);
thread_cpu_work();
$measured->stop();
thread_cpu_work();
$unmeasured->stop();
expect_measured($measured->getReport(), 'measuring stops first');
expect_unmeasured($unmeasured->getReport(), 'measuring stops first');
if ($unmeasured->getReport()['functions']['thread_cpu_work']['calls'] !== 2) {
    throw new RuntimeException('unmeasured session lost calls after the measuring session stopped');
}
Profiler::resetAll();
Profiler::disable();
echo "thread CPU option passed\n";
