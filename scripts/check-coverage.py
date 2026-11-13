#!/usr/bin/env python3
"""Fail closed on uncovered first-party source; retain raw external data separately."""
import argparse
import json
from pathlib import Path
import sys
from runtime_sources import runtime_sources

parser = argparse.ArgumentParser()
parser.add_argument('report')
parser.add_argument('--workspace-root', type=Path)
parser.add_argument('--language', choices=('all', 'rust', 'c'), default='all')
args = parser.parse_args()
with open(args.report) as source:
    report = json.load(source)
failed = []
for index, dataset in enumerate(report['data']):
    totals = dataset['totals']
    if args.workspace_root:
        root = args.workspace_root.resolve() / 'crates'
        expected = runtime_sources(args.workspace_root, args.language)
        selected = []
        seen = set()
        external = 0
        for entry in dataset['files']:
            path = Path(entry['filename']).resolve()
            if path in expected:
                if path in seen:
                    failed.append(f'duplicate first-party source entry: {path}')
                seen.add(path)
                selected.append(entry)
                for metric in ('lines', 'functions', 'branches'):
                    summary = entry['summary'].get(metric, {})
                    if summary.get('count', 0) == 0:
                        failed.append(f'{path}: {metric} not measured')
                    elif summary.get('covered', 0) != summary.get('count', 0):
                        failed.append(f'{path}: {metric} {summary.get("covered", 0)}/{summary.get("count", 0)}')
            else:
                external += 1
        for missing in sorted(expected - seen):
            failed.append(f'first-party source absent from coverage: {missing}')
        if not expected:
            failed.append('no expected first-party production source files found')
        if not selected:
            failed.append('no first-party production source files found')
        totals = {metric: {'count': sum(f['summary'].get(metric, {}).get('count', 0) for f in selected),
                           'covered': sum(f['summary'].get(metric, {}).get('covered', 0) for f in selected)}
                  for metric in ('lines', 'functions', 'branches')}
        print(f'dataset {index}: {len(selected)} first-party source files; {external} other files retained in raw artifact')
    for metric in ('lines', 'functions', 'branches'):
        summary = totals.get(metric)
        if not summary or summary.get('count', 0) == 0:
            failed.append(f'{index}: {metric} not measured')
        elif summary['covered'] != summary['count']:
            failed.append(f'{index}: {metric} {summary["covered"]}/{summary["count"]}')
        else:
            print(f'{index}: {metric} {summary["covered"]}/{summary["count"]}')
if failed:
    sys.exit('\n'.join(failed))
print('100% measured first-party lines, functions, and branches')
