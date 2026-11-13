<?php

declare(strict_types=1);

use PhpProfiler\Profiler;

require __DIR__ . '/../support/stopped-sampling-assertions.php';
use PhpProfiler\StateException;

function sampling_recovery_check(bool $condition, string $message): void
{
    if (!$condition)
        throw new RuntimeException($message);
}

$expectedSampling = profiler_test_sampling_status()['expected_supported'];
if (Profiler::isSamplingSupported() !== $expectedSampling)
    throw new RuntimeException('public capability differs from compiled platform contract');
if (!$expectedSampling)
    return;
$mode = $argv[1] ?? 'create';
profiler_test_sampling_fault($mode);
if (in_array($mode, ['create', 'snapshot-clock', 'snapshot-range'], true)) {
    try {
        Profiler::start(null, ['sampling' => true]);
        throw new RuntimeException('native admission failure accepted');
    } catch (StateException) {
    }
} else {
    $failed = Profiler::start(null, ['sampling' => true]);
    if (str_starts_with($mode, 'service-')) {
        sampling_recovery_check(profiler_test_sampling_service(), 'genuine service fault path not reached');
    } elseif ($mode === 'join') {
        sampling_recovery_check(profiler_test_sampling_service(), 'pre-fault real tick missing');
        profiler_test_sampling_fault('join');
    } else {
        // Give the genuine worker its one fault; the PHP safepoint consumes the
        // resulting error interrupt, without claiming an observed stack.
        usleep(30_000);
    }
    $failed->stop();
    $report = $failed->getReport();
    if ($mode === 'join') {
        $metadata = $report['runtime_metadata'];
        sampling_recovery_check(
            $metadata['sampling.cleanup_error_code'] === '7'
            && (int) $metadata['sampling.cleanup_system_error_code'] !== 0
            && $metadata['sampling.cleanup_deferred'] === 'false',
            'native cleanup failure undisclosed',
        );
        assert_stopped_sampling($report['sampling']);
        sampling_recovery_check(
            $report['status'] === 'complete'
            && $report['sampling']['failure_reason'] === null
            && $report['sampling']['captured_samples'] > 0,
            'cleanup error invalidated genuine measurements',
        );
    } else {
        sampling_recovery_check($report['sampling']['failure_reason'] !== null, 'native sampling failure undisclosed');
    }
}
sampling_recovery_check(profiler_test_sampling_status()['consumed'] === 1, 'native fault not exercised');
Profiler::resetAll();
sampling_recovery_check(
    profiler_test_sampling_status()['error'] === 0 && profiler_test_sampling_status()['cleanup_error'] === 0,
    'explicit reset retained recoverable native error',
);
$healthy = Profiler::start(null, ['sampling' => true]);
sampling_recovery_check(profiler_test_sampling_service(), 'recovered timer missing real tick');
$healthy->stop();
$report = $healthy->getReport();
assert_stopped_sampling($report['sampling']);
sampling_recovery_check(
    $report['status'] === 'complete' && $report['sampling']['captured_samples'] > 0,
    'recovered public capture unhealthy',
);
$healthy->reset();
Profiler::disable();
echo "public sampling $mode failure/reset/recovery passed\n";
