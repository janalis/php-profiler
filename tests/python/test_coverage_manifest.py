"""Coverage evidence must fail closed when inputs drift or outputs disappear."""
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

SCRIPT = Path(__file__).resolve().parents[2] / 'scripts/coverage-run-manifest.py'


class CoverageManifestTests(unittest.TestCase):
    def check(self, drift=False, missing=False, status=0):
        with tempfile.TemporaryDirectory() as temporary:
            parent = Path(temporary)
            run = parent / 'run-example'
            run.mkdir()
            (run / 'source-before.json').write_text(json.dumps({'engine.rs': 'before'}))
            (run / 'source-after.json').write_text(json.dumps({'engine.rs': 'after' if drift else 'before'}))
            if not missing:
                (run / 'coverage.json').write_text('{"measured": true}')
            result = subprocess.run([sys.executable, str(SCRIPT), str(run), str(parent), str(status),
                                     '--artifact', 'coverage.json'], capture_output=True, text=True)
            manifest = json.loads((run / 'run.json').read_text())
            self.assertEqual(json.loads((parent / 'latest.json').read_text()), manifest)
            return result, manifest

    def test_matching_inputs_and_present_artifacts_preserve_success(self):
        result, manifest = self.check()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertTrue(manifest['source_snapshot_matches'])
        self.assertEqual(len(manifest['artifact_sha256']['coverage.json']), 64)

    def test_source_drift_invalidates_an_otherwise_successful_run(self):
        result, manifest = self.check(drift=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse(manifest['source_snapshot_matches'])
        self.assertEqual(manifest['changed_inputs'], ['engine.rs'])

    def test_missing_report_invalidates_success(self):
        result, manifest = self.check(missing=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(manifest['missing_artifacts'], ['coverage.json'])

    def test_tool_failure_is_not_erased_by_matching_sources(self):
        result, manifest = self.check(status=7)
        self.assertEqual(result.returncode, 7)
        self.assertEqual(manifest['exit_code'], 7)


if __name__ == '__main__':
    unittest.main()
