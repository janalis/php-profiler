#!/usr/bin/env python3
"""Strict Clang gcov intermediate coverage: every raw first-party C counter."""
import argparse
import json
from pathlib import Path
import sys
from runtime_sources import runtime_sources

parser = argparse.ArgumentParser()
parser.add_argument('reports', type=Path)
parser.add_argument('--workspace-root', required=True, type=Path)
args = parser.parse_args()
root = args.workspace_root.resolve()
expected = runtime_sources(root, 'c')
files = {}
external = set()
for raw in args.reports.glob('*.gcov'):
    current = None
    for line in raw.read_text().splitlines():
        kind, separator, value = line.partition(':')
        if kind == 'file' and separator:
            path = Path(value)
            if path.is_absolute():
                path = path.resolve()
            else:
                candidate = (root / path).resolve()
                if candidate not in expected:
                    # cc::Build records filenames relative to the crate build
                    # directory. Require an unambiguous full relative suffix.
                    matches = [item for item in expected if any(
                        (crate / path).resolve() == item for crate in (root / 'crates').iterdir() if crate.is_dir())]
                    if len(matches) > 1:
                        sys.exit('Ambiguous compiler source filename: ' + value)
                    candidate = matches[0] if matches else candidate
                path = candidate
            current = files.setdefault(str(path), {'lines': [], 'functions': [], 'branches': []}) if path in expected else None
            if path not in expected:
                external.add(str(path))
        elif current is not None:
            parts = value.split(',')
            if kind == 'function':
                current['functions'].append({'line': int(parts[0]), 'count': int(parts[1]), 'name': ','.join(parts[2:])})
            elif kind == 'lcount':
                current['lines'].append({'line': int(parts[0]), 'count': int(parts[1])})
            elif kind == 'branch':
                if parts[1] not in ('taken', 'nottaken', 'notexec'):
                    sys.exit('Unknown raw Clang branch state: ' + line)
                current['branches'].append({'line': int(parts[0]), 'covered': parts[1] == 'taken', 'raw_state': parts[1]})
failed = set(map(str, expected)) != set(files) or not expected
summary = {'files': files, 'external_files_retained': len(external), 'passed': False}
for name, counters in files.items():
    for metric in ('lines', 'functions', 'branches'):
        entries = counters[metric]
        covered = sum(e['covered'] if metric == 'branches' else e['count'] > 0 for e in entries)
        print(f'{name}: {metric} {covered}/{len(entries)}')
        failed |= not entries or covered != len(entries)
summary['passed'] = not failed
(args.reports / 'strict-summary.json').write_text(json.dumps(summary, indent=2) + '\n')
if failed:
    sys.exit('Clang gcov strict first-party coverage gate failed; raw reports retained')
print('100% raw Clang-gcov first-party C lines, functions and branches')
