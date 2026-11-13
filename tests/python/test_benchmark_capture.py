"""Benchmark results must prove capture happened and every requested call exists."""
import importlib.util
from pathlib import Path
import unittest

path = Path(__file__).resolve().parents[2] / 'scripts' / 'benchmark.py'
spec = importlib.util.spec_from_file_location('profiler_benchmark', path)
benchmark = importlib.util.module_from_spec(spec)
spec.loader.exec_module(benchmark)


class CaptureValidationTests(unittest.TestCase):
    def test_sampled_benchmarks_validate_each_report_and_disclose_boundary_loss(self):
        import copy
        report = {'status': 'complete', 'captured_samples': 2, 'truncated_stacks': 0,
                  'final_counters_available': True, 'timestamps_valid': True,
                  'counters_valid': True, 'counter_overflow': False,
                  'failure_reason': None, 'termination_reason': 'stopped',
                  'counters': {'serviced_wakes': 3, 'coalesced_wakes': 1,
                               'dropped_ticks': 0, 'dropped_samples': 0,
                               'unserved_wakes': 0, 'invalid_observations': 0}}
        sample = {'sampling_statuses': {'complete': 1}, 'sampling_samples': 2,
                  'sampling_reports': [report]}
        benchmark.validate_sampling(sample, 'enabled', True, 7, 'calls')
        boundary = copy.deepcopy(sample)
        boundary['sampling_reports'][0]['counters']['unserved_wakes'] = 1
        boundary['sampling_reports'][0]['status'] = 'truncated'
        boundary['sampling_statuses'] = {'truncated': 1}
        benchmark.validate_sampling(boundary, 'enabled', True, 7, 'calls')
        worker = {'sampling_statuses': {'complete': 7}, 'sampling_samples': 14,
                  'sampling_reports': [report] * 7}
        benchmark.validate_sampling(worker, 'enabled', True, 7, 'worker')
        for key, invalid in [('final_counters_available', False), ('timestamps_valid', False),
                             ('counters_valid', False), ('counter_overflow', True),
                             ('failure_reason', 'clock_failure'), ('termination_reason', 'fork'),
                             ('truncated_stacks', 1), ('captured_samples', True),
                             ('captured_samples', 3), ('status', 'truncated')]:
            bad = copy.deepcopy(sample); bad['sampling_reports'][0][key] = invalid
            with self.subTest(key=key), self.assertRaises(RuntimeError):
                benchmark.validate_sampling(bad, 'enabled', True, 7, 'calls')
        for key in report['counters']:
            for invalid in (-1, True, None):
                bad = copy.deepcopy(sample); bad['sampling_reports'][0]['counters'][key] = invalid
                with self.subTest(key=key, invalid=invalid), self.assertRaises(RuntimeError):
                    benchmark.validate_sampling(bad, 'enabled', True, 7, 'calls')
        for key in ('dropped_ticks', 'dropped_samples', 'invalid_observations'):
            bad = copy.deepcopy(sample); bad['sampling_reports'][0]['counters'][key] = 1
            with self.subTest(loss=key), self.assertRaises(RuntimeError):
                benchmark.validate_sampling(bad, 'enabled', True, 7, 'calls')
        for key, invalid in [('sampling_statuses', {'complete': True}),
                             ('sampling_samples', 3), ('sampling_samples', -1),
                             ('sampling_reports', []), ('sampling_reports', None)]:
            bad = copy.deepcopy(sample); bad[key] = invalid
            with self.subTest(summary=key), self.assertRaises(RuntimeError):
                benchmark.validate_sampling(bad, 'enabled', True, 7, 'calls')
        for mode in ('absent', 'disabled', 'enabled'):
            benchmark.validate_sampling({'sampling_statuses': [], 'sampling_samples': 0,
                                         'sampling_reports': []}, mode, False, 7, 'calls')
            with self.subTest(mode=mode), self.assertRaises(RuntimeError):
                benchmark.validate_sampling(sample, mode, False, 7, 'calls')

    def test_cpu_and_wall_counters_are_independent_measured_integers(self):
        benchmark.validate_timing({'elapsed_ns': 100, 'cpu_ns': 0})
        benchmark.validate_timing({'elapsed_ns': 10, 'cpu_ns': 100})
        for field in ('elapsed_ns', 'cpu_ns'):
            for invalid in (None, -1, True, 1.0, '1'):
                sample = {'elapsed_ns': 100, 'cpu_ns': 100}
                sample[field] = invalid
                with self.subTest(field=field, invalid=invalid), self.assertRaises(RuntimeError):
                    benchmark.validate_timing(sample)
            with self.subTest(missing=field), self.assertRaises(RuntimeError):
                benchmark.validate_timing({key: 1 for key in ('elapsed_ns', 'cpu_ns') if key != field})

    def test_exact_application_and_worker_captures_are_accepted(self):
        benchmark.validate_capture({'capture_statuses': {'complete': 1}, 'leaf_calls': 17}, 'enabled', 17, 'calls')
        benchmark.validate_capture({'capture_statuses': {'complete': 17}, 'leaf_calls': 17}, 'enabled', 17, 'worker')
        benchmark.validate_capture({'capture_statuses': {'complete': 1}, 'leaf_calls': 0}, 'enabled', 17, 'cpu')
        for mode in ('absent', 'disabled'):
            for statuses in ([], {}):
                benchmark.validate_capture({'capture_statuses': statuses, 'leaf_calls': 0}, mode, 17, 'calls')

    def test_enabled_empty_or_wrong_status_counts_are_rejected(self):
        for statuses in ([], {}, {'complete': 0}, {'complete': True}, {'complete': 2}, {'complete': 1, 'truncated': 1}, {'incomplete': 1}):
            with self.subTest(statuses=statuses), self.assertRaises(RuntimeError):
                benchmark.validate_capture({'capture_statuses': statuses, 'leaf_calls': 17}, 'enabled', 17, 'calls')
        with self.assertRaises(RuntimeError):
            benchmark.validate_capture({'capture_statuses': {'complete': 1}, 'leaf_calls': 17}, 'enabled', 17, 'worker')

    def test_missing_wrong_or_estimated_leaf_counts_are_rejected(self):
        for calls in (None, 0, 16, 18, 17.0, '17', True):
            with self.subTest(calls=calls), self.assertRaises(RuntimeError):
                benchmark.validate_capture({'capture_statuses': {'complete': 1}, 'leaf_calls': calls}, 'enabled', 17, 'calls')
        with self.assertRaises(RuntimeError):
            benchmark.validate_capture({'capture_statuses': {'complete': 17}, 'leaf_calls': 16}, 'enabled', 17, 'worker')
        with self.assertRaises(RuntimeError):
            benchmark.validate_capture({'capture_statuses': {'complete': 1}, 'leaf_calls': 1}, 'enabled', 17, 'memory')

    def test_disabled_modes_cannot_claim_recorded_data(self):
        for mode in ('absent', 'disabled'):
            with self.subTest(mode=mode), self.assertRaises(RuntimeError):
                benchmark.validate_capture({'capture_statuses': {'complete': 1}, 'leaf_calls': 0}, mode, 17, 'calls')
            with self.subTest(mode=mode), self.assertRaises(RuntimeError):
                benchmark.validate_capture({'capture_statuses': [], 'leaf_calls': 17}, mode, 17, 'calls')


if __name__ == '__main__':
    unittest.main()
