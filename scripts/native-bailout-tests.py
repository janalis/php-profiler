#!/usr/bin/env python3
"""Real C boundaries, dependency faults, no production fault switches."""
import os
import pathlib
import subprocess
import sys
import tempfile
import json
from sanitizer_diagnostics import reject_sanitizer_diagnostics
root = pathlib.Path(__file__).resolve().parent.parent
os.chdir(root)
if sys.platform != 'darwin' and os.environ.get('PROFILER_LINKED_BAILOUT_FAULT') != '1':
    sys.exit('Linux requires the explicitly test-linked --wrap fault module')
target = pathlib.Path(os.environ.get('CARGO_TARGET_DIR', root / 'target')) / 'fault-tests'
target.mkdir(parents=True, exist_ok=True)
flags = subprocess.check_output([os.environ.get('PHP_CONFIG', 'php-config'), '--includes'], text=True).split()
helper = target / 'bailout-fault.dylib'
if sys.platform == 'darwin':
    subprocess.run([os.environ.get('CC', 'cc'), '-dynamiclib', '-Wl,-undefined,dynamic_lookup', *flags,
                    'tests/native/bailout-fault.c', '-o', str(helper)], check=True)
outer_reports = tempfile.TemporaryDirectory(prefix='profiler-native-boundary-reports-')
if os.environ.get('PROFILER_C_UNIT_BOUNDARIES') == '1':
    for mode in ('decode', 'string', 'allowed', 'resolve', 'exact', 'ini'):
        env = dict(os.environ, PROFILER_TEST_BAILOUT=mode)
        if sys.platform == 'darwin':
            env['DYLD_INSERT_LIBRARIES'] = str(helper.resolve())
        result = subprocess.run([os.environ.get('PHP', 'php'), '-n', '-d',
                                 'extension=' + env['PROFILER_EXTENSION'], '-r',
                                 'profiler_test_guard_contract();'], env=env,
                                capture_output=True, text=True, timeout=30)
        reject_sanitizer_diagnostics(result.stdout + "\n" + result.stderr, "native bailout boundary")
        if result.returncode != 0 or 'C_GUARD_CONTRACT_OK' not in result.stdout:
            sys.exit(f'{mode}: C return contract failed: {result.returncode}: {result.stdout}{result.stderr}')
        print(f'{mode}: cold native guard return/publication/rethrow contract passed')
startup_modes = ['handle-api']
if os.environ.get('PROFILER_C_UNIT_BOUNDARIES') == '1':
    startup_modes += ['handles-negative', 'handles-capacity']
for mode in startup_modes:
    env = dict(os.environ, PROFILER_TEST_BAILOUT=mode)
    if sys.platform == 'darwin':
        env['DYLD_INSERT_LIBRARIES'] = str(helper.resolve())
    result = subprocess.run([os.environ.get('PHP', 'php'), '-n', '-d',
                             'extension=' + env['PROFILER_EXTENSION'], '-r', 'echo "AFTER_MODULE_STARTUP";'],
                            env=env, capture_output=True, text=True, timeout=30)
    reject_sanitizer_diagnostics(result.stdout + "\n" + result.stderr, "native bailout boundary")
    output = result.stdout + result.stderr
    marker = 'INJECTED_HANDLE_API_FAILURE' if mode == 'handle-api' else 'INJECTED_HANDLE_COUNT_FAILURE'
    if (result.returncode not in (254, 255) or marker not in output or
            'Unable to start' not in output or 'AFTER_MODULE_STARTUP' in output):
        sys.exit(f'{mode}: module failure was not safely rejected: {result.returncode}: {output}')
    print(f'{mode}: startup rejects dependency failure before PHP execution (exit {result.returncode})')
