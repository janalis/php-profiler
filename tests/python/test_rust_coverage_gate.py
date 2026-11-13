"""Rust coverage must account for every expected runtime source file."""
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

SCRIPT = Path(__file__).resolve().parents[2] / 'scripts/check-coverage.py'
METRICS = {name: {'count': 2, 'covered': 2} for name in ('lines', 'functions', 'branches')}


class RustCoverageGateTests(unittest.TestCase):
    def check(self, missing=False, zero=False, duplicate=False, uncovered=False):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            directory = root / 'crates/engine/src'
            directory.mkdir(parents=True)
            source = directory / 'lib.rs'
            source.write_text('pub fn example(n: bool) -> bool { !n }\n')
            c_source = directory / 'bridge.c'
            c_source.write_text('int bridge(void) { return 1; }\n')
            if missing:
                (directory / 'storage.rs').write_text('pub fn storage() -> bool { true }\n')
            metrics = json.loads(json.dumps(METRICS))
            if zero:
                metrics['branches'] = {'count': 0, 'covered': 0}
            if uncovered:
                metrics['branches']['covered'] = 1
            entry = {'filename': str(source), 'summary': metrics}
            files = [entry, {'filename': str(c_source), 'summary': {}}]
            if duplicate:
                files.append(entry)
            report = root / 'report.json'
            report.write_text(json.dumps({'data': [{'totals': metrics, 'files': files}]}))
            return subprocess.run([sys.executable, str(SCRIPT), str(report), '--workspace-root', str(root),
                                   '--language', 'rust'], capture_output=True, text=True)

    def test_all_rust_files_are_gated_while_c_remains_a_separate_backend(self):
        result = self.check()
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_a_missing_runtime_file_is_rejected(self):
        result = self.check(missing=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('storage.rs', result.stderr)

    def test_zero_counters_are_not_a_measured_pass(self):
        result = self.check(zero=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('not measured', result.stderr)

    def test_duplicate_source_records_cannot_inflate_totals(self):
        result = self.check(duplicate=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('duplicate', result.stderr)

    def test_one_uncovered_edge_fails(self):
        result = self.check(uncovered=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('branches 1/2', result.stderr)


if __name__ == '__main__':
    unittest.main()
