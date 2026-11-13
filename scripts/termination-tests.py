#!/usr/bin/env python3
"""Compare real exit/exception behavior and verify shutdown profile finalization."""
import json
import os
import platform
from pathlib import Path
import subprocess
import tempfile

extension = os.environ['PROFILER_EXTENSION']
child_env = dict(os.environ)
if child_env.get('PROFILER_ASAN_RUNTIME'):
    key = 'DYLD_INSERT_LIBRARIES' if platform.system() == 'Darwin' else 'LD_PRELOAD'
    child_env[key] = child_env['PROFILER_ASAN_RUNTIME']
fixture = str(Path(__file__).resolve().parent.parent / 'tests/fixtures/termination.php')
with tempfile.TemporaryDirectory(prefix='php-profiler-termination-') as directory:
    for mode, expected in [('normal', 0), ('exit', 7), ('exception', 255)]:
        path = str(Path(directory) / (mode + '.json'))
        baseline = subprocess.run(['php', '-n', fixture, path, mode], capture_output=True, env=child_env)
        profiled = subprocess.run(['php', '-n', '-d', 'extension=' + extension, fixture, path, mode], capture_output=True, env=child_env)
        if baseline.returncode != expected or profiled.returncode != baseline.returncode:
            raise RuntimeError(f'{mode}: exit semantics changed: {baseline.returncode}/{profiled.returncode}')
        if profiled.stdout != baseline.stdout or profiled.stderr != baseline.stderr:
            raise RuntimeError(f'{mode}: application output changed')
        with open(path) as source:
            report = json.load(source)
        if report['functions']['termination_leaf']['calls'] != 1:
            raise RuntimeError(f'{mode}: shutdown lost call')
print('normal/exit/uncaught exception semantics and shutdown persistence passed')