if os.environ.get('PROFILER_C_UNIT_BOUNDARIES') == '1':
    subprocess.run([os.environ.get('PHP', 'php'), '-n', '-d',
                    'extension=' + os.environ['PROFILER_EXTENSION'], 'tests/native/fiber-observations.php'],
                   check=True, timeout=30)
    subprocess.run([os.environ.get('PHP', 'php'), '-n', '-d',
                    'extension=' + os.environ['PROFILER_EXTENSION'], 'tests/native/fiber-tracking.php'],
                   check=True, timeout=30)
    subprocess.run([os.environ.get('PHP', 'php'), '-n', '-d',
                    'extension=' + os.environ['PROFILER_EXTENSION'], 'tests/native/closure-uid-boundaries.php'],
                   check=True, timeout=30)
    opcache_command = [os.environ.get('PHP', 'php'), '-n']
    if subprocess.run(opcache_command + ['-r', "exit(extension_loaded('Zend OPcache') ? 0 : 1);"]).returncode:
        opcache_command += ['-d', 'zend_extension=opcache']
    subprocess.run(opcache_command + ['-d', 'opcache.enable_cli=1', '-d', 'opcache.file_update_protection=0',
                    '-d', 'extension=' + os.environ['PROFILER_EXTENSION'], 'tests/native/closure-map-pointers.php'],
                   check=True, timeout=30)

for mode in ('decode', 'string', 'allowed', 'resolve'):
    outer_report = pathlib.Path(outer_reports.name) / (mode + '.json')
    env = dict(os.environ, PROFILER_TEST_BAILOUT=mode)
    if sys.platform == 'darwin':
        env['DYLD_INSERT_LIBRARIES'] = str(helper.resolve())
    result = subprocess.run([os.environ.get('PHP', 'php'), '-n', '-d',
                             'extension=' + env['PROFILER_EXTENSION'], 'tests/native/bailout-fault.php', mode, str(outer_report)],
                            env=env, capture_output=True, text=True, timeout=30)
    reject_sanitizer_diagnostics(result.stdout + "\n" + result.stderr, "native bailout boundary")
    output = result.stdout + result.stderr
    for line in result.stdout.splitlines():
        if line.startswith('NATIVE_TARGET='):
            path = pathlib.Path(line.removeprefix('NATIVE_TARGET='))
            if path.exists():
                path.unlink()
                sys.exit(f'{mode}: report published after fatal path-check failure')
    if result.returncode != 255 or 'INJECTED_NATIVE_BAILOUT' not in output or 'AFTER_NATIVE_FAULT' in output or 'CAUGHT_NATIVE_FAULT' in output:
        sys.exit(f'{mode}: lost fatal boundary status {result.returncode}:\n{output}')
    if mode in ('decode', 'string'):
        report = json.loads(outer_report.read_text())
        if report['functions']['native_fault_consumer']['exceptions'] != 0:
            sys.exit(f'{mode}: native fatal failure manufactured a PHP exception')
    print(f'{mode}: real native guard defers fatal until Rust returns')

for mode in (() if sys.platform == 'darwin' else ('small', 'failure', 'exact', 'unterminated')):
    env = dict(os.environ, PROFILER_TEST_BAILOUT=mode)
    if sys.platform == 'darwin':
        env['DYLD_INSERT_LIBRARIES'] = str(helper.resolve())
    result = subprocess.run([os.environ.get('PHP', 'php'), '-n', '-d',
                             'extension=' + env['PROFILER_EXTENSION'], 'tests/native/bailout-fault.php', mode],
                            env=env, capture_output=True, text=True, timeout=30)
    reject_sanitizer_diagnostics(result.stdout + "\n" + result.stderr, "native bailout boundary")
    if result.returncode != 0 or 'EXPECTED_BOUNDARY_DENIAL' not in result.stdout:
        sys.exit(f'{mode}: boundary failure not safely propagated: {result.stdout}{result.stderr}')
    print(f'{mode}: native boundary failure preserves report without publication')

env = dict(os.environ, PROFILER_TEST_BAILOUT='control')
if sys.platform == 'darwin':
    env['DYLD_INSERT_LIBRARIES'] = str(helper.resolve())
result = subprocess.run([os.environ.get('PHP', 'php'), '-n', '-d',
                         'extension=' + env['PROFILER_EXTENSION'], 'tests/native/bailout-fault.php', 'control'],
                        env=env, capture_output=True, text=True, timeout=30)
reject_sanitizer_diagnostics(result.stdout + "\n" + result.stderr, "native bailout boundary")
if result.returncode or 'CONTROL_CLOCKS_INDEPENDENT' not in result.stdout:
    sys.exit(f'control API observed unrelated clocks: {result.stdout}{result.stderr}')
print('control state queries do not contaminate clock measurements')
