#!/usr/bin/env python3
"""Exercise patched upstream exception semantics and actual Zend bailout ownership."""
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile

root=Path(__file__).resolve().parent.parent
os.chdir(root)
php=os.environ.get('PHP','php')
from exception_fixture_support import exception_fixture
fixture=exception_fixture()
command=[php,'-n','-d','extension='+fixture]
subprocess.run(command+['tests/native/vendored-exception.php'],check=True)
for case in (6,7):
    with tempfile.TemporaryDirectory(prefix='profiler-exception-ownership-') as directory:
        path=Path(directory)/'allocations.json'
        result=subprocess.run(command+['tests/native/vendored-exception-oom.php',str(path),str(case)],
                              capture_output=True,text=True,timeout=30)
        output=result.stdout+result.stderr
        if (result.returncode!=255 or 'BEFORE_EXCEPTION' not in output or
                'Allowed memory size' not in output or
                'AFTER_EXCEPTION' in output or 'CAUGHT_FATAL' in output or not path.exists()):
            raise RuntimeError(f'exception OOM case{case} changed fatal/shutdown semantics exit={result.returncode}:\n{output}')
        counts=json.loads(path.read_text())
        # The same fallback path primed cleanup bookkeeping before the baseline.
        # Every Rust-owned error/class/message/fallback allocation must be released.
        if counts['after']!=counts['before']:
            raise RuntimeError('Rust exception ownership leaked across Zend bailout: '+str(counts))
        print(f'vendored exception OOM case{case} Rust ownership reclaimed:',json.dumps(counts))
