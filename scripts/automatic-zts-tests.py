"""Prove automatic capture isolation in real concurrent ZTS embed requests."""
import json
import os
from pathlib import Path
import shlex
import subprocess
import sys
import tempfile

config = os.environ.get('PHP_CONFIG', 'php-config')
prefix = Path(subprocess.check_output([config, '--prefix'], text=True).strip())
suffix = 'dylib' if sys.platform == 'darwin' else 'so'
library = Path(os.environ.get('PROFILER_EMBED_LIBRARY', prefix / 'lib' / ('libphp.' + suffix)))
includes = shlex.split(subprocess.check_output([config, '--includes'], text=True))
with tempfile.TemporaryDirectory(prefix='profiler-automatic-zts-') as temporary:
    directory = Path(temporary)
    output = directory / 'reports'
    output.mkdir()
    receipt = directory / 'receipts'
    receipt.mkdir()
    (directory / 'closure-worker-functions.php').write_text('<?php // embed harness warmup companion')
    fixture = directory / 'request.php'
    fixture.write_text('<?php function automatic_thread_leaf() { usleep(10000); } automatic_thread_leaf();')
    executable = directory / 'threads'
    compiler = shlex.split(os.environ.get('CC', 'cc'))
    subprocess.run(compiler + ['-std=c11'] + includes + ['tests/native/zts_threads.c', str(library),
                   '-lpthread', '-Wl,-rpath,' + str(library.parent), '-o', str(executable)], check=True)
    ini = directory / 'php.ini'
    ini.write_text(f'extension="{Path(os.environ["PROFILER_EXTENSION"]).resolve()}"\n'
                   f'php_profiler.mode=always\nphp_profiler.output_dir="{output}"\n'
                   'display_errors=stderr\nlog_errors=1\nmax_execution_time=0\n')
    environment = dict(os.environ, PHP_INI_SCAN_DIR='')
    subprocess.run([str(executable), str(ini), str(output), str(fixture), str(receipt)],
                   env=environment, check=True, timeout=120)
    paths = list(output.glob('*.json'))
    assert len(paths) == 82, len(paths)  # 80 workers plus embed startup/shutdown requests
    captures = []
    for path in paths:
        report = json.loads(path.read_text())
        assert report['termination_reason'] == 'request_complete'
        assert report['status'] == 'complete'
        assert report['runtime_metadata']['zts'] == 'true'
        leaf = report['functions'].get('automatic_thread_leaf')
        if leaf:
            assert leaf['calls'] == 1
            captures.append(report)
    assert len(captures) == 80
print('automatic ZTS: 80 isolated worker captures across four concurrent pthreads plus two main requests')
