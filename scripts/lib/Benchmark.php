<?php

declare(strict_types=1);

namespace PhpProfiler\Tooling;

use RuntimeException;

/** Validation shared by the CLI and FPM benchmarks: measured, complete and consistent. */
final class Benchmark
{
    /** Keeps CPU observations distinct from elapsed time and rejects invalid counters. */
    public static function validateTiming(array $sample): void
    {
        foreach (['elapsed_ns', 'cpu_ns'] as $field) {
            $value = $sample[$field] ?? null;
            if (!is_int($value) || $value < 0) {
                throw new RuntimeException("invalid measured {$field}: " . json_encode($value));
            }
        }
    }

    /** Requires exact capture and valid sampled facts; discloses stop-boundary wakes. */
    public static function validateSampling(
        array $sample,
        string $mode,
        bool $requested,
        int $iterations,
        string $workload,
    ): void {
        $statuses = $sample['sampling_statuses'] ?? null;
        $count = $sample['sampling_samples'] ?? null;
        $reports = $sample['sampling_reports'] ?? null;
        if (!is_int($count) || $count < 0 || !is_array($reports) || !array_is_list($reports)) {
            throw new RuntimeException('invalid retained sampling observations');
        }
        if (!$requested || $mode !== 'enabled') {
            if ($statuses !== [] || $count !== 0 || $reports !== []) {
                throw new RuntimeException('sampling was recorded when not requested and enabled');
            }
            return;
        }
        $sessions = $workload === 'worker' ? $iterations : 1;
        if (count($reports) !== $sessions) {
            throw new RuntimeException("expected {$sessions} sampled reports, got " . count($reports));
        }
        $actualStatuses = [];
        $actualCount = 0;
        foreach ($reports as $report) {
            foreach (['final_counters_available', 'timestamps_valid', 'counters_valid'] as $flag) {
                if (($report[$flag] ?? null) !== true) {
                    throw new RuntimeException("unavailable sampling facts: {$flag}");
                }
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
            ] as $key) {
                if (!is_int($counters[$key] ?? null) || $counters[$key] < 0) {
                    throw new RuntimeException('invalid measured sampling counters');
                }
            }
            if ($counters['dropped_ticks'] || $counters['dropped_samples'] || $counters['invalid_observations']) {
                throw new RuntimeException('benchmark lost sampled observations');
            }
            $captured = $report['captured_samples'] ?? null;
            if (
                !is_int($captured)
                || $captured < 0
                || ($counters['serviced_wakes'] - $counters['coalesced_wakes']) !== $captured
            ) {
                throw new RuntimeException('sampled stack and wake counts disagree');
            }
            // A real wake between the last VM safepoint and stop is counted but has no
            // stack. It is not a failed backend or a fabricated execution sample.
            $expected = $counters['unserved_wakes'] ? 'truncated' : 'complete';
            if (($report['status'] ?? null) !== $expected) {
                throw new RuntimeException('sampling status disagrees with measured stop-boundary loss');
            }
            $actualStatuses[$expected] = ($actualStatuses[$expected] ?? 0) + 1;
            $actualCount += $captured;
        }
        if (!is_array($statuses) || array_filter($statuses, static fn(mixed $value): bool => !is_int($value)) !== []) {
            throw new RuntimeException('sampling summary disagrees with retained reports');
        }
        ksort($statuses);
        ksort($actualStatuses);
        if ($statuses !== $actualStatuses || $count !== $actualCount) {
            throw new RuntimeException('sampling summary disagrees with retained reports');
        }
    }

    /** Rejects absent, incomplete or incorrectly counted benchmark captures. */
    public static function validateCapture(array $sample, string $mode, int $iterations, string $workload): void
    {
        $statuses = $sample['capture_statuses'] ?? null;
        $sessions = $workload === 'worker' ? $iterations : 1;
        if ($mode === 'enabled') {
            if (
                !is_array($statuses)
                || array_keys($statuses) !== ['complete']
                || !is_int($statuses['complete'])
                || $statuses['complete'] !== $sessions
            ) {
                throw new RuntimeException("expected {$sessions} complete captures, got " . json_encode($statuses));
            }
            $expectedCalls = in_array($workload, ['calls', 'worker'], true) ? $iterations : 0;
        } else {
            if ($statuses !== []) {
                throw new RuntimeException(
                    'disabled/absent mode unexpectedly captured sessions: ' . json_encode($statuses),
                );
            }
            $expectedCalls = 0;
        }
        $calls = $sample['leaf_calls'] ?? null;
        if (!is_int($calls) || $calls !== $expectedCalls) {
            throw new RuntimeException(
                "expected {$expectedCalls} measured bench_leaf calls, got " . json_encode($calls),
            );
        }
    }

    /**
     * Python's statistics.median(): the middle value, or the mean of the two middle
     * values as a float.
     *
     * @param list<int|float> $values
     */
    public static function median(array $values): int|float
    {
        sort($values);
        $count = count($values);
        if ($count === 0) {
            throw new RuntimeException('no median for no data points');
        }
        $middle = intdiv($count, 2);
        return ($count % 2) === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2.0;
    }

    /** The host description that stands in for Python's platform.platform(). */
    public static function platform(): string
    {
        return php_uname('s') . '-' . php_uname('r') . '-' . php_uname('m');
    }
}
