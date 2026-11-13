<?php

declare(strict_types=1);

$mode = $argv[1] ?? 'conversion';
function engine_check(bool $value, string $message): void
{
    if (!$value)
        throw new RuntimeException($message);
}

$status = profiler_test_sampling_status();
engine_check($status['supported'] === $status['expected_supported'], 'compiled capability mismatch');
if (!$status['expected_supported'])
    return;
if (in_array(
    $mode,
    ['conversion', 'snapshot-error', 'deadline-exact', 'ring-wrap', 'status-mutex', 'worker-mutex'],
    true,
)) {
    engine_check(profiler_test_sampling_engine_probe($mode), 'exact native engine boundary: ' . $mode);
} elseif ($mode === 'destroy-retirement') {
    engine_check(profiler_test_sampling_engine_destroy(), 'join and resources before controller retirement');
} elseif ($mode === 'unready-drain') {
    engine_check(profiler_test_sampling_engine_unready_drain(), 'unready drain initializes complete caller status');
} elseif ($mode === 'drain-mutex') {
    engine_check(profiler_test_sampling_engine_drain_mutex(), 'drain native mutex ownership');
} elseif ($mode === 'mutex-error') {
    engine_check(
        $status['error'] === 1 && $status['system_error'] === $status['expected_enomem'],
        'native ENOMEM constructor cause',
    );
    engine_check(
        $status['snapshot_ns'] === 0 && $status['wakes'] === 0 && $status['pending'] === 0 && $status['running'] === 0,
        'unready status placeholders',
    );
} elseif (in_array($mode, ['clock', 'range', 'wake-clock', 'wake-range'], true)) {
    profiler_test_sampling_fault($mode);
    $operation = profiler_test_sampling_run($mode);
    $status = profiler_test_sampling_status();
    engine_check($operation['started'] === 1, 'real worker admission');
    engine_check($status['error'] === (str_ends_with($mode, 'clock') ? 3 : 4), 'exact worker error classification');
    engine_check(
        $status['system_error'] === (str_ends_with($mode, 'clock') ? $status['expected_eio'] : 0),
        'exact EIO/range native cause',
    );
    engine_check(
        $status['joined'] === 1 && $status['running'] === 0 && !$status['live_handle'],
        'real worker join/retirement',
    );
} else {
    throw new RuntimeException('unknown native boundary mode');
}
echo $mode, " exact clock/ring/error contract PASS\n";
