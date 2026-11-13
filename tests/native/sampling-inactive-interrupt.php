<?php

declare(strict_types=1);

use PhpProfiler\Profiler;

require __DIR__ . '/../support/stopped-sampling-assertions.php';
$expectedSampling = profiler_test_sampling_status()['expected_supported'];
if (Profiler::isSamplingSupported() !== $expectedSampling)
    throw new RuntimeException('public capability differs from compiled platform contract');
if (!$expectedSampling)
    return;
if (!function_exists('pcntl_signal') || !function_exists('posix_kill')) {
    throw new RuntimeException('real inactive interrupt proof requires pcntl and posix');
}
$signals = 0;
pcntl_async_signals(true);
pcntl_signal(SIGUSR1, static function () use (&$signals): void {
    ++$signals;
});
function inactive_sampling_interrupt(int &$signals): void
{
    $before = $signals;
    profiler_test_sampling_fault('snapshot-clock');
    if (!posix_kill(getmypid(), SIGUSR1))
        throw new RuntimeException('signal delivery failed');
    $deadline = hrtime(true) + 2_000_000_000;
    while ($signals === $before && hrtime(true) < $deadline) {
    }
    if ($signals !== ($before + 1))
        throw new RuntimeException('previous interrupt handler not chained');
    if (profiler_test_sampling_fault_consumed() !== 0)
        throw new RuntimeException('inactive sampler read a clock');
    profiler_test_sampling_fault('none');
}

inactive_sampling_interrupt($signals);
$first = Profiler::start(null, ['sampling' => true]);
if (!profiler_test_sampling_service())
    throw new RuntimeException('first sampler tick missing');
$first->stop();
inactive_sampling_interrupt($signals);
$second = Profiler::start(null, ['sampling' => true]);
if (!profiler_test_sampling_service())
    throw new RuntimeException('next sampler tick missing');
$second->stop();
foreach ([$first, $second] as $session) {
    $report = $session->getReport();
    assert_stopped_sampling($report['sampling']);
    if ($report['status'] !== 'complete') {
        throw new RuntimeException('inactive interrupt poisoned healthy capture');
    }
}
Profiler::resetAll();
Profiler::disable();
echo "real prior-only interrupts preserve cold/stopped sampling clocks passed\n";
