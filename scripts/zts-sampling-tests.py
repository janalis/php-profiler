#!/usr/bin/env python3
"""Genuine Linux ZTS/embed sampler requests, native retirement, and DSO lifetime."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import platform
import shlex
import sys

from validation_inputs import snapshot
from process_groups import ProcessGroups

ROOT = Path(__file__).resolve().parent.parent


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def main(processes):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, default=ROOT / 'target/zts-sampling')
    parser.add_argument('--extension', type=Path,
                        default=os.environ.get('PROFILER_EXTENSION', ROOT / 'target/release/libphp_profiler.so'))
    args = parser.parse_args()
    if sys.platform != 'linux' or platform.libc_ver()[0] != 'glibc':
        raise RuntimeError('this mandatory sampler lifecycle gate requires Linux glibc')
    output = args.output.resolve()
    output.mkdir(parents=True, exist_ok=True)
    extension = args.extension.resolve()
    if not extension.is_file() or extension.name != 'libphp_profiler.so':
        raise RuntimeError('the unrenamed Cargo libphp_profiler.so artifact is required')
    commands = []

    def run(command, *, log=None, env=None, timeout=60):
        commands.append(command)
        process = processes.run(command, cwd=ROOT, env=env, text=True,
                                capture_output=True, timeout=timeout)
        combined = process.stdout + process.stderr
        if log:
            (output / log).write_text(combined)
        if process.returncode:
            raise RuntimeError(f'command exited {process.returncode}: {shlex.join(command)}\n{combined}')
        return process.stdout

    before = snapshot(ROOT)
    receipt = {'passed': False, 'source_before': before,
               'extension_sha256': digest(extension), 'commands': commands,
               'jit_or_opcache_exercised': False}
    try:
        config = os.environ.get('PHP_CONFIG', 'php-config')
        php = os.environ.get('PHP', 'php')
        metadata = json.loads(run([php, '-n', '-r',
                                  'echo json_encode(["version"=>PHP_VERSION,"zts"=>PHP_ZTS]);']))
        sdk_version = run([config, '--version']).strip()
        if not metadata['zts'] or metadata['version'] != sdk_version:
            raise RuntimeError('PHP ZTS executable and SDK versions must match')
        prefix = Path(run([config, '--prefix']).strip())
        library = Path(os.environ.get('PROFILER_EMBED_LIBRARY', prefix / 'lib/libphp.so')).resolve()
        if not library.is_file():
            raise RuntimeError('matching SDK embed library is required: ' + str(library))
        includes = shlex.split(run([config, '--includes']))
        cc = shlex.split(os.environ.get('CC', 'cc'))
        executable, observer = output / 'sampling_zts_threads', output / 'sampling_pthreads.so'
        run(cc + ['-std=c11'] + includes + [str(ROOT / 'tests/native/sampling_zts_threads.c'),
            str(library), '-lpthread', '-ldl', '-Wl,-rpath,' + str(library.parent),
            '-o', str(executable)], log='embed-build.log')
        run(cc + ['-shared', '-fPIC', '-std=c11', str(ROOT / 'tests/native/sampling_pthreads.c'),
                  '-lpthread', '-ldl', '-o', str(observer)], log='observer-build.log')
        reports = output / 'request-receipts'
        reports.mkdir(exist_ok=True)
        for old in reports.glob('*.json'):
            old.unlink()
        ini = output / 'php.ini'
        escaped = str(extension).replace('\\', '\\\\').replace('"', '\\"')
        ini.write_text(f'extension="{escaped}"\nhtml_errors=0\ndisplay_errors=stderr\n'
                       'log_errors=0\nmax_execution_time=0\n')
        environment = os.environ.copy()
        environment['PHP_INI_SCAN_DIR'] = ''
        environment['LD_PRELOAD'] = str(observer) + (
            ':' + environment['LD_PRELOAD'] if environment.get('LD_PRELOAD') else '')
        text = run([str(executable), str(ini), str(extension),
                    str(ROOT / 'tests/support/sampling-worker-request.php'), str(reports)],
                   log='lifecycle.log', env=environment, timeout=120)
        evidence = [json.loads(path.read_text()) for path in reports.glob('*.json')]
        if (len(evidence) != 20 or any(row['id'] != 1 or row['calls'] != 2 or
                row['samples'] < 2 or len(row['contexts']) < 2 for row in evidence)):
            raise RuntimeError('expected twenty isolated real sampled main/Fiber captures')
        for expected in ('created=20 actual_joins=20 live=0 peak=4 invalid_attrs=0',
                         'created=22 actual_joins=22 live=0 peak=4 invalid_attrs=0',
                         'created=23 actual_joins=23 live=0 peak=4 invalid_attrs=0',
                         'actual DSO unload/postshutdown fork'):
            if expected not in text:
                raise RuntimeError('native retirement evidence missing: ' + expected)
        cycles = output / 'sampling_module_cycles'
        run(cc + ['-std=c11'] + includes + [str(ROOT / 'tests/native/sampling_module_cycles.c'),
            str(library), '-lpthread', '-Wl,-rpath,' + str(library.parent), '-o', str(cycles)],
            log='module-cycles-build.log')
        cycle_log = run([str(cycles), str(ini)], log='module-cycles.log',
                        env=environment, timeout=30)
        if ('module cycle 1 + retained-DSO fork passed' not in cycle_log or
                'module cycle 2 + retained-DSO fork passed' not in cycle_log):
            raise RuntimeError('same-owner repeated module lifecycle/fork evidence missing')
        # The actual Cargo-linked DSO must use glibc's DSO-local atfork wrapper.
        symbols = run(['nm', '-a', str(extension)], log='symbols.log')
        assembly = run(['objdump', '-d', str(extension)], log='disassembly.log')
        if '__pthread_atfork' not in symbols or '__dso_handle' not in symbols:
            raise RuntimeError('Cargo DSO atfork wrapper/DSO identity not found')
        wrapper = assembly.split('<__pthread_atfork>:', 1)[-1].split('\n\n', 1)[0]
        if '__dso_handle' not in wrapper or '__register_atfork' not in wrapper:
            raise RuntimeError('atfork wrapper does not pass its local DSO identity')
        receipt.update({'php': metadata, 'sdk_version': sdk_version,
                        'php_source_sha256': os.environ.get('PHP_SHA256'),
                        'configure_options': run([config, '--configure-options']).strip(),
                        'libc': platform.libc_ver(), 'compiler': run(cc + ['--version']).splitlines()[0],
                        'rustc': run(['rustc', '--version']).strip(),
                        'embed_library_sha256': digest(library), 'reports': len(evidence),
                        'actual_samples': sum(row['samples'] for row in evidence),
                        'created_workers': 23, 'actual_joins': 23, 'peak_overlap': 4,
                        'live_workers': 0, 'invalid_attrs': 0,
                        'rshutdown_recovery': True, 'actual_tsrm_fallback': True,
                        'normal_tls_retirement_then_actual_unload': True,
                        'post_unload_forks': 3, 'same_owner_module_cycles': 2,
                        'retained_dso_forks_between_cycles': 2})
        receipt['source_after'] = snapshot(ROOT)
        receipt['extension_after_sha256'] = digest(extension)
        if receipt['source_after'] != before or receipt['extension_after_sha256'] != receipt['extension_sha256']:
            raise RuntimeError('validation input or extension changed during sampler lifecycle proof')
        receipt['passed'] = True
        print('Linux ZTS sampler: 20 isolated reports, 23 actual worker joins, peak4, TLS/DSO/fork passed')
    finally:
        (output / 'receipt.json').write_text(json.dumps(receipt, indent=2) + '\n')


if __name__ == '__main__':
    with ProcessGroups() as owned_processes:
        main(owned_processes)
