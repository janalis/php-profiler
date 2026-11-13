#!/usr/bin/env python3
"""Verify PHP filesystem policy against real writes, including symlink/tightening."""
import os
from pathlib import Path
import platform
import subprocess
import tempfile

extension = os.environ['PROFILER_EXTENSION']
code = (Path(__file__).resolve().parent.parent / 'tests/native/basedir.php').read_text().removeprefix('<?php')
child_env = dict(os.environ)
if child_env.get('PROFILER_ASAN_RUNTIME'):
    key = 'DYLD_INSERT_LIBRARIES' if platform.system() == 'Darwin' else 'LD_PRELOAD'
    child_env[key] = child_env['PROFILER_ASAN_RUNTIME']
with tempfile.TemporaryDirectory(prefix='php-profiler-basedir-') as directory:
    root = Path(directory)
    allowed = root / 'allowed'
    outside = root / 'outside'
    allowed.mkdir()
    outside.mkdir()
    (allowed / 'link').symlink_to(outside, target_is_directory=True)
    for mode in ['allowed', 'outside', 'symlink', 'tightening', 'relative', 'parent-denied']:
        target = {'allowed': allowed / 'allowed.json', 'outside': outside / 'outside.json',
                  'symlink': allowed / 'link/symlink.json', 'tightening': outside / 'tightening.json', 'relative': allowed / 'relative.json', 'parent-denied': allowed / 'parent-denied.json'}[mode]
        if mode == 'parent-denied':
            target.write_text('existing-target-payload')
        initial = target if mode == 'parent-denied' else (root if mode == 'tightening' else allowed)
        result = subprocess.run(['php', '-n', '-d', 'extension=' + extension,
                                 '-d', 'open_basedir=' + str(initial), '-r', code,
                                 target.name if mode == 'relative' else str(target), str(allowed), mode],
                                capture_output=True, text=True, env=child_env, cwd=outside)
        if result.returncode or result.stderr or 'Warning:' in result.stdout:
            raise RuntimeError(f'{mode}: policy test failed: {result.stdout}{result.stderr}')
        if mode == 'parent-denied' and target.read_text() != 'existing-target-payload':
            raise RuntimeError('exact-file policy failed to preserve existing target')
        if target.exists() != (mode in ['allowed', 'relative', 'parent-denied']):
            raise RuntimeError(f'{mode}: OS filesystem disagrees with PHP policy')
        if mode == 'relative' and (outside / 'relative.json').exists():
            raise RuntimeError('relative report bypassed PHP virtual CWD')
        print(result.stdout.strip())
