#!/usr/bin/env python3
"""Same-worker closure cache lifetimes with actual FPM OPcache/JIT/preload receipts."""
import argparse
import concurrent.futures
from collections import Counter
import json
import os
from pathlib import Path
import subprocess
from fpm_support import FpmServer

parser=argparse.ArgumentParser()
parser.add_argument('--mode',choices=['disabled','opcache','jit','preload','preload-jit'],default='opcache')
parser.add_argument('--workers',type=int,choices=[1,4],default=1)
parser.add_argument('--require-jit',action='store_true')
parser.add_argument('--require-uid-probe',action='store_true')
parser.add_argument('--expected-php',help='Expected FPM PHP major.minor from the matrix label')
parser.add_argument('--expected-zts',choices=['nts','ts'])
args=parser.parse_args()
companion_version=header_version=None
if args.expected_php or args.expected_zts:
    companion_version=subprocess.check_output([os.environ.get('PHP','php'),'-n','-r',
                                               'echo PHP_VERSION;'],text=True).strip()
    header_version=subprocess.check_output([os.environ.get('PHP_CONFIG','php-config'),'--version'],text=True).strip()
    if companion_version!=header_version:
        raise RuntimeError('PHP companion and SDK versions differ before FPM startup')
root=Path(__file__).resolve().parent.parent
os.chdir(root)
settings={'opcache.enable':int(args.mode!='disabled'),'opcache.enable_cli':1,
          'opcache.file_update_protection':0,'opcache.validate_timestamps':0}
if os.environ.get('PROFILER_FPM_OPCACHE_EXTENSION'):
    settings['zend_extension']=os.environ['PROFILER_FPM_OPCACHE_EXTENSION']
if args.mode in ('jit','preload-jit'):
    settings.update({'opcache.jit':'tracing','opcache.jit_buffer_size':'16M',
                     'opcache.jit_hot_func':1,'opcache.jit_hot_loop':1})
if args.mode in ('preload','preload-jit'):
    settings['opcache.preload']=str(root/'tests/support/closure-worker-functions.php')
    if os.geteuid()==0:
        settings['opcache.preload_user']=os.environ.get('PROFILER_FPM_PRELOAD_USER','www-data')
with FpmServer(os.environ['PROFILER_EXTENSION'],ini_settings=settings,workers=args.workers) as server:
    def request(index):
        payload=server.request('tests/fpm/closure-request.php',{'PROFILE_TEST_TOKEN':str(index)})
        if payload['id']!=1 or payload['result']!=index+1 or payload['checksum']!=4025*index+549:
            raise RuntimeError('request identity or PHP result leaked')
        report=payload['report']
        runtime=report['runtime_metadata']
        if runtime['sapi']!='fpm-fcgi':
            raise RuntimeError('FPM request did not report the actual FPM SAPI')
        if companion_version and runtime['php_version']!=companion_version:
            raise RuntimeError('actual FPM version differs from the PHP companion and SDK')
        if args.expected_php and '.'.join(runtime['php_version'].split('.')[:2])!=args.expected_php:
            raise RuntimeError('actual FPM PHP minor differs from its matrix label')
        if args.expected_zts and runtime['zts']!=str(args.expected_zts=='ts').lower():
            raise RuntimeError('actual FPM ZTS mode differs from its matrix label')
        rows=[metric['calls'] for symbol,metric in report['functions'].items() if '{closure#' in symbol]
        if report['status']!='complete' or sorted(rows)!=[2]*20+[24]:
            raise RuntimeError('closure rows/call counts merged across FPM requests')
        if args.mode!='disabled' and not(payload['opcache_enabled'] and payload['shared_file_cached']):
            raise RuntimeError('requested FPM shared OPcache was not actually active/cached')
        if args.mode in ('preload','preload-jit') and not payload['preloaded']:
            raise RuntimeError('preload configuration did not provide persistent functions before include')
        return payload
    with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
        results=list(pool.map(request,range(32)))
    pids={item['pid'] for item in results}
    runtimes={(item['report']['runtime_metadata']['php_version'],
               item['report']['runtime_metadata']['sapi'],
               item['report']['runtime_metadata']['zts']) for item in results}
    if len(runtimes)!=1:
        raise RuntimeError('FPM worker pool mixed PHP runtime identities')
    php_version,sapi,zts=next(iter(runtimes))
    if len(pids)!=args.workers:
        raise RuntimeError('FPM did not preserve the expected worker pool during repeated request proof')
    per_worker=Counter(item['pid'] for item in results)
    if min(per_worker.values())<2:
        raise RuntimeError('every FPM worker must prove request-state reuse')
    edges=[]
    for item in results:
        event=next(event for event in item['report']['timeline'] if event['symbol']=='zts_profiled_leaf')
        if event['end_ns']<=event['start_ns']:
            raise RuntimeError('profiled leaf lacks a positive measured wall interval')
        edges.extend([(event['start_ns'],1),(event['end_ns'],-1)])
    active=maximum=0
    for _,delta in sorted(edges):
        active+=delta
        maximum=max(maximum,active)
    if active!=0 or maximum!=args.workers:
        raise RuntimeError('measured application intervals did not prove actual FPM pool concurrency')
    jit=[item for item in results if item['jit'] and item['jit'].get('enabled') and item['jit'].get('on')]
    consumed=sum(max(0,item['jit_before']['buffer_free']-item['jit']['buffer_free'])
                 for item in jit if item['jit_before'])
    if args.require_jit and (len(jit)!=32 or consumed==0):
        raise RuntimeError('requested proof requires actual FPM JIT on plus generated-code buffer consumption')
    probe=[item['storage'] for item in results if item['storage'] and 'uid' in item['storage']]
    if args.require_uid_probe and len(probe)!=32:
        raise RuntimeError('CUNIT read-only closure UID probe is required for raw cache sequence proof')
    if probe:
        if any(int(item['uid'])==0 for item in probe):
            raise RuntimeError('a called shared closure had no issued UID')
        # Either per-request caches get fresh IDs, or a retained cache keeps its
        # own existing ID; both must preserve all independent eval row counts.
        uid_scope={'observations':len(probe),'distinct_uids':len({item['uid'] for item in probe}),
                   'distinct_runtime_cache_addresses':len({item['runtime_cache'] for item in probe}),
                   'distinct_opcode_addresses':len({item['opcodes'] for item in probe}),
                   'scope':'UID identity is process/cache-local; no cross-process uniqueness claim'}
    else:
        uid_scope='normal runtime exposes no raw cache IDs; exact row/count/preload assertions only'
    print(json.dumps({'mode':args.mode,'requests':32,'workers':args.workers,'worker_pids':sorted(pids),
                      'requests_per_worker':dict(per_worker),'maximum_overlapping_leaf_intervals':maximum,
                      'same_worker_pid':next(iter(pids)) if args.workers==1 else None,
                      'php_version':php_version,'sapi':sapi,'zts':zts=='true',
                      'php_companion_version':companion_version,'php_config_version':header_version,
                      'closure_rows_per_report':21,'closure_calls_per_report':64,
                      'opcache_active':sum(item['opcache_enabled'] for item in results),
                      'shared_file_cached':sum(item['shared_file_cached'] for item in results),
                      'functions_preloaded':sum(item['preloaded'] for item in results),
                      'jit_on_requests':len(jit),'jit_generated_bytes_observed':consumed,
                      'uid_cache_scope':uid_scope},indent=2))
