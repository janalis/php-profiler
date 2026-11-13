#!/usr/bin/env python3
"""Test arbitrary host SAPI names without adding a production configuration hook."""
import os
from pathlib import Path
import shlex
import subprocess
import sys
import tempfile

root=Path(__file__).resolve().parent.parent
os.chdir(root)
config=os.environ.get('PHP_CONFIG','php-config')
prefix=Path(subprocess.check_output([config,'--prefix'],text=True).strip())
includes=shlex.split(subprocess.check_output([config,'--includes'],text=True))
suffix='dylib' if sys.platform=='darwin' else 'so'
library=Path(os.environ.get('PROFILER_EMBED_LIBRARY',prefix/'lib'/('libphp.'+suffix)))
if not library.is_file():raise RuntimeError('PHP embed library required: '+str(library))
extension=Path(os.environ['PROFILER_EXTENSION']).resolve()
with tempfile.TemporaryDirectory(prefix='profiler-engine-metadata-') as temporary:
    directory=Path(temporary)
    ini=directory/'php.ini'
    escaped=str(extension).replace('\\','\\\\').replace('"','\\"')
    ini.write_text('extension="'+escaped+'"\nhtml_errors=0\ndisplay_errors=stderr\nlog_errors=0\n')
    executable=directory/'engine_metadata'
    subprocess.run(shlex.split(os.environ.get('CC','cc'))+includes+
                   ['tests/native/engine-metadata.c',str(library),'-Wl,-rpath,'+str(library.parent),'-o',str(executable)],check=True)
    environment=os.environ.copy();environment['PHP_INI_SCAN_DIR']=''
    subprocess.run([str(executable),str(ini)],env=environment,check=True,timeout=30)
