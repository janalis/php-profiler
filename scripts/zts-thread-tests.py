#!/usr/bin/env python3
"""Real pthread/embed requests, closure lifetimes, and shared OPcache code proof."""
import json
import os
from pathlib import Path
import shlex
import subprocess
import sys
import tempfile

root = Path(__file__).resolve().parent.parent
os.chdir(root)
config = os.environ.get('PHP_CONFIG', 'php-config')
prefix = Path(subprocess.check_output([config, '--prefix'], text=True).strip())
includes = shlex.split(subprocess.check_output([config, '--includes'], text=True))
suffix = 'dylib' if sys.platform == 'darwin' else 'so'
library = Path(os.environ.get('PROFILER_EMBED_LIBRARY', prefix / 'lib' / ('libphp.' + suffix)))
if not library.is_file():
    raise RuntimeError('ZTS embed library is required: ' + str(library))
extension = Path(os.environ['PROFILER_EXTENSION']).resolve()
fixture = root / 'tests/support/closure-worker-request.php'

def quoted(path):
    return str(path).replace('\\', '\\\\').replace('"', '\\"')

with tempfile.TemporaryDirectory(prefix='profiler-zts-threads-') as temporary:
    directory = Path(temporary)
    executable = directory / 'zts_threads'
    compiler = shlex.split(os.environ.get('CC', 'cc'))
    subprocess.run(compiler + ['-std=c11'] + includes +
                   ['tests/native/zts_threads.c', str(library), '-lpthread',
                    '-Wl,-rpath,' + str(library.parent), '-o', str(executable)], check=True)
    for mode in ('disabled', 'opcache'):
        work = directory / mode
        work.mkdir()
        output, receipts = work / 'reports', work / 'receipts'
        output.mkdir(mode=0o700)
        receipts.mkdir(mode=0o700)
        ini = work / 'php.ini'
        settings = ('extension="' + quoted(extension) + '"\nhtml_errors=0\ndisplay_errors=stderr\n'
                    'log_errors=0\nmax_execution_time=0\nopcache.enable_cli=1\n'
                    'opcache.file_update_protection=0\nopcache.validate_timestamps=0\n'
                    'opcache.enable=' + ('1' if mode == 'opcache' else '0') + '\n')
        if os.environ.get('PROFILER_EMBED_OPCACHE_EXTENSION'):
            settings = 'zend_extension="' + quoted(os.environ['PROFILER_EMBED_OPCACHE_EXTENSION']) + '"\n' + settings
        ini.write_text(settings)
        environment = os.environ.copy()
        environment['PHP_INI_SCAN_DIR'] = ''
        subprocess.run([str(executable), str(ini), str(output), str(fixture), str(receipts)],
                       env=environment, check=True, timeout=120)
        paths = list(output.glob('*.json'))
        evidence = [json.loads(path.read_text()) for path in receipts.glob('*.json')]
        if len(paths) != 80 or len(evidence) != 80 or len({item['token'] for item in evidence}) != 80:
            raise RuntimeError('expected 80 unique reports and request receipts')
        edges = []
        for path in paths:
            report = json.loads(path.read_text())
            if (report['status'] != 'complete' or report['functions']['zts_profiled_leaf']['calls'] != 1 or
                    report['runtime_metadata']['zts'] != 'true' or report['runtime_metadata']['sapi'] != 'embed'):
                raise RuntimeError('thread/request contamination in ' + str(path))
            rows = [value['calls'] for key, value in report['functions'].items() if '{closure#' in key]
            if sorted(rows) != [2] * 20 + [24]:
                raise RuntimeError('shared factory or recycled eval lifetime identities merged')
            event = next(event for event in report['timeline'] if event['symbol'] == 'zts_profiled_leaf')
            edges.extend([(event['start_ns'], 1), (event['end_ns'], -1)])
        active = maximum = 0
        for _, delta in sorted(edges):
            active += delta
            maximum = max(maximum, active)
        if maximum != 4:
            raise RuntimeError(f'expected four actually overlapping captures, measured {maximum}')
        if any(item['storage'] is None for item in evidence):
            raise RuntimeError('test-only SAPI storage observer was not registered')
        for request in range(20):
            round_items = [item for item in evidence if item['token'] % 1000 == request]
            if len({item['storage']['runtime_cache'] for item in round_items}) != 4:
                raise RuntimeError('concurrent threads unexpectedly share writable runtime caches')
        cached = all(item['opcache_enabled'] and item['shared_file_cached'] for item in evidence)
        opcode_addresses = {item['storage']['opcodes'] for item in evidence}
        if mode == 'opcache' and cached:
            if len(opcode_addresses) != 1:
                raise RuntimeError('cached common source did not share compiled closure opcodes')
            scope = 'OPcache shared opcodes proved; runtime caches distinct across four concurrent threads'
        elif mode == 'opcache':
            if os.environ.get('PROFILER_REQUIRE_EMBED_OPCACHE') == '1':
                raise RuntimeError('this embed build did not enable shared OPcache; no shared-code proof claimed')
            scope = 'embed OPcache unavailable; closure/request isolation proved, shared opcodes not claimed'
        else:
            scope = 'OPcache disabled; independently compiled closure/request isolation proved'
        print(f'{mode}: 80 exact reports; 21 closure lifetimes/64 calls each; maximum overlap {maximum}; {scope}')
