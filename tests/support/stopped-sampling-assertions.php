<?php

/** Test oracle for successful measured sampling with disclosed stop-window loss. */
function assert_stopped_sampling(array $report): void
{
    foreach (['final_counters_available', 'timestamps_valid', 'counters_valid'] as $flag) {
        if (($report[$flag] ?? null) !== true)
            throw new RuntimeException("unavailable sampling facts: $flag");
    }
    if (
        ($report['failure_reason'] ?? null) !== null
        || ($report['counter_overflow'] ?? null) !== false
        || ($report['termination_reason'] ?? null) !== 'stopped'
        || ($report['truncated_stacks'] ?? null) !== 0
    ) {
        throw new RuntimeException('failed or truncated sampled capture');
    }
    $counters = $report['counters'] ?? [];
    foreach ([
        'serviced_wakes',
        'dropped_ticks',
        'coalesced_wakes',
        'dropped_samples',
        'unserved_wakes',
        'invalid_observations',
    ] as $name) {
        if (!isset($counters[$name]) || !is_int($counters[$name]) || $counters[$name] < 0) {
            throw new RuntimeException("invalid measured sampling counter: $name");
        }
    }
    foreach (['dropped_ticks', 'dropped_samples', 'invalid_observations'] as $name) {
        if ($counters[$name] !== 0)
            throw new RuntimeException("lost sampled observations: $name");
    }
    $captured = $report['captured_samples'] ?? null;
    if (
        !is_int($captured)
        || $captured < 1
        || ($counters['serviced_wakes'] - $counters['coalesced_wakes']) !== $captured
    ) {
        throw new RuntimeException('sampled stack and wake counts disagree');
    }
    $status = $counters['unserved_wakes'] === 0 ? 'complete' : 'truncated';
    if (($report['status'] ?? null) !== $status)
        throw new RuntimeException('sampling status disagrees with measured stop-window loss');
}
