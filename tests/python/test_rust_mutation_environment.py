"""Private Zend contracts belong to the DSO, never standalone Rust executables."""
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
FLAGS = ('PROFILER_ADAPTER_CONTRACT_TEST', 'PROFILER_C_UNIT_BOUNDARIES', 'PROFILER_SAMPLING_UNIT')

class RustMutationEnvironmentTests(unittest.TestCase):
    def test_inherited_private_flags_are_removed_from_all_cargo_invocations(self):
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            cargo = directory / 'cargo'
            cargo.write_text('''#!/usr/bin/env python3
import json, os, sys
from pathlib import Path
root = Path(os.environ['MUTATION_TEST_ROOT'])
flags = ('PROFILER_ADAPTER_CONTRACT_TEST', 'PROFILER_C_UNIT_BOUNDARIES', 'PROFILER_SAMPLING_UNIT')
with open(os.environ['MUTATION_TEST_LOG'], 'a') as output:
    output.write(json.dumps({'args': sys.argv[1:], 'private': {key: os.environ.get(key) for key in flags}}) + '\\n')
if sys.argv[1] == 'metadata':
    print(json.dumps({'workspace_members': ['core', 'ext'], 'packages': [{'name': 'profiler-core', 'id': 'core', 'manifest_path': str(root/'crates/profiler-core/Cargo.toml')}, {'name': 'php-profiler', 'id': 'ext', 'manifest_path': str(root/'crates/php-profiler/Cargo.toml')}]}))
else:
    sys.path.insert(0, str(root/'scripts'))
    from runtime_sources import runtime_sources
    print(json.dumps([{'file': str(path.relative_to(root))} for path in runtime_sources(root, 'rust')]))
''')
            cargo.chmod(0o755)
            log = directory / 'calls.jsonl'
            environment = dict(os.environ, PATH=str(directory)+os.pathsep+os.environ['PATH'], MUTATION_TEST_ROOT=str(ROOT), MUTATION_TEST_LOG=str(log), **{key:'1' for key in FLAGS})
            environment.pop('CARGO_TARGET_DIR', None)
            output = directory / 'output'
            result = subprocess.run([sys.executable, str(ROOT/'scripts/rust-mutation.py'), '--list-only', '--output', str(output)], env=environment, text=True, capture_output=True, timeout=30)
            self.assertEqual(result.returncode, 0, result.stdout+result.stderr)
            calls = [json.loads(line) for line in log.read_text().splitlines()]
            self.assertEqual(len(calls), 3)
            self.assertTrue(all(set(call['private'].values()) == {None} for call in calls))
            manifest = json.loads((output/'latest.json').read_text())
            self.assertEqual(manifest['private_contract_environment']['native_cdylib_build'], {key:'1' for key in FLAGS})
            self.assertEqual(set(manifest['runtime_sources']), set(manifest['all_workspace_runtime_sources']))
