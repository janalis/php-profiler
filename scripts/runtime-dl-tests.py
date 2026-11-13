#!/usr/bin/env python3
"""Runtime dl must fail before profiler class/observer registration."""
import os
from pathlib import Path
import subprocess

root=Path(__file__).resolve().parent.parent
os.chdir(root)
php=os.environ.get('PHP','php')
extension=Path(os.environ['PROFILER_EXTENSION']).resolve()
result=subprocess.run([php,'-n','-d','enable_dl=1','-d','extension_dir='+str(extension.parent),
                       'tests/native/runtime-dl.php',extension.name],capture_output=True,text=True,timeout=30)
output=result.stdout+result.stderr
if (result.returncode!=255 or 'runtime dl rejected before class registration' not in output or
        'Unable to start php_profiler module' not in output or 'AFTER_DL_UNEXPECTED' in output):
    raise RuntimeError('runtime module startup did not reject safely: '+output)
# The same artifact still loads normally at process startup in a fresh process.
code='use PhpProfiler\\Profiler; $s=Profiler::start(); usleep(1); $s->stop(); if(($s->getReport()["functions"]["usleep"]["calls"]??0)!==1)throw new RuntimeException("permanent startup failed"); echo "permanent startup after rejected dl passed\\n";'
subprocess.run([php,'-n','-d','extension='+str(extension),'-r',code],check=True,timeout=30)
print('runtime dl rejected with native fatal 255 before registration; shutdown PHP remains valid')
