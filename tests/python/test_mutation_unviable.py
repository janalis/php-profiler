import json
from pathlib import Path
import tempfile
import unittest
import os
import subprocess
import sys
sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "scripts"))
from mutation_unviable import audit, classify_log

class DiagnosticTests(unittest.TestCase):
    def log(self, diagnostic):
        return '*** mutation diff:\n+ error[E9999]: text in user string\n*** cargo nextest run --no-run\n' + diagnostic

    def test_rejects_infrastructure_even_with_rust_error(self):
        for error in ['unable to copy x.o: No such file or directory', 'linking with cc failed: exit status: 1',
                      'could not execute process rustc', 'failed to download package', 'No space left on device']:
            with self.subTest(error=error):
                self.assertEqual(classify_log(self.log('error[E0308]: mismatched types\nerror: '+error))['classification'], 'infrastructure_or_unknown')

    def test_compile_rejects_and_summary(self):
        for text in ['error[E0308]: mismatched types', 'error: `||` operators are not supported in let chain conditions']:
            self.assertEqual(classify_log(self.log(text+'\nerror: could not compile `x` (lib) due to 1 previous error'))['classification'], 'compiler_rejected')

    def test_diff_is_not_diagnostic(self):
        self.assertEqual(classify_log(self.log('error: command `cargo` exited with code 101'))['classification'], 'infrastructure_or_unknown')
        self.assertEqual(classify_log('error[E0308]: text without command')['classification'], 'infrastructure_or_unknown')

    def test_unknown_error_code_fails_closed(self):
        self.assertEqual(classify_log(self.log('error[E0463]: cannot find crate std'))['classification'], 'infrastructure_or_unknown')

    def test_unknown_error_fails_closed(self):
        self.assertEqual(classify_log(self.log('error: future unknown problem'))['classification'], 'infrastructure_or_unknown')

    def test_parallel_shared_target_rejected_before_cargo(self):
        root = Path(__file__).resolve().parents[2]
        with tempfile.TemporaryDirectory() as directory:
            directory = Path(directory)
            binary = directory / 'cargo'
            binary.write_text('#!/bin/sh\ntouch "$PROFILER_TEST_METADATA_MARKER"\nexit 42\n')
            binary.chmod(0o700)
            marker = directory / 'metadata-called'
            environment = dict(os.environ, PATH=str(directory)+os.pathsep+os.environ['PATH'],
                               CARGO_TARGET_DIR=str(directory/'shared'),
                               PROFILER_TEST_METADATA_MARKER=str(marker))
            command = [sys.executable, str(root/'scripts/rust-mutation.py'), '--list-only',
                       '--output', str(directory/'result')]
            rejected = subprocess.run(command+['--jobs', '2'], env=environment,
                                      text=True, capture_output=True, timeout=10)
            self.assertEqual(rejected.returncode, 2)
            self.assertIn('separate target directories', rejected.stderr)
            self.assertFalse(marker.exists())
            admitted = subprocess.run(command+['--jobs', '1'], env=environment,
                                     text=True, capture_output=True, timeout=10)
            self.assertNotEqual(admitted.returncode, 0)
            self.assertTrue(marker.exists())

    def test_log_path_cannot_escape(self):
        with tempfile.TemporaryDirectory() as d:
            with self.assertRaisesRegex(ValueError, 'escapes'):
                audit({'unviable':1,'outcomes':[{'summary':'Unviable','log_path':'../outside'}]},d)

    def test_count_and_phase_must_agree(self):
        with self.assertRaisesRegex(ValueError, 'count'):
            audit({'unviable':1,'outcomes':[]},'.')
        with tempfile.TemporaryDirectory() as d:
            Path(d,'log').write_text(self.log('error[E0308]: mismatch'))
            r=audit({'unviable':1,'outcomes':[{'summary':'Unviable','log_path':'log','scenario':{},'phase_results':[{'phase':'Test'}]}]},d)
            self.assertEqual(r['infrastructure_or_unknown'],1)

if __name__=='__main__':unittest.main()
