#!/usr/bin/env python3
"""Deterministic exception-allocation bailout ownership, separate from natural OOM."""
import json
import os
from pathlib import Path
import shlex
import subprocess
import sys
import tempfile
from exception_fixture_support import exception_fixture

root = Path(__file__).resolve().parent.parent
os.chdir(root)
php = os.environ.get('PHP', 'php')
config = os.environ.get('PHP_CONFIG', 'php-config')
os.environ.pop('PROFILER_TEST_CLONE_BAILOUT', None)
if sys.platform != 'darwin':
    os.environ['PROFILER_TEST_EXCEPTION_BAILOUT'] = '1'
fixture = exception_fixture()
with tempfile.TemporaryDirectory(prefix='profiler-exception-bailout-') as temporary:
    directory = Path(temporary)
    environment = os.environ.copy()
    if sys.platform == 'darwin':
        includes = shlex.split(subprocess.check_output([config, '--includes'], text=True))
        interposer = directory / 'exception-bailout.dylib'
        subprocess.run(shlex.split(os.environ.get('CC', 'cc')) + includes +
                       ['-dynamiclib', '-undefined', 'dynamic_lookup',
                        'tests/native/exception-bailout.c', '-o', str(interposer)], check=True)
        environment['DYLD_INSERT_LIBRARIES'] = str(interposer)
    for case in (6, 7):
        counts_path = directory / f'counts-{case}.json'
        result = subprocess.run([php, '-n', '-d', 'memory_limit=-1', '-d', 'extension=' + fixture,
                                 'tests/native/exception-bailout.php', str(counts_path), str(case)],
                                env=environment, capture_output=True, text=True, timeout=30)
        output = result.stdout + result.stderr
        if (result.returncode != 255 or 'BEFORE_EXCEPTION' not in output or
                'EXCEPTION_ALLOCATION_BAILOUT' not in output or 'AFTER_EXCEPTION' in output or
                'CAUGHT_FATAL' in output or not counts_path.exists()):
            raise RuntimeError(f'exception bailout case{case} fatal semantics failed exit={result.returncode}:\n{output}')
        counts = json.loads(counts_path.read_text())
        print(f'exception injected bailout case{case} Rust ownership:', json.dumps(counts))
        if counts['before'] != counts['after']:
            raise RuntimeError('Rust exception ownership survived injected bailout: ' + str(counts))
