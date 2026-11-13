<?php

declare(strict_types=1);

function check(bool $condition, string $message): void
{
    if (!$condition)
        throw new RuntimeException($message);
}

$mode = $argv[1] ?? 'normal';
check(function_exists('profiler_test_sampling_status'), 'isolated sampling transport missing');
$status = profiler_test_sampling_status();
check($status['supported'] === $status['expected_supported'], 'capability differs from independent platform contract');
if (!$status['expected_supported']) {
    check(profiler_test_sampling_start() === 0, 'unsupported platform started timer');
    profiler_test_sampling_stop();
    check(profiler_test_sampling_status()['error'] === 0, 'unsupported status not zero');
    check(profiler_test_sampling_empty_drain(), 'unsupported drain returned invented ticks');
    check(profiler_test_sampling_register_again(), 'unsupported registration not idempotent');
    echo "unsupported-stub PASS\n";
    exit();
}
$maskBefore = [$status['caller_usr1'], $status['caller_usr2']];
if ($status['error'] === 0)
    check($status['snapshot_in_reference_range'], 'native timestamp differs from independent POSIX clock bracket');
$initial = in_array($mode, ['mutex', 'condattr', 'setclock', 'condition'], true);
$startErrors = ['attr' => 1, 'page' => 1, 'stack' => 1, 'guard' => 1, 'mask' => 8, 'create' => 6];
if ($mode === 'lifecycle') {
    check(profiler_test_sampling_lifecycle(), 'real shutdown/stale-hook/reinitialization contract failed');
} elseif ($mode === 'lock-contention') {
    check(profiler_test_sampling_lock_contention(), 'real registry lock contention was not exercised');
} elseif ($mode === 'register-again') {
    check(profiler_test_sampling_register_again(), 'native registration is not idempotent');
} elseif ($mode === 'request-restart') {
    check(profiler_test_sampling_request_restart(), 'RINIT failed to join skipped-shutdown worker');
} elseif ($mode === 'capacity') {
    check(profiler_test_sampling_capacity(), 'genuine initialized registry capacity contract failed');
} elseif ($initial) {
    check($status['error'] === 1 && $status['consumed'] === 1, "initialization fault not reported: $mode");
    check(profiler_test_sampling_start() === 0, 'failed initialization started worker');
    profiler_test_sampling_stop();
    check(profiler_test_sampling_empty_drain(), 'uninitialized controller returned invented ticks');
} elseif (in_array($mode, ['snapshot-clock', 'snapshot-range'], true)) {
    profiler_test_sampling_fault($mode);
    $status = profiler_test_sampling_status();
    check($status['error'] === ($mode === 'snapshot-clock' ? 3 : 4), 'snapshot clock failure not disclosed');
    check($status['consumed'] === 1, 'snapshot fault was not consumed');
    check(profiler_test_sampling_start() === 0, 'snapshot failure admitted worker');
    profiler_test_sampling_stop();
} elseif (isset($startErrors[$mode])) {
    profiler_test_sampling_fault($mode);
    check(profiler_test_sampling_start() === 0, "start fault ignored: $mode");
    $status = profiler_test_sampling_status();
    check($status['error'] === $startErrors[$mode] && $status['consumed'] === 1, 'start error classification');
    check($status['running'] === 0 && $status['system_error'] !== 0, 'failed start still running');
    profiler_test_sampling_stop();
} elseif (in_array($mode, ['fork', 'fork-clock', 'fork-range'], true)) {
    if ($mode !== 'fork')
        profiler_test_sampling_fault($mode === 'fork-clock' ? 'snapshot-clock' : 'snapshot-range');
    check(profiler_test_sampling_fork(), 'real native fork/join/no-restart contract failed');
    check(profiler_test_sampling_status()['joined'] === 1, 'fork did not join worker');
    try {
        PhpProfiler\Profiler::start(null, ['sampling' => true]);
        throw new RuntimeException('public sampler restarted after fork');
    } catch (PhpProfiler\StateException $exception) {
        check(
            str_contains($exception->getMessage(), 'cannot restart after fork'),
            'public fork policy lost typed cause',
        );
    }
} else {
    if ($mode === 'counter')
        profiler_test_sampling_counter_limit();
    if ($mode === 'dropped-counter')
        profiler_test_sampling_dropped_limit();
    if (in_array(
        $mode,
        [
            'clock',
            'range',
            'wait',
            'nsec-negative',
            'nsec-upper',
            'seconds-overflow',
            'deadline-overflow',
            'spurious',
            'wake-clock',
            'wake-range',
            'wake-regression',
            'wake-equal',
        ],
        true,
    ))
        profiler_test_sampling_fault($mode);
    if ($mode === 'join')
        profiler_test_sampling_fault('join');
    if ($mode === 'prior-clock-join')
        profiler_test_sampling_fault('clock');
    $operation = profiler_test_sampling_run($mode);
    check($operation['started'] === 1, 'worker did not start');
    if ($mode === 'normal')
        check($operation['duplicate'] === 2, 'duplicate worker admitted');
    if ($mode === 'backlog')
        check($operation['backlog_ready'], 'producer did not fill real queue');
    if ($mode === 'real-spurious')
        check($operation['backlog_ready'], 'real condition-wait synchronization missing');
    $status = profiler_test_sampling_status();
    check($status['running'] === 0, 'worker remains running after stop');
    check($status['attributes_valid'], 'worker stack/guard/signal attributes invalid');
    check($status['joined'] === 1, 'worker not actually joined exactly once');
    check(!$status['live_handle'], 'joined worker still owns a live handle');
    check(
        $status['snapshot_ns'] > 0 && $status['snapshot_in_reference_range'],
        'status not timestamped within independent real clock bracket',
    );
    $expected =
        [
            'clock' => 3,
            'range' => 4,
            'wait' => 5,
            'join' => 7,
            'nsec-negative' => 4,
            'nsec-upper' => 4,
            'seconds-overflow' => 4,
            'deadline-overflow' => 4,
            'counter' => 10,
            'prior-clock-join' => 3,
            'dropped-counter' => 10,
            'wake-clock' => 3,
            'wake-range' => 4,
            'wake-regression' => 4,
        ][$mode] ?? 0;
    check($status['error'] === $expected, 'worker native error classification: ' . json_encode($status));
    $expectedConsumed = $mode === 'prior-clock-join'
        ? 2
        : (
            in_array($mode, ['spurious', 'wake-equal'], true)
                ? 1
                : ($expected && !in_array($mode, ['counter', 'dropped-counter'], true) ? 1 : 0)
        );
    check($status['consumed'] === $expectedConsumed, 'fault transport was not consumed exactly once');
    check(
        $status['cleanup_error'] === (in_array($mode, ['join', 'prior-clock-join'], true) ? 7 : 0),
        'cleanup diagnostic lost or invented',
    );
    if (in_array($mode, ['join', 'prior-clock-join'], true))
        check($status['cleanup_system_error'] !== 0, 'native join errno missing');
    if ($mode === 'normal' || in_array($mode, ['spurious', 'wake-equal'], true))
        check($status['wakes'] > 0, 'no real timer wakes');
    if ($mode === 'backlog')
        check($status['wakes'] >= 64 && $status['dropped'] > 0, 'real bounded queue did not disclose backlog');
    if ($mode === 'join')
        check($status['termination'] === 3, 'transient failed join not disclosed');
    if (in_array($mode, ['spurious', 'real-spurious'], true))
        check(
            $status['zero_wakes'] >= 2 && $status['deadline_checks'] >= 2 && $status['same_deadline'],
            'spurious wakes postponed the original absolute deadline',
        );
    // A second stop must be harmless after the successful actual join.
    profiler_test_sampling_stop();
}
$after = profiler_test_sampling_status();
check([$after['caller_usr1'], $after['caller_usr2']] === $maskBefore, 'caller signal mask changed');
echo "$mode PASS\n";
