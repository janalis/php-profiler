"""Every exported cohort must retain exact objects and reject uncertain data."""
import json
import importlib.util
from unittest.mock import patch
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / 'scripts/export-native-coverage.py'
sys.path.insert(0, str(ROOT / 'scripts'))
from runtime_sources import runtime_sources


class NativeExportTests(unittest.TestCase):
    def run_export(self, stderr='', status=0, missing=False, missing_profiles=False):
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
            export = {'type': 'llvm.coverage.json.export', 'data': [{'functions': [], 'files': []}]}
            for source in sorted(runtime_sources(ROOT, 'rust')):
                export['data'][0]['functions'].append({'name': 'fixture_function', 'count': 1,
                    'filenames': [str(source)], 'regions': [[1, 1, 1, 9, 1, 0, 0, 0]],
                    'branches': [[1, 2, 1, 4, 1, 1, 0, 0, 4]]})
                export['data'][0]['files'].append({'filename': str(source),
                    'segments': [[1, 1, 1, True, True, False], [1, 9, 0, False, False, False]],
                    'branches': [[1, 2, 1, 4, 1, 1, 0, 0, 4]],
                    'summary': {'lines': {'count': 1, 'covered': 1},
                                'functions': {'count': 1, 'covered': 1},
                                'branches': {'count': 2, 'covered': 2}}})
            llvm = directory / 'llvm-cov'
            llvm.write_text('#!' + sys.executable + '\nimport sys\n'
                            'print(' + repr(json.dumps(export)) + ')\n'
                            'sys.stderr.write(' + repr(stderr) + ')\n'
                            'sys.exit(' + repr(status) + ')\n')
            llvm.chmod(0o700)
            profdata = directory / 'llvm-profdata'
            profdata.write_text('#!' + sys.executable + '\nimport sys\nfrom pathlib import Path\nPath(sys.argv[sys.argv.index("-o")+1]).write_bytes(b"fixture")\n')
            profdata.chmod(0o700)
            for cohort in ('core', 'adapter', 'native'):
                if not (missing_profiles and cohort == 'native'):
                    (directory / (cohort + '-100-123.profraw')).write_bytes(cohort.encode())
            (directory / 'unrelated-100.profraw').touch()
            extension = directory / 'native.so'
            extension.touch()
            result = subprocess.run([sys.executable, str(SCRIPT), str(llvm), str(directory), str(extension), str(directory / 'merged.profdata')], capture_output=True, text=True, cwd=ROOT)
            diagnostics = directory / 'export-diagnostics.json'
            if not diagnostics.exists():
                diagnostics = directory / 'cohorts/core-diagnostics.json'
            receipt = json.loads(diagnostics.read_text()) if diagnostics.exists() else None
            return result, receipt, extension

    def test_exact_objects_and_disjoint_actual_profiles_are_recorded(self):
        result, diagnostics, extension = self.run_export()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual([entry['cohort'] for entry in diagnostics['cohorts']], ['core', 'adapter', 'native'])
        for entry in diagnostics['cohorts']:
            self.assertEqual(len(entry['objects']), 1)
            self.assertEqual(len(entry['raw_profiles']), 1)
            self.assertTrue(next(iter(entry['raw_profiles'])).endswith(entry['cohort'] + '-100-123.profraw'))
        self.assertEqual(list(diagnostics['cohorts'][-1]['objects']), [str(extension.resolve())])
        self.assertEqual(diagnostics['exit_code'], 0)

    def test_demangler_canonicalizes_gnu_and_llvm_without_losing_generics(self):
        spec = importlib.util.spec_from_file_location('native_export', SCRIPT)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        exports = [{'data': [{'functions': [{'name': '_Rvalid'}]}]}]
        for version, flags in [('GNU c++filt 2.40', ['-n', '-i']), ('LLVM cxxfilt 23.1', ['-n'])]:
            with self.subTest(version=version), patch.object(module.shutil, 'which', return_value='/tools/filt'), patch.object(module.subprocess, 'check_output', side_effect=[version, 'crate::generic::<u64>\n']) as command:
                self.assertEqual(module.demangled_names(exports), {'_Rvalid': 'crate::generic::<u64>'})
                self.assertEqual(command.call_args.args[0], ['/tools/filt', *flags])

    def test_missing_cargo_artifact_fails_before_export(self):
        result, diagnostics, _ = self.run_export(missing=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('disappeared', result.stderr)
        self.assertIsNone(diagnostics)

    def test_missing_actual_cohort_profiles_fails(self):
        result, _, _ = self.run_export(missing_profiles=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('No actual raw profiles', result.stderr)

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
