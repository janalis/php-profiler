<?php

declare(strict_types=1);

require_once __DIR__ . '/support.php';
require_once ROOT . '/scripts/lib/Benchmark.php';

use PhpProfiler\Tooling\Benchmark;

/** Benchmark results must prove capture happened and every requested call exists. */
function samplingReport(): array
{
    return [
        'status' => 'complete',
        'captured_samples' => 2,
        'truncated_stacks' => 0,
        'final_counters_available' => true,
        'timestamps_valid' => true,
        'counters_valid' => true,
        'counter_overflow' => false,
        'failure_reason' => null,
        'termination_reason' => 'stopped',
        'counters' => [
            'serviced_wakes' => 3,
            'coalesced_wakes' => 1,
            'dropped_ticks' => 0,
            'dropped_samples' => 0,
            'unserved_wakes' => 0,
            'invalid_observations' => 0,
        ],
    ];
}

function rejectsSampling(
    array $sample,
    string $mode = 'enabled',
    bool $requested = true,
    string $workload = 'calls',
): void {
    throws(RuntimeException::class, static fn() => Benchmark::validateSampling(
        $sample,
        $mode,
        $requested,
        7,
        $workload,
    ));
}

return [
    'sampled benchmarks validate each report and disclose boundary loss' => static function (): void {
        $sample = [
            'sampling_statuses' => ['complete' => 1],
            'sampling_samples' => 2,
            'sampling_reports' => [samplingReport()],
        ];
        Benchmark::validateSampling($sample, 'enabled', true, 7, 'calls');
        $boundary = $sample;
        $boundary['sampling_reports'][0]['counters']['unserved_wakes'] = 1;
        $boundary['sampling_reports'][0]['status'] = 'truncated';
        $boundary['sampling_statuses'] = ['truncated' => 1];
        Benchmark::validateSampling($boundary, 'enabled', true, 7, 'calls');
        Benchmark::validateSampling(
            [
                'sampling_statuses' => ['complete' => 7],
                'sampling_samples' => 14,
                'sampling_reports' => array_fill(0, 7, samplingReport()),
            ],
            'enabled',
            true,
            7,
            'worker',
        );
        foreach ([
            ['final_counters_available', false],
            ['timestamps_valid', false],
            ['counters_valid', false],
            ['counter_overflow', true],
            ['failure_reason', 'clock_failure'],
            ['termination_reason', 'fork'],
            ['truncated_stacks', 1],
            ['captured_samples', true],
            ['captured_samples', 3],
            ['status', 'truncated'],
        ] as [$key, $invalid]) {
            $bad = $sample;
            $bad['sampling_reports'][0][$key] = $invalid;
            rejectsSampling($bad);
        }
        foreach (array_keys(samplingReport()['counters']) as $key) {
            foreach ([-1, true, null] as $invalid) {
                $bad = $sample;
                $bad['sampling_reports'][0]['counters'][$key] = $invalid;
                rejectsSampling($bad);
            }
        }
        foreach (['dropped_ticks', 'dropped_samples', 'invalid_observations'] as $key) {
            $bad = $sample;
            $bad['sampling_reports'][0]['counters'][$key] = 1;
            rejectsSampling($bad);
        }
        foreach ([
            ['sampling_statuses', ['complete' => true]],
            ['sampling_samples', 3],
            ['sampling_samples', -1],
            ['sampling_reports', []],
            ['sampling_reports', null],
        ] as [$key, $invalid]) {
            $bad = $sample;
            $bad[$key] = $invalid;
            rejectsSampling($bad);
        }
        foreach (['absent', 'disabled', 'enabled'] as $mode) {
            Benchmark::validateSampling(
                ['sampling_statuses' => [], 'sampling_samples' => 0, 'sampling_reports' => []],
                $mode,
                false,
                7,
                'calls',
            );
            rejectsSampling($sample, $mode, false);
        }
    },
    'cpu and wall counters are independent measured integers' => static function (): void {
        Benchmark::validateTiming(['elapsed_ns' => 100, 'cpu_ns' => 0]);
        Benchmark::validateTiming(['elapsed_ns' => 10, 'cpu_ns' => 100]);
        foreach (['elapsed_ns', 'cpu_ns'] as $field) {
            foreach ([null, -1, true, 1.0, '1'] as $invalid) {
                throws(RuntimeException::class, static fn() => Benchmark::validateTiming([
                    ...['elapsed_ns' => 100, 'cpu_ns' => 100],
                    $field => $invalid,
                ]));
            }
            throws(RuntimeException::class, static fn() => Benchmark::validateTiming(array_diff_key([
                'elapsed_ns' => 1,
                'cpu_ns' => 1,
            ], [$field => true])));
        }
    },
    'exact application and worker captures are accepted' => static function (): void {
        Benchmark::validateCapture(
            ['capture_statuses' => ['complete' => 1], 'leaf_calls' => 17],
            'enabled',
            17,
            'calls',
        );
        Benchmark::validateCapture(
            ['capture_statuses' => ['complete' => 17], 'leaf_calls' => 17],
            'enabled',
            17,
            'worker',
        );
        Benchmark::validateCapture(['capture_statuses' => ['complete' => 1], 'leaf_calls' => 0], 'enabled', 17, 'cpu');
        foreach (['absent', 'disabled'] as $mode) {
            Benchmark::validateCapture(['capture_statuses' => [], 'leaf_calls' => 0], $mode, 17, 'calls');
        }
    },
    'enabled empty or wrong status counts are rejected' => static function (): void {
        foreach ([
            [],
            ['complete' => 0],
            ['complete' => true],
            ['complete' => 2],
            ['complete' => 1, 'truncated' => 1],
            ['incomplete' => 1],
            ['complete'],
        ] as $statuses) {
            throws(RuntimeException::class, static fn() => Benchmark::validateCapture(
                ['capture_statuses' => $statuses, 'leaf_calls' => 17],
                'enabled',
                17,
                'calls',
            ));
        }
        throws(RuntimeException::class, static fn() => Benchmark::validateCapture(
            ['capture_statuses' => ['complete' => 1], 'leaf_calls' => 17],
            'enabled',
            17,
            'worker',
        ));
    },
    'missing wrong or estimated leaf counts are rejected' => static function (): void {
        foreach ([null, 0, 16, 18, 17.0, '17', true] as $calls) {
            throws(RuntimeException::class, static fn() => Benchmark::validateCapture(
                ['capture_statuses' => ['complete' => 1], 'leaf_calls' => $calls],
                'enabled',
                17,
                'calls',
            ));
        }
        throws(RuntimeException::class, static fn() => Benchmark::validateCapture(
            ['capture_statuses' => ['complete' => 17], 'leaf_calls' => 16],
            'enabled',
            17,
            'worker',
        ));
        throws(RuntimeException::class, static fn() => Benchmark::validateCapture(
            ['capture_statuses' => ['complete' => 1], 'leaf_calls' => 1],
            'enabled',
            17,
            'memory',
        ));
    },
    'disabled modes cannot claim recorded data' => static function (): void {
        foreach (['absent', 'disabled'] as $mode) {
            throws(RuntimeException::class, static fn() => Benchmark::validateCapture(
                ['capture_statuses' => ['complete' => 1], 'leaf_calls' => 0],
                $mode,
                17,
                'calls',
            ));
            throws(RuntimeException::class, static fn() => Benchmark::validateCapture(
                ['capture_statuses' => [], 'leaf_calls' => 17],
                $mode,
                17,
                'calls',
            ));
        }
    },
    'median matches Python statistics' => static function (): void {
        same([3, 2.5, 3.0, 1.5], [
            Benchmark::median([5, 1, 3]),
            Benchmark::median([4, 1, 2, 3]),
            Benchmark::median([2, 4]),
            Benchmark::median([1.0, 2.0]),
        ]);
        throws(RuntimeException::class, static fn() => Benchmark::median([]));
    },
];
