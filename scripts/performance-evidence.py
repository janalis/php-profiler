#!/usr/bin/env python3
"""Run comparative workloads; retain measurements without an invented overhead limit."""
import argparse
import json
import os
from pathlib import Path
import subprocess
import sys


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--extension', required=True)
    parser.add_argument('--output', required=True)
    parser.add_argument('--repeats', type=int, default=7)
    parser.add_argument('--fpm', action='store_true')
    parser.add_argument('--with-sampling', action='store_true', help='also benchmark opt-in sampled captures')
    parser.add_argument('--with-warm-disabled', action='store_true', help='also measure CLI calls/worker after real prior capture and reset')
    args = parser.parse_args()
    if args.repeats < 3:
        parser.error('at least three repeats required')
    root = Path(__file__).resolve().parents[1]
    output = Path(args.output).resolve()
    output.mkdir(parents=True, exist_ok=True)
    load_before = os.getloadavg()
    commands = []
    for workload, iterations in [('calls', 1000000), ('cpu', 10000), ('sleep', 1000),
                                 ('memory', 10000), ('worker', 1000)]:
        commands.append((workload, [sys.executable, 'scripts/benchmark.py',
                         '--workload', workload, '--iterations', str(iterations)]))
    if args.fpm:
        commands.append(('fpm', [sys.executable, 'scripts/fpm-benchmark.py',
                                '--requests', '20', '--iterations', '10000']))
    if args.with_sampling:
        commands += [(name + '-sampling', command + ['--sampling']) for name, command in list(commands)]
    if args.with_warm_disabled:
        commands += [(name + '-warm-disabled', command + ['--warm-disabled'])
                     for name, command in list(commands) if name in ('calls', 'worker')]
    for name, command in commands:
        # Preserve stderr and partial stdout when an actual benchmark fails.
        with (output / (name + '.json')).open('w') as stdout, (output / (name + '.log')).open('w') as stderr:
            subprocess.run(command + ['--extension', str(Path(args.extension).resolve()),
                           '--repeats', str(args.repeats)], cwd=root, stdout=stdout,
                           stderr=stderr, check=True)
    (output / 'environment.json').write_text(json.dumps({
        'cpu_count': os.cpu_count(), 'load_before': load_before,
        'load_after': os.getloadavg(), 'scope': 'comparative evidence, not proof of an idle host',
        'acceptance': 'drivers require unchanged application results, complete exact instrumentation, valid CPU/wall measurements and internally consistent sampling; unserved stop-boundary wakes remain disclosed; no universal negligible-overhead threshold is asserted',
    }, indent=2) + '\n')


if __name__ == '__main__':
    main()
