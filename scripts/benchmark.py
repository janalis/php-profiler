#!/usr/bin/env python3
"""Paired subprocess benchmark; run against release artifact on an idle host."""
import argparse
import hashlib
from pathlib import Path
import json
import os
import platform
import random
import statistics
import subprocess
import tempfile
import time

def validate_timing(sample):
    """Keep CPU observations distinct from elapsed time and reject invalid counters."""
    for field in ('elapsed_ns', 'cpu_ns'):
        value = sample.get(field)
        if type(value) is not int or value < 0:
            raise RuntimeError(f'invalid measured {field}: {value!r}')

def validate_sampling(sample, mode, requested, iterations, workload):
    """Require exact capture and valid sampled facts; disclose stop-boundary wakes."""
    statuses, count = sample.get('sampling_statuses'), sample.get('sampling_samples')
    reports = sample.get('sampling_reports')
    if type(count) is not int or count < 0 or type(reports) is not list:
        raise RuntimeError('invalid retained sampling observations')
    if not requested or mode != 'enabled':
        if statuses not in ([], {}) or count != 0 or reports:
            raise RuntimeError('sampling was recorded when not requested and enabled')
        return
    sessions = iterations if workload == 'worker' else 1
    if len(reports) != sessions:
        raise RuntimeError(f'expected {sessions} sampled reports, got {len(reports)}')
    actual_statuses, actual_count = {}, 0
    for report in reports:
        for flag in ('final_counters_available', 'timestamps_valid', 'counters_valid'):
            if report.get(flag) is not True:
                raise RuntimeError(f'unavailable sampling facts: {flag}')
        if (report.get('failure_reason') is not None or report.get('counter_overflow') is not False or
                report.get('termination_reason') != 'stopped' or report.get('truncated_stacks') != 0):
            raise RuntimeError('failed or truncated sampled capture')
        counters = report.get('counters', {})
        names = ('serviced_wakes', 'dropped_ticks', 'coalesced_wakes', 'dropped_samples',
                 'unserved_wakes', 'invalid_observations')
        if any(type(counters.get(key)) is not int or counters[key] < 0 for key in names):
            raise RuntimeError('invalid measured sampling counters')
        if any(counters[key] for key in ('dropped_ticks', 'dropped_samples', 'invalid_observations')):
            raise RuntimeError('benchmark lost sampled observations')
        captured = report.get('captured_samples')
        if (type(captured) is not int or captured < 0 or
                counters['serviced_wakes'] - counters['coalesced_wakes'] != captured):
            raise RuntimeError('sampled stack and wake counts disagree')
        # A real wake between the last VM safepoint and stop is counted but has no
        # stack. It is not a failed backend or a fabricated execution sample.
        expected_status = 'truncated' if counters['unserved_wakes'] else 'complete'
        if report.get('status') != expected_status:
            raise RuntimeError('sampling status disagrees with measured stop-boundary loss')
        actual_statuses[expected_status] = actual_statuses.get(expected_status, 0) + 1
        actual_count += captured
    if (type(statuses) is not dict or any(type(value) is not int for value in statuses.values()) or
            statuses != actual_statuses or count != actual_count):
        raise RuntimeError('sampling summary disagrees with retained reports')

def validate_capture(sample, mode, iterations, workload):
    """Reject absent, incomplete or incorrectly counted benchmark captures."""
    statuses = sample.get('capture_statuses')
    expected_sessions = iterations if workload == 'worker' else 1
    if mode == 'enabled':
        if (type(statuses) is not dict or set(statuses) != {'complete'} or
                type(statuses['complete']) is not int or statuses['complete'] != expected_sessions):
            raise RuntimeError(f'expected {expected_sessions} complete captures, got {statuses!r}')
        expected_calls = iterations if workload in ('calls', 'worker') else 0
    else:
        if statuses not in ([], {}):
            raise RuntimeError(f'disabled/absent mode unexpectedly captured sessions: {statuses!r}')
        expected_calls = 0
    calls = sample.get('leaf_calls')
    if type(calls) is not int or calls != expected_calls:
        raise RuntimeError(f'expected {expected_calls} measured bench_leaf calls, got {calls!r}')


