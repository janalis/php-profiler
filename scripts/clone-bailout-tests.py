#!/usr/bin/env python3
"""Exact Rust ownership check when Zend bails out allocating a clone error."""
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
os.environ.pop('PROFILER_TEST_EXCEPTION_BAILOUT', None)
if sys.platform != 'darwin':
    # The executable defines zend_throw_error, so ELF preloading cannot reliably
    # interpose calls. Wrap only the auxiliary module's references at link time.
    os.environ['PROFILER_TEST_CLONE_BAILOUT'] = '1'
fixture = exception_fixture()
with tempfile.TemporaryDirectory(prefix='profiler-clone-ownership-') as temporary:
    directory = Path(temporary)
    environment = os.environ.copy()
    if sys.platform == 'darwin':
        includes = shlex.split(subprocess.check_output([config, '--includes'], text=True))
        interposer = directory / 'clone-bailout.dylib'
        subprocess.run(shlex.split(os.environ.get('CC', 'cc')) + includes +
                       ['-dynamiclib', '-undefined', 'dynamic_lookup',
                        'tests/native/clone-bailout.c', '-o', str(interposer)], check=True)
        environment['DYLD_INSERT_LIBRARIES'] = str(interposer)
    counts_path = directory / 'counts.json'
    result = subprocess.run([php, '-n', '-d', 'extension=' + fixture,
                             'tests/native/clone-bailout.php', str(counts_path)],
                            env=environment, capture_output=True, text=True, timeout=30)
    output = result.stdout + result.stderr
    if (result.returncode != 255 or 'BEFORE_CLONE' not in output or
            'CLONE_ALLOCATION_BAILOUT' not in output or 'AFTER_CLONE' in output or
            'CAUGHT_FATAL' in output or not counts_path.exists()):
        raise RuntimeError(f'clone fatal semantics failed exit={result.returncode}:\n{output}')
    counts = json.loads(counts_path.read_text())
    print('clone bailout Rust ownership:', json.dumps(counts))
    if counts['before'] != counts['after']:
        raise RuntimeError('owned Rust allocation survived clone bailout: ' + str(counts))
