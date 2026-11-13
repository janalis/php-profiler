#!/usr/bin/env python3
"""Ordinary sampled CPU workload under actual shared OPcache and tracing JIT.

CLI and genuine reused FPM workers are distinct receipts. The workload uses no
private controller exports, fake observations, or profiler-specific fault transports.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import subprocess
from fpm_support import FpmServer
from benchmark import validate_sampling

parser = argparse.ArgumentParser()
parser.add_argument('--extension', default=os.environ.get('PROFILER_EXTENSION'),
                    required=not bool(os.environ.get('PROFILER_EXTENSION')))
parser.add_argument('--fpm', action='store_true')
parser.add_argument('--expected-php')
parser.add_argument('--expected-zts', choices=['nts', 'ts'])
parser.add_argument('--out')
args = parser.parse_args()
root = Path(__file__).resolve().parents[1]
os.chdir(root)
php = os.environ.get('PHP', 'php')
extension = Path(args.extension).resolve()
version = subprocess.check_output([php, '-n', '-r', 'echo PHP_VERSION;'], text=True).strip()
headers = subprocess.check_output([os.environ.get('PHP_CONFIG', 'php-config'), '--version'], text=True).strip()
if version != headers:
    raise RuntimeError('PHP companion and SDK differ')
load_opcache = subprocess.run([php, '-n', '-r', "exit(function_exists('opcache_get_status') ? 0 : 1);"],
                             check=False).returncode != 0
result = {'scope': 'actual FPM worker reuse' if args.fpm else 'CLI companion',
          'extension_sha256': hashlib.sha256(extension.read_bytes()).hexdigest(),
          'php_config_version': headers,
          'inputs': {name: hashlib.sha256((root / name).read_bytes()).hexdigest() for name in
                     ('scripts/sampling-jit-tests.py', 'scripts/benchmark.py', 'tests/support/sampling-workload.php', 'tests/native/sampling-jit.php',
                      'tests/fpm/sampling-request.php')}, 'cases': []}

def validate(receipt, token):
    runtime = receipt['runtime']
    if runtime['php_version'] != version:
        raise RuntimeError('actual SAPI version differs from companion/SDK')
    if args.expected_php and '.'.join(runtime['php_version'].split('.')[:2]) != args.expected_php:
        raise RuntimeError('actual PHP minor differs from requested label')
    if args.expected_zts and runtime['zts'] != str(args.expected_zts == 'ts').lower():
        raise RuntimeError('actual PHP ZTS mode differs from requested label')
    if runtime['sapi'] != ('fpm-fcgi' if args.fpm else 'cli'):
        raise RuntimeError('actual SAPI differs from receipt scope')
    if (receipt['token'] != token or receipt['session_id'] != 1 or receipt['calls'] < 1
            or receipt['checksum'] != receipt['calls'] * (3500000 + token)
            or receipt['samples'] < 1 or not receipt['cached']):
        raise RuntimeError('sampling ownership, workload, or cached execution invalid')
    validate_sampling({'sampling_statuses': {receipt['sampling_status']: 1},
                       'sampling_samples': receipt['samples'],
                       'sampling_reports': [receipt['sampling_report']]},
                      'enabled', True, 1, 'calls')

for mode in ('opcache', 'jit'):
    settings = {'opcache.enable': 1, 'opcache.enable_cli': 1,
                'opcache.file_update_protection': 0, 'opcache.validate_timestamps': 0,
                'opcache.jit': 'tracing' if mode == 'jit' else 'off',
                'opcache.jit_buffer_size': '32M' if mode == 'jit' else '0',
                'opcache.jit_hot_func': 1, 'opcache.jit_hot_loop': 1}
    if load_opcache:
        settings['zend_extension'] = 'opcache'
    receipts = []
    if args.fpm:
        with FpmServer(str(extension), ini_settings=settings) as server:
            for token in range(12):
                receipts.append(server.request('tests/fpm/sampling-request.php',
                    {'PROFILE_TEST_TOKEN': str(token), 'PROFILE_TEST_JIT': str(int(mode == 'jit'))}))
                validate(receipts[-1], token)
        if len({item['pid'] for item in receipts}) != 1:
            raise RuntimeError('FPM failed to reuse one worker across sampled requests')
    else:
        command = [php, '-n']
        for key, value in settings.items():
            command += ['-d', f'{key}={value}']
        command += ['-d', 'extension=' + str(extension), 'tests/native/sampling-jit.php', mode]
        completed = subprocess.run(command, capture_output=True, text=True, check=False, timeout=120)
        if completed.returncode:
            raise RuntimeError('sampled CLI subprocess failed: ' + completed.stdout + completed.stderr)
        receipts.append(json.loads(completed.stdout))
        validate(receipts[0], 17)
    consumed = 0
    for item in receipts:
        before, after = item['jit_before'], item['jit_after']
        if mode == 'jit':
            if not isinstance(before, dict) or not isinstance(after, dict) or not after.get('on') or after.get('kind') != 5:
                raise RuntimeError('actual tracing JIT is not active')
            old, new = before.get('buffer_free'), after.get('buffer_free')
            if type(old) is not int or type(new) is not int or new < 0 or old < new:
                raise RuntimeError('invalid or regressing JIT code-buffer counters')
            consumed += old - new
        elif after and after.get('on'):
            raise RuntimeError('OPcache-only workload unexpectedly enabled JIT')
    if mode == 'jit' and consumed == 0:
        raise RuntimeError('sampled workload did not prove actual tracing-JIT code generation')
    result['cases'].append({'mode': mode, 'requests': len(receipts),
                            'jit_generated_bytes_observed': consumed, 'receipts': receipts})
if hashlib.sha256(extension.read_bytes()).hexdigest() != result['extension_sha256']:
    raise RuntimeError('extension changed during ordinary sampling compatibility proof')
if any(hashlib.sha256((root / name).read_bytes()).hexdigest() != digest
       for name, digest in result['inputs'].items()):
    raise RuntimeError('sampling compatibility inputs changed during proof')
result['sampling_acceptance'] = 'exact complete call graph, positive actual samples, valid stopped counter snapshots; only explicitly measured unserved boundary wakes may truncate sampling, with all raw statuses/counters retained'
result['complete'] = True
if args.out:
    Path(args.out).write_text(json.dumps(result, indent=2) + '\n')
print(json.dumps(result, indent=2))