source = r'''<?php
use PhpProfiler\Profiler;
function bench_leaf(int $i): int { return ($i * 3) % 97; }
$enabled = ($argv[1] ?? '') === 'enabled';
$iterations = (int)$argv[2]; $workload = $argv[3];
$statuses = []; $leafCalls = 0; $samplingStatuses = []; $samplingSamples = 0; $samplingReports = [];
$sampling = ($argv[4] ?? '0') === '1';
$disabledWarmup = false;
if (($argv[1] ?? '') === 'disabled' && ($argv[5] ?? '0') === '1') {
    // Real prior capture, outside both timed regions; no sampler is requested.
    $warmup = Profiler::start(); bench_leaf(0); $warmup->stop();
    $warmupReport = $warmup->getReport();
    if ($warmupReport['status'] !== 'complete' ||
        ($warmupReport['functions']['bench_leaf']['calls'] ?? 0) !== 1) {
        throw new RuntimeException('disabled-state warmup did not capture the real function');
    }
    $warmup->reset(); Profiler::disable(); unset($warmup, $warmupReport);
    if (Profiler::isEnabled()) { throw new RuntimeException('warmup failed to disable profiling'); }
    $disabledWarmup = true;
}
if ($enabled && $workload !== 'worker') { $session = Profiler::start(null, ['sampling' => $sampling]); }
$usageBefore = getrusage();
$t = hrtime(true); $sum = 0;
for ($i = 0; $i < $iterations; ++$i) {
    switch ($workload) {
        case 'calls': $sum += bench_leaf($i); break;
        case 'cpu':
            for ($j = 0; $j < 1000; ++$j) { $sum += ($i * 3 + $j) % 97; }
            break;
        case 'sleep': usleep(100); $sum += $i; break;
        case 'memory': $buffer = str_repeat('a', 65536); $sum += strlen($buffer); unset($buffer); break;
        case 'worker':
            if ($enabled) { $job = Profiler::start(null, ['sampling' => $sampling]); }
            $sum += bench_leaf($i);
            if ($enabled) { $job->stop(); $report = $job->getReport(); $statuses[] = $report['status']; $leafCalls += $report['functions']['bench_leaf']['calls'] ?? 0; if ($sampling) { $samplingReports[] = $report['sampling']; $samplingStatuses[] = $report['sampling']['status']; $samplingSamples += $report['sampling']['captured_samples']; } $job->reset(); }
            break;
    }
}
$elapsed = hrtime(true) - $t;
$usageAfter = getrusage();
$cpu = (($usageAfter['ru_utime.tv_sec'] - $usageBefore['ru_utime.tv_sec'] +
         $usageAfter['ru_stime.tv_sec'] - $usageBefore['ru_stime.tv_sec']) * 1000000 +
        $usageAfter['ru_utime.tv_usec'] - $usageBefore['ru_utime.tv_usec'] +
        $usageAfter['ru_stime.tv_usec'] - $usageBefore['ru_stime.tv_usec']) * 1000;
if ($enabled && $workload !== 'worker') {
    $session->stop(); $report = $session->getReport(); $statuses[] = $report['status']; $leafCalls += $report['functions']['bench_leaf']['calls'] ?? 0;
    if ($sampling) { $samplingReports[] = $report['sampling']; $samplingStatuses[] = $report['sampling']['status']; $samplingSamples += $report['sampling']['captured_samples']; }
}
if ($enabled) { Profiler::disable(); }
echo json_encode(['disabled_warmup' => $disabledWarmup, 'elapsed_ns' => $elapsed, 'cpu_ns' => $cpu, 'sum' => $sum, 'capture_statuses' => array_count_values($statuses), 'leaf_calls' => $leafCalls, 'sampling_statuses' => array_count_values($samplingStatuses), 'sampling_samples' => $samplingSamples, 'sampling_reports' => $samplingReports]);
'''
def main():
    from validation_inputs import snapshot
    root = Path(__file__).resolve().parents[1]
    source_before = snapshot(root)
    parser = argparse.ArgumentParser()
    parser.add_argument('--extension', required=True)
    parser.add_argument('--repeats', type=int, default=11)
    parser.add_argument('--iterations', type=int, default=100)
    parser.add_argument('--workload', choices=['calls', 'cpu', 'sleep', 'memory', 'worker'], default='calls')
    parser.add_argument('--sampling', action='store_true', help='enable wall safepoint sampling in enabled captures')
    parser.add_argument('--warm-disabled', action='store_true', help='measure disabled mode after a real stopped/reset capture; setup is outside CPU/wall regions')
    args = parser.parse_args()
    if args.repeats < 3 or args.iterations < 1:
        parser.error('use at least three repeats and one iteration')
    extension_before = hashlib.sha256(Path(args.extension).read_bytes()).hexdigest()
    results = {mode: [] for mode in ['absent', 'disabled', 'enabled']}
    with tempfile.TemporaryDirectory(prefix='php-profiler-bench-') as directory:
        script = os.path.join(directory, 'bench.php')
        with open(script, 'w') as output:
            output.write(source)
        reference = None
        for repetition in range(args.repeats + 1):
            modes = list(results)
            random.Random(repetition).shuffle(modes)
            for mode in modes:
                command = ['php', '-n']
                if mode != 'absent':
                    command += ['-d', 'extension=' + os.path.abspath(args.extension)]
                command += [script, mode, str(args.iterations), args.workload, '1' if args.sampling else '0', '1' if args.warm_disabled else '0']
                started = time.perf_counter_ns()
                sample = json.loads(subprocess.check_output(command, text=True))
                sample['process_ns'] = time.perf_counter_ns() - started
                if reference is None:
                    reference = sample['sum']
                if sample['sum'] != reference:
                    raise RuntimeError('profiling changed workload result')
                if sample.get('disabled_warmup') is not (args.warm_disabled and mode == 'disabled'):
                    raise RuntimeError('disabled-state warmup evidence disagrees with requested mode')
                validate_capture(sample, mode, args.iterations, args.workload)
                validate_timing(sample)
                validate_sampling(sample, mode, args.sampling, args.iterations, args.workload)
                if repetition:
                    results[mode].append(sample)
    summary = {}
    for mode, samples in results.items():
        values = [sample['elapsed_ns'] for sample in samples]
        cpu = [sample['cpu_ns'] for sample in samples]
        summary[mode] = {'median_ns': statistics.median(values), 'min_ns': min(values),
                         'max_ns': max(values), 'median_cpu_ns': statistics.median(cpu),
                         'min_cpu_ns': min(cpu), 'max_cpu_ns': max(cpu), 'samples': samples}
    for mode in ['disabled', 'enabled']:
        summary[mode]['median_ratio_to_absent'] = summary[mode]['median_ns'] / summary['absent']['median_ns']
        baseline_cpu = summary['absent']['median_cpu_ns']
        summary[mode]['median_cpu_ratio_to_absent'] = (summary[mode]['median_cpu_ns'] / baseline_cpu
                                                       if baseline_cpu else None)
    source_after = snapshot(root)
    extension_after = hashlib.sha256(Path(args.extension).read_bytes()).hexdigest()
    if source_after != source_before or extension_after != extension_before:
        raise RuntimeError('benchmark source or extension changed during capture')
    print(json.dumps({'source_sha256': source_before, 'source_snapshot_matches': True,
                      'extension_sha256': extension_before, 'extension_snapshot_matches': True,
                      'platform': platform.platform(), 'php': subprocess.check_output(['php', '-v'], text=True),
                      'extension': os.path.abspath(args.extension), 'iterations': args.iterations,
                      'repeats': args.repeats, 'workload': args.workload,
                      'sampling_requested': args.sampling,
                      'disabled_process_state': ('after a real stopped/reset capture; setup and teardown excluded from timing; allocator/cache state is also warmed'
                                                 if args.warm_disabled else 'profiling never started in the disabled process'),
                      'sampling_note': 'captures shorter than the timer interval may correctly retain zero samples; exact instrumentation counts and sampled counter/status consistency are checked separately; real unserved stop-boundary wakes remain disclosed as truncated sampling',
                      'complete_capture': bool(results['enabled']) and all(sample['capture_statuses'] == {'complete': args.iterations if args.workload == 'worker' else 1} for sample in results['enabled']),
                      'capture_validation': {'expected_sessions_per_enabled_sample': args.iterations if args.workload == 'worker' else 1, 'expected_leaf_calls_per_enabled_sample': args.iterations if args.workload in ('calls', 'worker') else 0},
                      'timed_region': 'per-iteration start/application/stop/report/reset lifecycle' if args.workload == 'worker' else 'application loop only; admission, stop, report retrieval and disable excluded',
                      'cpu_measurement': 'process user+system CPU from PHP getrusage; microsecond resolution converted to nanoseconds; brackets wall region and its clock reads; includes all process threads; null ratio when absent median rounds to zero',
                      'opcache': 'disabled (-n)', 'results': summary}, indent=2))


if __name__ == '__main__':
    main()
