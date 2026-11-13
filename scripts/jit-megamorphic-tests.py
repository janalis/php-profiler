#!/usr/bin/env python3
"""GH-22158: real megamorphic JIT calls, exact counts and observer coexistence."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import platform
import shlex
import subprocess
import tempfile

parser=argparse.ArgumentParser()
parser.add_argument('--php',default='php')
parser.add_argument('--php-config',default=os.environ.get('PHP_CONFIG','php-config'))
parser.add_argument('--extension',default=os.environ.get('PROFILER_EXTENSION'),required=not bool(os.environ.get('PROFILER_EXTENSION')))
parser.add_argument('--opcache-extension',default=os.environ.get('PROFILER_OPCACHE_EXTENSION'))
parser.add_argument('--iterations',type=int,default=100000)
parser.add_argument('--out')
args=parser.parse_args()
root=Path(__file__).resolve().parents[1]
fixture=root/'tests/native/jit-megamorphic.php'
helper=root/'tests/support/jit-coobserver.c'
extension=Path(args.extension).resolve()
if args.iterations<40 or args.iterations%5: parser.error('iterations must be >=40 and divisible by five')
includes=shlex.split(subprocess.check_output([args.php_config,'--includes'],text=True))
if args.opcache_extension is None:
    available=subprocess.run([args.php,'-n','-r',"exit(function_exists('opcache_get_status') ? 0 : 1);"],check=False)
    if available.returncode: args.opcache_extension='opcache'
result={'scope':'GH-22158 tracing JIT regression; genuine methods and independent coobserver; no runtime workaround',
        'extension':str(extension),'extension_sha256':hashlib.sha256(extension.read_bytes()).hexdigest(),
        'fixture_sha256':hashlib.sha256(fixture.read_bytes()).hexdigest(),
        'coobserver_source_sha256':hashlib.sha256(helper.read_bytes()).hexdigest(),
        'php_config_version':subprocess.check_output([args.php_config,'--version'],text=True).strip(),'cases':[]}
def save():
    if args.out: Path(args.out).write_text(json.dumps(result,indent=2)+'\n')
with tempfile.TemporaryDirectory(prefix='profiler-jit-coobserver-') as directory:
    other=Path(directory)/('coobserver.dylib' if platform.system()=='Darwin' else 'coobserver.so')
    compiler=shlex.split(os.environ.get('CC','cc'))
    flags=['-dynamiclib','-Wl,-undefined,dynamic_lookup'] if platform.system()=='Darwin' else ['-shared','-fPIC']
    subprocess.run(compiler+flags+includes+[str(helper),'-o',str(other)],check=True)
    modes=[('absent',False,None),('other',False,'before'),('disabled',True,None),('enabled',True,None),
           ('disabled',True,'before'),('enabled',True,'before'),('disabled',True,'after'),('enabled',True,'after')]
    for mode,loaded,order in modes:
        command=[args.php,'-n']
        if args.opcache_extension: command+=['-d','zend_extension='+args.opcache_extension]
        command+=['-d','opcache.enable=1','-d','opcache.enable_cli=1','-d','opcache.file_update_protection=0',
                  '-d','opcache.jit=1254','-d','opcache.jit_buffer_size=32M',
                  '-d','opcache.jit_hot_func=1','-d','opcache.jit_hot_loop=1',
                  '-d','opcache.jit_max_polymorphic_calls=2']
        if order=='before': command+=['-d','extension='+str(other)]
        if loaded: command+=['-d','extension='+str(extension)]
        if order=='after': command+=['-d','extension='+str(other)]
        command+=[str(fixture),mode,str(args.iterations)]
        completed=subprocess.run(command,capture_output=True,text=True,timeout=120)
        item={'mode':mode,'profiler_loaded':loaded,'coobserver_order':order,'command':command,
              'exit':completed.returncode,'stdout':completed.stdout,'stderr':completed.stderr}
        result['cases'].append(item); save()
        if completed.returncode: raise RuntimeError('megamorphic JIT subprocess failed: '+json.dumps(item))
        item['receipt']=json.loads(completed.stdout)
        if (order is None)!=(item['receipt']['other'] is None): raise RuntimeError('independent observer not loaded as requested')
        save()
if hashlib.sha256(extension.read_bytes()).hexdigest()!=result['extension_sha256']: raise RuntimeError('extension changed during proof')
result['complete']=True; save(); print(json.dumps(result,indent=2))
