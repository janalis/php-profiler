<?php

declare(strict_types=1);

use PhpProfiler\Profiler;

require __DIR__ . '/../support/stopped-sampling-assertions.php';
function frame_contract_check(bool $ok, string $message): void
{
    if (!$ok)
        throw new RuntimeException($message);
}

function sampling_frame_depth_contract(int $depth): void
{
    if ($depth > 0) {
        sampling_frame_depth_contract($depth - 1);
        return;
    }
    frame_contract_check(profiler_test_sampling_service(), 'real deep producer tick unavailable');
}

$mode = $argv[1] ?? 'equal';
if (!Profiler::isSamplingSupported())
    return;
if ($mode === 'registration') {
    frame_contract_check(
        profiler_test_sampling_frame_contract($mode)['valid'],
        'actual atfork registration duplicated',
    );
    echo "registration contract passed\n";
    return;
}
$session = Profiler::start(null, ['sampling' => true]);
$expected = profiler_test_sampling_frame_contract($mode);
frame_contract_check($expected['valid'], 'unknown frame contract mode');
if (in_array($mode, ['service-clock', 'service-range', 'service-regression', 'identity'], true))
    profiler_test_sampling_fault($mode);
if ($mode === 'identity') {
    $closure = static function (): int {
        frame_contract_check(profiler_test_sampling_service(), 'identity tick absent');
        return 73;
    };
    frame_contract_check($closure() === 73, 'closure semantics');
} elseif ($mode === 'depth')
    sampling_frame_depth_contract(70);
elseif ($mode === 'backlog')
    frame_contract_check(profiler_test_sampling_backlog_service(), 'real full ring unavailable');
else
    frame_contract_check(profiler_test_sampling_service(), 'actual producer tick unavailable');
$session->stop();
$report = $session->getReport();
$sampling = $report['sampling'];
$observed = profiler_test_sampling_frame_contract('verify');
frame_contract_check($observed['valid'], 'native observation status/ownership/equality contract');
frame_contract_check($report['status'] === 'complete', 'instrumentation changed during sampling contract');
frame_contract_check(
    (int) $report['runtime_metadata']['sampling.backend_error_code'] === $expected['expected_error'],
    'exact native error code',
);
frame_contract_check(
    (int) $report['runtime_metadata']['sampling.backend_system_error_code'] === $expected['expected_system_error'],
    'exact native system error',
);
if ($mode === 'equal' || $mode === 'equal-wake')
    assert_stopped_sampling($sampling);
if ($mode === 'depth') {
    $found = false;
    foreach ($sampling['samples'] as $sample) {
        $stack = array_map(
            static fn(int $id): string => $sampling['symbols'][$id],
            array_slice($sampling['frames'], $sample['first_frame'], $sample['frame_count']),
        );
        if (
            ($stack[0] ?? null) === 'profiler_test_sampling_service'
            && ($stack[1] ?? null) === 'sampling_frame_depth_contract'
        ) {
            frame_contract_check(
                $sample['frame_count'] === 64 && $sample['depth_truncated'],
                'ordinary bounded prefix lost valid frames',
            );
            frame_contract_check(
                array_unique(array_slice($stack, 1)) === ['sampling_frame_depth_contract'],
                'ordinary recursion prefix changed',
            );
            $found = true;
        }
    }
    frame_contract_check($found, 'actual deep stack missing');
}
if ($mode === 'backlog')
    frame_contract_check(
        $sampling['counters']['serviced_wakes'] >= 63
        && $sampling['counters']['dropped_ticks'] > 0
        && $sampling['captured_samples'] > 0,
        'real bounded backlog absent',
    );
if (str_starts_with($mode, 'service-'))
    frame_contract_check($sampling['failure_reason'] === 'clock_failure', 'native clock reason wrong');
if ($mode === 'identity')
    frame_contract_check($sampling['failure_reason'] === 'identity_failure', 'native identity reason wrong');
Profiler::resetAll();
Profiler::disable();
echo "$mode actual native interrupt contract passed\n";
