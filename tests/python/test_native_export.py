"""Coverage exports must preserve executable identity and reject uncertain data."""
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

SCRIPT = Path(__file__).resolve().parents[2] / 'scripts/export-native-coverage.py'


class NativeExportTests(unittest.TestCase):
    def run_export(self, stderr='', status=0, missing=False):
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            artifacts = [directory / 'core-test', directory / 'adapter-test']
            for index, artifact in enumerate(artifacts):
                if not (missing and index == 1):
                    artifact.touch()
                message = {'reason': 'compiler-artifact', 'executable': str(artifact),
                           'profile': {'test': True}, 'target': {'kind': ['lib' if index == 0 else 'cdylib']}}
                (directory / ('core-tests.jsonl' if index == 0 else 'adapter-tests.jsonl')).write_text(
                    'test result: ok\n' + json.dumps(message) + '\n')
            llvm = directory / 'llvm-cov'
            llvm.write_text('#!' + sys.executable + '\nimport sys\n'
                            'print(\'{"data": []}\')\n'
                            'sys.stderr.write(' + repr(stderr) + ')\n'
                            'sys.exit(' + repr(status) + ')\n')
            llvm.chmod(0o700)
            extension = directory / 'native.so'
            extension.touch()
            result = subprocess.run([sys.executable, str(SCRIPT), str(llvm), str(directory), str(extension), str(directory / "merged.profdata")],
                                    capture_output=True, text=True)
            diagnostics = directory / 'export-diagnostics.json'
            return result, json.loads(diagnostics.read_text()) if diagnostics.exists() else None, extension

    def test_exact_unit_artifacts_and_native_primary_are_recorded(self):
        result, diagnostics, extension = self.run_export()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(diagnostics['command'][2], str(extension))
        self.assertEqual(len(diagnostics['test_objects']), 2)
        self.assertEqual(diagnostics['exit_code'], 0)

    def test_missing_cargo_artifact_fails_before_export(self):
        result, diagnostics, _ = self.run_export(missing=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('disappeared', result.stderr)
        self.assertIsNone(diagnostics)

    def test_llvm_failure_is_retained_and_rejected(self):
        result, diagnostics, _ = self.run_export(stderr='malformed profile', status=7)
        self.assertEqual(result.returncode, 7)
        self.assertEqual(diagnostics['stderr'], 'malformed profile')

    def test_zero_exit_with_mismatched_map_is_still_rejected(self):
        result, diagnostics, _ = self.run_export(stderr='warning: 1 functions have mismatched data\n')
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(diagnostics['exit_code'], 0)
        self.assertIn('mismatched', diagnostics['stderr'])


if __name__ == '__main__':
    unittest.main()
