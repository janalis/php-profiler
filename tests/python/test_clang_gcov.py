"""Every actual C source and raw edge must satisfy the independent compiler gate."""
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

SCRIPT = Path(__file__).resolve().parents[2] / 'scripts/check-clang-gcov.py'
RAW = ('file:src/bridge.c\nfunction:1,2,example\nlcount:1,2\nlcount:2,1\n'
       'branch:1,taken\nbranch:1,taken\n')


class ClangGcovTests(unittest.TestCase):
    def check(self, raw=RAW, extra_source=False, ambiguous=False):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            source = root / 'crates/adapter/src/bridge.c'
            source.parent.mkdir(parents=True)
            source.write_text('int example(int n) { return n ? 1 : 2; }\n')
            if extra_source:
                (source.parent / 'missing.c').write_text('int missing(void) { return 1; }\n')
            if ambiguous:
                other = root / 'crates/other/src/bridge.c'
                other.parent.mkdir(parents=True)
                other.write_text(source.read_text())
            reports = root / 'reports'
            reports.mkdir()
            (reports / 'bridge.gcov').write_text(raw)
            result = subprocess.run([sys.executable, str(SCRIPT), str(reports), '--workspace-root', str(root)],
                                    capture_output=True, text=True)
            summary = reports / 'strict-summary.json'
            return result, json.loads(summary.read_text()) if summary.exists() else None

    def test_all_raw_counters_and_unique_crate_relative_path_pass(self):
        result, summary = self.check()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertTrue(summary['passed'])
        self.assertEqual(len(next(iter(summary['files'].values()))['branches']), 2)

    def test_an_uncovered_edge_fails_without_discarding_it(self):
        result, summary = self.check(raw=RAW.replace('branch:1,taken', 'branch:1,nottaken', 1))
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse(summary['passed'])
        self.assertEqual(len(next(iter(summary['files'].values()))['branches']), 2)

    def test_missing_first_party_source_is_not_an_empty_pass(self):
        result, summary = self.check(extra_source=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse(summary['passed'])

    def test_ambiguous_compiler_relative_path_fails_closed(self):
        result, summary = self.check(ambiguous=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('Ambiguous', result.stderr)
        self.assertIsNone(summary)

    def test_unknown_compiler_edge_encoding_fails_closed(self):
        result, summary = self.check(raw=RAW.replace('branch:1,taken', 'branch:1,unknown', 1))
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('Unknown', result.stderr)
        self.assertIsNone(summary)


if __name__ == '__main__':
    unittest.main()
