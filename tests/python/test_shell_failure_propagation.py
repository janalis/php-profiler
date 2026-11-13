"""Validation must stop when a prerequisite returns failure, even with stdout."""
import os
from pathlib import Path
import shlex
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


class ShellFailurePropagationTests(unittest.TestCase):
    def executable(self, path, body):
        path.write_text('#!/bin/bash\nset -eu\n' + body)
        path.chmod(0o755)

    def environment(self, directory):
        tools = directory / 'tool commands'
        tools.mkdir()
        output = directory / 'output'
        output.mkdir()
        runtime = directory / 'runtime'
        runtime.mkdir()
        (runtime / 'libclang_rt.profile-aarch64.a').write_bytes(b'')
        # Coherent discovery fixture only; the failure oracle fires before any
        # compiler/bindgen can load this placeholder library.
        (runtime / 'libclang.so').write_bytes(b'')
        environment = dict(os.environ, PATH=str(tools) + os.pathsep + os.environ['PATH'],
                           CARGO_TARGET_DIR=str(output), CC=str(tools / 'cc'),
                           LLVM_COV=str(tools / 'cc'), PROFILER_FAULT_OUTPUT=str(output),
                           PROFILER_FAULT_RUNTIME=str(runtime), PROFILER_FAULT_ROOT=str(directory))
        self.executable(tools / 'cargo', 'touch "$PROFILER_FAULT_ROOT/cargo-called"\nexit 0\n')
        self.executable(tools / 'php', 'touch "$PROFILER_FAULT_ROOT/php-called"\nexit 91\n')
        self.executable(tools / 'rustc', '''if [[ "$*" == *"--print target-libdir"* ]]; then
  printf '%s\\n' "$PROFILER_FAULT_RUNTIME"
  exit 73
fi
printf 'host: aarch64-apple-darwin\\nLLVM version: 23.1.3\\n'
''')
        self.executable(tools / 'cc', '''touch "$PROFILER_FAULT_ROOT/compiler-called"
case "${1:-}" in
  --print-resource-dir) printf '%s\\n' "$PROFILER_FAULT_RUNTIME/clang/23" ;;
  --print-runtime-dir) printf '%s\\n' "$PROFILER_FAULT_RUNTIME" ;;
  -print-target-triple) printf 'aarch64-unknown-linux-gnu\\n' ;;
  -print-file-name=*) printf '%s\\n' "$PROFILER_FAULT_RUNTIME/missing.so"; exit 73 ;;
  *) printf 'clang version 23.1.3\\n' ;;
esac
''')
        return tools, environment

    def run_script(self, name, environment):
        return subprocess.run(['bash', str(ROOT / 'scripts' / name)], env=environment,
                              capture_output=True, text=True, timeout=30)

    def test_failed_temporary_directory_is_not_exported_as_success(self):
        with tempfile.TemporaryDirectory(prefix='profiler-shell-contract-') as temporary:
            directory = Path(temporary)
            tools, environment = self.environment(directory)
            self.executable(tools / 'mktemp', 'printf "%s\\n" "$PROFILER_FAULT_OUTPUT"\nexit 73\n')
            result = self.run_script('clang-gcov.sh', environment)
            self.assertEqual(result.returncode, 73, result.stdout + result.stderr)
            self.assertFalse((directory / 'compiler-called').exists())
            self.assertFalse((directory / 'cargo-called').exists())

    def test_failed_counter_flag_generation_never_reaches_cargo(self):
        for script in ('native-coverage.sh', 'clang-gcov.sh'):
            with self.subTest(script=script), tempfile.TemporaryDirectory(prefix='profiler-shell-contract-') as temporary:
                directory = Path(temporary)
                tools, environment = self.environment(directory)
                self.executable(tools / 'python3', '''if [[ "${1:-}" == - ]] && { [[ $# == 1 ]] || [[ "${2:-}" == *.a ]]; }; then
  exit 73
fi
exec ''' + shlex.quote(sys.executable) + ' "$@"\n')
                result = self.run_script(script, environment)
                self.assertEqual(result.returncode, 73, result.stdout + result.stderr)
                self.assertFalse((directory / 'cargo-called').exists())

    def test_failed_sanitizer_runtime_lookup_never_launches_php(self):
        with tempfile.TemporaryDirectory(prefix='profiler-shell-contract-') as temporary:
            directory = Path(temporary)
            _, environment = self.environment(directory)
            result = self.run_script('native-sanitizers.sh', environment)
            self.assertEqual(result.returncode, 73, result.stdout + result.stderr)
            self.assertTrue((directory / 'cargo-called').exists())
            self.assertFalse((directory / 'php-called').exists())


if __name__ == '__main__':
    unittest.main()
