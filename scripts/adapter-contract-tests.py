#!/usr/bin/env python3
"""Exercise private Rust adapter contracts inside real initialized Zend requests."""
import os
from pathlib import Path
import shutil
import subprocess
import sys

root = Path(__file__).resolve().parent.parent
os.chdir(root)
php = shutil.which(os.environ.get('PHP', 'php')) or os.environ.get('PHP', 'php')
configured = os.environ.get('PROFILER_CONTRACT_EXTENSION')
if configured:
    extension = Path(configured).resolve()
else:
    environment = os.environ.copy()
    environment['PROFILER_ADAPTER_CONTRACT_TEST'] = '1'
    environment['PROFILER_SAMPLING_UNIT'] = '1'
    target = Path(os.environ.get('CARGO_TARGET_DIR', root / 'target')) / 'adapter-contract'
    cargo = [os.environ.get('CARGO', 'cargo')]
    if os.environ.get('PROFILER_COVERAGE_TOOLCHAIN'):
        cargo += ['+' + os.environ['PROFILER_COVERAGE_TOOLCHAIN']]
    subprocess.run(cargo + ['build', '-p', 'php-profiler', '--target-dir', str(target)],
                   env=environment, check=True)
    suffix = 'dylib' if sys.platform == 'darwin' else 'so'
    extension = (target / 'debug' / ('libphp_profiler.' + suffix)).resolve()
environment = os.environ.copy()
if environment.get('PROFILER_ASAN_RUNTIME'):
    environment['DYLD_INSERT_LIBRARIES' if sys.platform == 'darwin' else 'LD_PRELOAD'] = environment['PROFILER_ASAN_RUNTIME']
if environment.get('PROFILER_C_UNIT_BOUNDARIES') == '1':
    subprocess.run([php, '-n', '-d', 'extension=' + str(extension), 'tests/native/fiber-observations.php'],
                   env=environment, check=True, timeout=30)
    subprocess.run([php, '-n', '-d', 'extension=' + str(extension), 'tests/native/fiber-tracking.php'],
                   env=environment, check=True, timeout=30)
for mode in ('disable-shared-boundary', 'native-identity-failure', 'reentrant-request-start', 'identity-max', 'nul-paths', 'reentrant-callbacks', 'unterminated-resolver', 'metadata-omission', 'capture-initialization-quota', 'uninitialized-storage', 'native-error-causes', 'serialization-limit'):
    subprocess.run([php, '-n', '-d', 'extension=' + str(extension), '-r',
                    'if (!profiler_adapter_contract_probe($argv[1])) { throw new RuntimeException("contract probe failed"); }',
                    mode], env=environment, check=True, timeout=30)
    print(mode + ': native private adapter contract passed', flush=True)

base = [php, '-n']
if subprocess.run(base + ['-r', "exit(extension_loaded('Zend OPcache') ? 0 : 1);"], env=environment).returncode:
    base += ['-d', 'zend_extension=opcache']
subprocess.run(base + ['-d', 'extension=' + str(extension), 'tests/native/adapter-sealed.php'],
               env=environment, check=True, timeout=30)

subprocess.run(base + ['-d', 'extension=' + str(extension), '-r',
    "function fresh_request_leaf(){return 41;} $s=PhpProfiler\\Profiler::start(); if(fresh_request_leaf()!==41)throw new RuntimeException('semantics'); $s->stop(); $r=$s->getReport(); if($r['status']!=='complete'||$r['functions']['fresh_request_leaf']['calls']!==1)throw new RuntimeException('fresh request identity recovery failed'); echo 'fresh request identity recovery passed',PHP_EOL;"],
    env=environment, check=True, timeout=30)

# Synthetic byte descriptors/counter failures inside real Zend startup. These
# are private unit contracts, never runtime performance samples or fake frames.
for mode in ('freeze-dependencies', 'start-boundary', 'renderer', 'mapper-admission', 'tracker-faults', 'callback-guard',
             'observer-defense', 'retry-metadata', 'freeze-boundaries', 'admission-rollback'):
    subprocess.run([php, '-n', '-d', 'extension=' + str(extension), '-r',
                    'if (!profiler_sampling_contract_probe($argv[1])) { throw new RuntimeException("sampling contract probe failed"); }',
                    mode], env=environment, check=True, timeout=60)
    print(mode + ': private sampling provider-boundary contract passed', flush=True)
subprocess.run([php, '-n', '-d', 'extension=' + str(extension), '-r',
    'try { profiler_sampling_contract_probe("unsupported-config"); throw new RuntimeException("unsupported dependency was accepted"); } '
    'catch (PhpProfiler\\ConfigurationException $expected) { '
    'if (!str_contains($expected->getMessage(), "Linux glibc 2.32")) { throw new RuntimeException("unexpected configuration failure", 0, $expected); } '
    'echo "unsupported sampler dependency preserves typed configuration failure", PHP_EOL; }'],
    env=environment, check=True, timeout=30)

subprocess.run([php, "-n", "-d", "extension=" + str(extension), "tests/native/sampling-map-lifetime.php"],
               env=environment, check=True, timeout=30)

subprocess.run([php, '-n', '-d', 'extension=' + str(extension), '-r',
                'if (!profiler_adapter_contract_probe("automatic")) throw new RuntimeException("automatic lifecycle contract failed");'],
               env=environment, check=True, timeout=30)
