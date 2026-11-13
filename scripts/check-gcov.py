#!/usr/bin/env python3
"""Require all first-party C lines/functions/branch outcomes in GCC's raw report."""
import gzip
import json
import pathlib
import sys
reports = list(pathlib.Path(sys.argv[1]).glob('*.gcov.json.gz'))
files = []
for path in reports:
    with gzip.open(path, 'rt') as source:
        data = json.load(source)
    for item in data['files']:
        if item['file'].replace('\\', '/').endswith('src/bridge.c'):
            files.append(item)
if len(files) != 1:
    sys.exit(f'expected exactly one first-party bridge report, got {len(files)}')
report = files[0]
lines = report['lines']
functions = report['functions']
branches = [branch for line in lines for branch in line.get('branches', [])]
counts = {
    'lines': (sum(line['count'] > 0 for line in lines), len(lines)),
    'functions': (sum(function['execution_count'] > 0 for function in functions), len(functions)),
    'branches': (sum(branch['count'] > 0 for branch in branches), len(branches)),
}
failed = False
for name, (covered, total) in counts.items():
    print(f'{name}: {covered}/{total}')
    failed |= total == 0 or covered != total
for line in lines:
    if not line['count'] or any(branch['count'] == 0 for branch in line.get('branches', [])):
        print(f'uncovered C line/branch at {line["line_number"]}: {line}')
if failed:
    sys.exit(1)
