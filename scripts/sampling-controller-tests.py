#!/usr/bin/env python3
"""Run controller fault contracts in genuine initialized PHP subprocesses.

The debug-only C translation unit delegates normal libc calls. A failed join is
injected once without consuming the real handle, so the controller must retry
and complete the real join before PHP can continue.
"""
import os
import json
from pathlib import Path
import shutil
import subprocess
import sys
from process_groups import ProcessGroups
from sanitizer_diagnostics import reject_sanitizer_diagnostics

root = Path(__file__).resolve().parent.parent
os.chdir(root)
php = shutil.which(os.environ.get('PHP', 'php')) or os.environ.get('PHP', 'php')
environment = os.environ.copy()
configured = environment.get('PROFILER_SAMPLING_EXTENSION')
if configured:
    extension = Path(configured).resolve()
else:
    environment['PROFILER_SAMPLING_UNIT'] = '1'
    target = Path(environment.get('CARGO_TARGET_DIR', root / 'target')) / 'sampling-unit'
    cargo = [environment.get('CARGO', 'cargo')]
    if environment.get('PROFILER_COVERAGE_TOOLCHAIN'):
        cargo += ['+' + environment['PROFILER_COVERAGE_TOOLCHAIN']]
    subprocess.run(cargo + ['build', '-p', 'php-profiler', '--target-dir', str(target)],
                   env=environment, check=True)
    suffix = 'dylib' if sys.platform == 'darwin' else 'so'
    extension = (target / 'debug' / ('libphp_profiler.' + suffix)).resolve()
if environment.get('PROFILER_ASAN_RUNTIME'):
    environment['DYLD_INSERT_LIBRARIES' if sys.platform == 'darwin' else 'LD_PRELOAD'] = environment['PROFILER_ASAN_RUNTIME']
with ProcessGroups() as processes:
    processes.run([php, '-n', 'tests/native/stopped-sampling-assertions.php'],
                  env=environment, check=True, timeout=30)
    base = [php, '-n', '-d', 'extension=' + str(extension)]
    platform_status = json.loads(processes.run(base + ['-r',
        "echo json_encode(profiler_test_sampling_status(), JSON_THROW_ON_ERROR);"],
        env=environment, text=True, capture_output=True, check=True, timeout=30).stdout)
    if (type(platform_status['supported']) is not bool or
            platform_status['supported'] is not platform_status['expected_supported']):
        raise RuntimeError('sampling capability differs from independent compiled-platform contract')
    supported = '1' if platform_status['expected_supported'] else '0'
    # Genuine initialized-PHP defensive provider and observation contracts.
    # Every case delegates normal resources and uses its own fresh PHP process.
    if supported == '1':
        for mode in ('conversion', 'snapshot-error', 'deadline-exact', 'ring-wrap',
                     'status-mutex', 'worker-mutex', 'destroy-retirement',
                     'unready-drain', 'drain-mutex', 'mutex-error', 'clock', 'range',
                     'wake-clock', 'wake-range'):
            probe_environment = environment.copy()
            probe_environment.pop('PROFILER_SAMPLING_FAULT', None)
            if mode in ('unready-drain', 'mutex-error'):
                probe_environment['PROFILER_SAMPLING_FAULT'] = 'mutex'
            processes.run(base + ['tests/native/sampling-engine-boundaries.php', mode],
                          env=probe_environment, check=True, timeout=30)
        for mode in ('normal', 'join', 'signal', 'create', 'attr', 'queued-reset',
                     'request-shutdown', 'module-shutdown', 'free-first',
                     'fork-registry', 'stack-equal', 'page-zero', 'ctor-mutex'):
            probe_environment = environment.copy()
            probe_environment.pop('PROFILER_SAMPLING_FAULT', None)
            if mode == 'ctor-mutex':
                probe_environment['PROFILER_SAMPLING_FAULT'] = 'mutex'
            processes.run(base + ['tests/native/sampling-lifecycle-contract.php', mode],
                          env=probe_environment, check=True, timeout=30)
        for mode in ('equal', 'equal-wake', 'service-clock', 'service-range',
                     'service-regression', 'identity', 'depth', 'backlog', 'registration'):
            probe_environment = environment.copy()
            probe_environment.pop('PROFILER_SAMPLING_FAULT', None)
            processes.run(base + ['tests/native/sampling-frame-contract.php', mode],
                          env=probe_environment, check=True, timeout=30)
    modes = ['normal']
    if supported == '1':
        modes += ['mutex', 'condattr', 'setclock', 'condition', 'attr', 'page', 'stack',
                  'guard', 'mask', 'create', 'join', 'clock', 'range', 'wait',
                  'snapshot-clock', 'snapshot-range', 'nsec-negative', 'nsec-upper',
                  'seconds-overflow', 'deadline-overflow', 'capacity', 'counter', 'backlog', 'fork', 'request-restart', 'prior-clock-join', 'dropped-counter', 'spurious', 'wake-clock', 'wake-range', 'wake-regression',
                  'wake-equal', 'register-again', 'lock-contention', 'lifecycle', 'real-spurious', 'fork-clock', 'fork-range']
    for mode in modes:
        case_environment = environment.copy()
        if mode in ('mutex', 'condattr', 'setclock', 'condition'):
            case_environment['PROFILER_SAMPLING_FAULT'] = mode
        else:
            case_environment.pop('PROFILER_SAMPLING_FAULT', None)
        processes.run(base + ['tests/native/sampling-controller.php', mode],
                       env=case_environment, check=True, timeout=30)
    if supported == '1':
        failures = ['atfork'] + (['tsrm'] if platform_status['expected_zts'] else [])
        for failure in failures:
            failure_environment = environment.copy()
            failure_environment['PROFILER_SAMPLING_FAULT'] = failure
            failed = processes.run(base + ['-r', 'echo "AFTER_SAMPLER_STARTUP";'],
                                    env=failure_environment, capture_output=True, text=True, timeout=30)
            diagnostic = failed.stdout + "\n" + failed.stderr
            reject_sanitizer_diagnostics(diagnostic, failure + " sampler registration")
            if (failed.returncode == 0 or 'AFTER_SAMPLER_STARTUP' in diagnostic
                    or 'Unable to start php_profiler module' not in diagnostic):
                raise RuntimeError('native registration failure did not fail startup: ' + diagnostic)
            print(failure + ' registration failure PASS', flush=True)

    processes.run(base + ['tests/native/sampling-stack.php'], env=environment, check=True, timeout=30)
    if supported == '1':
        for mode in ('create', 'snapshot-clock', 'snapshot-range', 'clock', 'wait', 'join', 'service-clock', 'service-range', 'service-regression'):
            processes.run(base + ['tests/native/sampling-recovery.php', mode],
                           env=environment, check=True, timeout=30)
    if supported == '1':
        signal_base = [php, '-n']
        for module in ('pcntl', 'posix'):
            if processes.run([php, '-n', '-r', "exit(extension_loaded('" + module + "') ? 0 : 1);"],
                              env=environment, check=False, timeout=30).returncode:
                signal_base += ['-d', 'extension=' + module]
        # Load the genuine earlier interrupt owner before the profiler module.
        signal_base += ['-d', 'extension=' + str(extension)]
        processes.run(signal_base + ['tests/native/sampling-inactive-interrupt.php'],
                       env=environment, check=True, timeout=30)
    processes.run(base + ['tests/native/sampling-nested.php'], env=environment, check=True, timeout=30)
    processes.run(base + ['tests/native/sampling-identity.php'], env=environment, check=True, timeout=30)
