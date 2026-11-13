#!/usr/bin/env python3
"""Paired disabled-observer release comparison; retain every timed sample."""
import argparse
import hashlib
import json
from pathlib import Path
import platform
import random
import statistics
import subprocess
import tempfile

parser = argparse.ArgumentParser()
parser.add_argument('--baseline', required=True)
parser.add_argument('--candidate', required=True)
parser.add_argument('--php', default='php')
parser.add_argument('--iterations', type=int, default=1000000)
parser.add_argument('--repeats', type=int, default=31)
args = parser.parse_args()
if args.iterations < 1 or args.repeats < 3:
    parser.error('positive iteration count and at least three repeats required')
artifacts = {name: str(Path(getattr(args, name)).resolve()) for name in ('baseline', 'candidate')}
source = r'''<?php
function observer_bench_leaf(int $i):int{return ($i*3)%97;}
$sum=0;$iterations=(int)$argv[1];
$t=hrtime(true);
for($i=0;$i<$iterations;$i++){$sum+=observer_bench_leaf($i);}
$elapsed=hrtime(true)-$t;
echo json_encode(['elapsed_ns'=>$elapsed,'sum'=>$sum]);
'''
samples = []
expected = None
with tempfile.TemporaryDirectory(prefix='profiler-observer-overhead-') as directory:
    script = Path(directory) / 'bench.php'
    script.write_text(source)
    for repetition in range(args.repeats + 1):
        order = ['absent', 'baseline', 'candidate']
        random.Random(repetition).shuffle(order)
        values = {}
        for name in order:
            command = [args.php, '-n']
            if name != 'absent':
                command += ['-d', 'extension=' + artifacts[name]]
            command += [str(script), str(args.iterations)]
            value = json.loads(subprocess.check_output(command, text=True))
            if expected is None:
                expected = value['sum']
            if value['sum'] != expected:
                raise RuntimeError('observer changed the workload result')
            values[name] = value['elapsed_ns']
        if repetition:
            samples.append({'repetition': repetition, 'order': order, 'elapsed_ns': values})
medians = {name: statistics.median(sample['elapsed_ns'][name] for sample in samples)
           for name in ('absent', 'baseline', 'candidate')}
print(json.dumps({'platform': platform.platform(),
                  'php': subprocess.check_output([args.php, '-v'], text=True),
                  'artifacts': {name: {'path': path, 'sha256': hashlib.sha256(Path(path).read_bytes()).hexdigest()}
                                for name, path in artifacts.items()},
                  'iterations': args.iterations, 'repeats': args.repeats,
                  'opcache': 'disabled (-n)', 'profiling': 'never enabled',
                  'median_ns': medians,
                  'candidate_to_baseline_paired_median': statistics.median(
                      sample['elapsed_ns']['candidate'] / sample['elapsed_ns']['baseline'] for sample in samples),
                  'baseline_to_absent': medians['baseline'] / medians['absent'],
                  'candidate_to_absent': medians['candidate'] / medians['absent'],
                  'samples': samples}, indent=2))
