#!/usr/bin/env python3
"""Ensure Zend OOM remains fatal across native report conversion boundaries."""
import os
import platform
import subprocess

extension = os.environ['PROFILER_EXTENSION']
php = os.environ.get('PHP', 'php')
child_env = dict(os.environ)
if child_env.get('PROFILER_ASAN_RUNTIME'):
    key = 'DYLD_INSERT_LIBRARIES' if platform.system() == 'Darwin' else 'LD_PRELOAD'
    child_env[key] = child_env['PROFILER_ASAN_RUNTIME']
for mode in ('json', 'array'):
    result = subprocess.run([php, '-n', '-d', 'extension=' + extension,
                             '-d', 'memory_limit=128M', 'tests/native/report-oom.php', mode],
                            capture_output=True, text=True, timeout=30, env=child_env)
    output = result.stdout + result.stderr
    if (result.returncode != 255 or 'BEFORE_REPORT' not in output or
            'Allowed memory size' not in output or
            'AFTER_REPORT' in output or 'CAUGHT_FATAL' in output):
        raise RuntimeError(f'{mode}: report OOM changed fatal behavior: exit={result.returncode}\n{output}')
    print(f'{mode}: report conversion OOM remains fatal (255), no PHP catch/continuation')
