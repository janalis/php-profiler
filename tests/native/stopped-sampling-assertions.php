<?php

declare(strict_types=1);

require __DIR__ . '/../support/stopped-sampling-assertions.php';
$valid = [
    'final_counters_available' => true,
    'timestamps_valid' => true,
    'counters_valid' => true,
    'failure_reason' => null,
    'counter_overflow' => false,
    'termination_reason' => 'stopped',
    'truncated_stacks' => 0,
    'status' => 'complete',
    'captured_samples' => 1,
    'counters' => [
        'serviced_wakes' => 1,
        'coalesced_wakes' => 0,
        'dropped_ticks' => 0,
        'dropped_samples' => 0,
        'unserved_wakes' => 0,
        'invalid_observations' => 0,
    ],
];
assert_stopped_sampling($valid);
$boundary = $valid;
$boundary['counters']['unserved_wakes'] = 1;
$boundary['status'] = 'truncated';
assert_stopped_sampling($boundary);
$coalesced = $valid;
$coalesced['counters']['serviced_wakes'] = 3;
$coalesced['counters']['coalesced_wakes'] = 2;
assert_stopped_sampling($coalesced);
$invalid = [];
foreach (['final_counters_available', 'timestamps_valid', 'counters_valid'] as $key) {
    $case = $valid;
    $case[$key] = false;
    $invalid[] = $case;
    $case = $valid;
    unset($case[$key]);
    $invalid[] = $case;
}
foreach ([
    'failure_reason' => 'thread_failure',
    'counter_overflow' => true,
    'termination_reason' => 'fork',
    'truncated_stacks' => 1,
    'captured_samples' => 0,
    'status' => 'truncated',
] as $key => $value) {
    $case = $valid;
    $case[$key] = $value;
    $invalid[] = $case;
}
foreach ($valid['counters'] as $key => $value) {
    foreach ([-1, '0', 0.0] as $value) {
        $case = $valid;
        $case['counters'][$key] = $value;
        $invalid[] = $case;
    }
    $case = $valid;
    unset($case['counters'][$key]);
    $invalid[] = $case;
}
foreach (['dropped_ticks', 'dropped_samples', 'invalid_observations', 'coalesced_wakes', 'serviced_wakes'] as $key) {
    $case = $valid;
    $case['counters'][$key]++;
    $invalid[] = $case;
}
$case = $boundary;
$case['status'] = 'complete';
$invalid[] = $case;
foreach ($invalid as $index => $case) {
    $rejected = false;
    try {
        assert_stopped_sampling($case);
    } catch (RuntimeException) {
        $rejected = true;
    }
    if (!$rejected)
        throw new RuntimeException("invalid sampling oracle case $index accepted");
}
echo count($invalid), " malformed/loss/status counter oracle cases rejected\n";
