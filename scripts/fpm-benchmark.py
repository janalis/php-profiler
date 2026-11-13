#!/usr/bin/env python3
"""Paired FPM request batches; no network service outside a private Unix socket."""
import argparse
import hashlib
import json
from pathlib import Path
import platform
import random
import statistics
import time
from fpm_support import FpmServer
from validation_inputs import snapshot
from benchmark import validate_timing, validate_sampling


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--extension', required=True)
    parser.add_argument('--repeats', type=int, default=11)
    parser.add_argument('--requests', type=int, default=20)
    parser.add_argument('--iterations', type=int, default=1000)
    parser.add_argument('--sampling', action='store_true')
    args = parser.parse_args()
    if args.repeats < 3 or args.requests < 1 or args.iterations < 1:
        parser.error('use at least three repeats and positive request/iteration counts')
    root = Path(__file__).resolve().parents[1]
    source_before = snapshot(root)
    extension_before = hashlib.sha256(Path(args.extension).read_bytes()).hexdigest()
    expected = sum((i * 3) % 97 for i in range(args.iterations))
    samples = {mode: [] for mode in ['absent', 'disabled', 'enabled']}
    workloads = {mode: [] for mode in samples}
    metadata = {}
    for repetition in range(args.repeats + 1):
        modes = list(samples)
        random.Random(repetition).shuffle(modes)
        for mode in modes:
            with FpmServer(None if mode == 'absent' else str(Path(args.extension).resolve())) as server:
                def request():
                    result = server.request('tests/fpm/benchmark.php',
                                            {'PROFILE_MODE': mode, 'PROFILE_ITERATIONS': str(args.iterations),
                                             'PROFILE_SAMPLING': '1' if args.sampling else '0'})
                    if result['sum'] != expected:
                        raise RuntimeError('application response changed')
                    validate_timing(result)
                    validate_sampling(result, mode, args.sampling, args.iterations, 'calls')
                    if mode == 'enabled' and (result['status'] != 'complete' or result['calls'] != args.iterations):
                        raise RuntimeError('benchmark capture incomplete or wrong call count')
                    metadata[mode] = {key: result[key] for key in ['php', 'zts', 'sapi', 'opcache', 'jit']}
                    return {key: result[key] for key in ('elapsed_ns', 'cpu_ns', 'sampling_statuses', 'sampling_samples', 'sampling_reports')}
                request()  # Warm the reused worker before timing.
                started = time.perf_counter_ns()
                measured = []
                for _ in range(args.requests):
                    measured.append(request())
                elapsed = time.perf_counter_ns() - started
                if repetition:
                    samples[mode].append(elapsed)
                    workloads[mode].append(measured)
    summary = {mode: {'median_batch_ns': statistics.median(values), 'min_batch_ns': min(values),
                      'max_batch_ns': max(values), 'samples_batch_ns': values} for mode, values in samples.items()}
    for mode, batches in workloads.items():
        summary[mode]['workload_samples'] = batches
        for field in ('elapsed_ns', 'cpu_ns'):
            summary[mode]['median_workload_batch_' + field] = statistics.median(
                sum(sample[field] for sample in batch) for batch in batches)
    for mode in ['disabled', 'enabled']:
        summary[mode]['ratio_to_absent'] = summary[mode]['median_batch_ns'] / summary['absent']['median_batch_ns']
        baseline_cpu = summary['absent']['median_workload_batch_cpu_ns']
        summary[mode]['workload_cpu_ratio_to_absent'] = (summary[mode]['median_workload_batch_cpu_ns'] / baseline_cpu
                                                         if baseline_cpu else None)
    source_after = snapshot(root)
    extension_after = hashlib.sha256(Path(args.extension).read_bytes()).hexdigest()
    if source_before != source_after or extension_before != extension_after:
        raise RuntimeError('benchmark source or extension changed during capture')
    print(json.dumps({'platform': platform.platform(), 'metadata': metadata, 'repeats': args.repeats,
                      'requests_per_batch': args.requests, 'iterations_per_request': args.iterations,
                      'sampling_requested': args.sampling,
                      'source_sha256': source_before, 'source_snapshot_matches': True,
                      'extension_sha256': extension_before, 'extension_snapshot_matches': True,
                      'workload_cpu_measurement': 'per-request process user+system CPU from getrusage, microsecond resolution converted to nanoseconds; brackets application-loop wall region and clock reads; excludes stop/report and FastCGI transport; null ratio when absent median rounds to zero',
                      'complete_capture': True, 'timed_region': 'full reused-worker request batches including application, stop/report and local FastCGI transport; worker startup excluded', 'results': summary}, indent=2))


if __name__ == '__main__':
    main()
