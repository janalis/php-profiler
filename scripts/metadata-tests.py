#!/usr/bin/env python3
"""Bound raw INI metadata in a separate PHP process with OPcache present."""
import os
import subprocess
import sys
base = [os.environ.get('PHP', 'php'), '-n']
env = dict(os.environ)
if env.get('PROFILER_ASAN_RUNTIME'):
    env['DYLD_INSERT_LIBRARIES' if sys.platform == 'darwin' else 'LD_PRELOAD'] = env['PROFILER_ASAN_RUNTIME']
probe = subprocess.run(base + ['-r', "exit(extension_loaded('Zend OPcache') ? 0 : 1);"], env=env, check=False)
if probe.returncode:
    base += ['-d', 'zend_extension=opcache']
subprocess.run(base + ['-d', 'extension=' + env['PROFILER_EXTENSION'],
                      'tests/native/metadata-bounds.php'], env=env, check=True)
