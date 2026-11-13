"""Mixed C/Rust profile instrumentation must use the same LLVM major."""
import importlib.util
from pathlib import Path
import subprocess
import unittest

spec = importlib.util.spec_from_file_location('coverage_toolchain', Path(__file__).resolve().parents[2] / 'scripts/coverage_toolchain.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

class CoverageToolchainTests(unittest.TestCase):
    def test_matching_upstream_and_vendor_versions(self):
        rust = 'rustc 1.102.0-nightly\nLLVM version: 23.1.5\n'
        for clang in ('clang version 23.1.3', 'Ubuntu clang version 23.1.5', 'Apple clang version 23.0.0 (clang-2300)'):
            self.assertEqual(module.require_matching_llvm(rust, clang), 23)

    def test_actual_mismatched_apple_compiler_is_rejected(self):
        with self.assertRaisesRegex(ValueError, 'rustc uses 23, Clang uses 21'):
            module.require_matching_llvm('LLVM version: 23.1.5', 'Apple clang version 21.0.0 (clang-2100.4.1.1)')

    def test_unknown_compilers_and_missing_llvm_version_are_rejected(self):
        for rust, cc in [('rustc 1.99.1', 'clang version 23.1.3'), ('LLVM version: 23.1.5', 'gcc 15.1.0')]:
            with self.assertRaises(ValueError):
                module.require_matching_llvm(rust, cc)

    def test_command_arguments_are_preserved_and_failures_propagate(self):
        calls = []
        def run(command, **kwargs):
            calls.append(command)
            return 'LLVM version: 23.1.5' if command[0] == 'rustc' else 'clang version 23.1.3'
        self.assertEqual(module.check('"/path with spaces/clang" --target=aarch64-apple-darwin', 'nightly-2026-11-11', run), 23)
        self.assertEqual(calls, [['rustc', '+nightly-2026-11-11', '-vV'], ['/path with spaces/clang', '--target=aarch64-apple-darwin', '--version']])
        with self.assertRaisesRegex(ValueError, 'CC must name'):
            module.check(' ', 'nightly', run)
        def failure(*args, **kwargs):
            raise subprocess.CalledProcessError(2, 'clang')
        with self.assertRaises(subprocess.CalledProcessError):
            module.check('clang', 'nightly', failure)
