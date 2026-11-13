#!/usr/bin/env python3
"""Explain GCC branch gaps without changing raw counts or relaxing the gate."""
import argparse
import gzip
import hashlib
import json
from pathlib import Path
import re
import sys


def notes(text):
    functions = {}
    current = None
    for line in text.splitlines():
        match = re.search(r"FUNCTION ident=(\d+), lineno_checksum=(0x\w+), cfg_checksum=(0x\w+), `([^']+)'", line)
        if match:
            current = {'signature': list(match.groups()[:3]), 'arcs': {}, 'locations': {}}
            functions[match[4]] = current
        elif current:
            match = re.search(r"block (\d+):`([^']+)':([\d, ]+)", line)
            if match:
                current['locations'].setdefault(int(match[1]), []).extend(
                    (match[2], int(number)) for number in match[3].split(','))
                continue
            match = re.search(r'block (\d+):\s+(.+)', line)
            if match:
                arcs = re.findall(r'(\d+):[0-9a-f]+(?:\(([^)]*)\))?', match[2])
                current['arcs'][int(match[1])] = [int(dest) for dest, flags in arcs if 'fake' not in flags]
    return functions


def tree_functions(text):
    result = {}
    parts = re.split(r'^;; Function ([\w.]+) .*$', text, flags=re.M)
    for index in range(1, len(parts), 2):
        blocks = re.split(r'^\s*<bb (\d+)>[^\n]*\n', parts[index + 1], flags=re.M)
        result[parts[index]] = {int(blocks[i]): blocks[i + 1] for i in range(1, len(blocks), 2)}
    return result


def constant_header_outcome(function, line, outcome, branch_count, raw_notes, rebuilt_notes, tree, suffix):
    original, rebuilt = raw_notes.get(function), rebuilt_notes.get(function)
    if not original or not rebuilt or original['signature'] != rebuilt['signature']:
        return None
    # GCC format 1 lacks branch block IDs. Require a unique matching source block;
    # never guess across several conditionals attributed to one source line.
    candidates = [(block, arcs) for block, arcs in original['arcs'].items()
                  if len(arcs) == branch_count and len(arcs) == 2 and
                  any(path.endswith(suffix) and number == line
                      for path, number in original['locations'].get(block, []))]
    if len(candidates) != 1:
        return None
    block, arcs = candidates[0]
    body = tree.get(function, {}).get(block, '')
    condition = re.search(r'\[([^\]]+):(\d+):(\d+)\] if \((\w+_\d+) (!=|==|<|>|<=|>=) (-?\d+)\)\s*\n\s*goto <bb (\d+)>;[^\n]*\n\s*else\s*\n\s*goto <bb (\d+)>;', body)
    if (not condition or condition[1].endswith(suffix) or
            '/include/php/' not in condition[1] or not condition[1].endswith('.h')):
        return None
    variable = condition[4]
    assignments = dict(re.findall(r'\b(\w+_\d+) = (-?\d+|\w+_\d+);', body[:condition.start()]))
    value, chain = variable, []
    while not re.fullmatch(r'-?\d+', value):
        if value in chain or value not in assignments:
            return None
        chain.append(value)
        value = assignments[value]
    left, right = int(value), int(condition[6])
    comparisons = {'!=': left != right, '==': left == right, '<': left < right,
                   '>': left > right, '<=': left <= right, '>=': left >= right}
    taken = int(condition[7] if comparisons[condition[5]] else condition[8])
    if set(arcs) != {int(condition[7]), int(condition[8])} or arcs[outcome] == taken:
        return None
    return {'kind': 'inlined_header_constant_parameter', 'function_signature': original['signature'],
            'source_block': block, 'untaken_destination': arcs[outcome],
            'constant_assignment': f'{variable}={left}', 'ssa_alias_chain': chain,
            'condition': f'{variable} {condition[5]} {right}',
            'condition_file': condition[1], 'condition_line': int(condition[2]),
            'note': 'Proof explains this instantiated header outcome; raw branch coverage remains incomplete.'}


def analyze(data, suffix, original=None, rebuilt=None, tree=None):
    selected = [entry for entry in data['files'] if entry['file'].replace('\\', '/').endswith(suffix)]
    if len(selected) != 1:
        raise ValueError(f'expected one {suffix} report, found {len(selected)}')
    entry = selected[0]
    lines, functions = entry['lines'], entry['functions']
    branches = [branch for line in lines for branch in line.get('branches', [])]
    totals = {'lines': {'covered': sum(line['count'] > 0 for line in lines), 'count': len(lines)},
              'functions': {'covered': sum(f['execution_count'] > 0 for f in functions), 'count': len(functions)},
              'branches': {'covered': sum(b['count'] > 0 for b in branches), 'count': len(branches)}}
    gaps = []
    for line in lines:
        for index, branch in enumerate(line.get('branches', [])):
            if branch['count'] != 0:
                continue
            proof = constant_header_outcome(line.get('function_name'), line['line_number'], index,
                                            len(line['branches']), original or {}, rebuilt or {}, tree or {}, suffix)
            gaps.append({'function': line.get('function_name'), 'line': line['line_number'],
                         'branch_index': index, 'raw_count': 0, 'evidence': proof,
                         'classification': proof['kind'] if proof else 'unresolved'})
    return {'schema_version': 1, 'compiler': data.get('gcc_version'), 'source_file': entry['file'],
            'raw_counts': totals, 'raw_gate_pass': all(v['count'] > 0 and v['covered'] == v['count'] for v in totals.values()),
            'uncovered_outcomes': gaps,
            'explained_constant_header_outcomes': sum(g['evidence'] is not None for g in gaps),
            'unresolved_outcomes': sum(g['evidence'] is None for g in gaps),
            'adjusted_coverage_percentage': None,
            'scope': 'GCC raw report, with optional checksum-matched instantiated-header evidence; no outcomes are excluded.'}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('report', type=Path)
    parser.add_argument('--file-suffix', default='src/bridge.c')
    parser.add_argument('--notes', type=Path, help='gcov-dump -l from the measured gcno')
    parser.add_argument('--rebuilt-notes', type=Path, help='gcov-dump -l from the analyzed rebuild')
    parser.add_argument('--optimized-tree', type=Path, help='GCC -fdump-tree-optimized-lineno-details output')
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    opener = gzip.open if args.report.suffix == '.gz' else open
    with opener(args.report, 'rt') as source:
        data = json.load(source)
    evidence = [args.notes, args.rebuilt_notes, args.optimized_tree]
    if any(evidence) and not all(evidence):
        parser.error('provide all three evidence files, or none')
    result = analyze(data, args.file_suffix,
                     notes(args.notes.read_text()) if args.notes else None,
                     notes(args.rebuilt_notes.read_text()) if args.rebuilt_notes else None,
                     tree_functions(args.optimized_tree.read_text()) if args.optimized_tree else None)
    result['input_sha256'] = {str(path): hashlib.sha256(path.read_bytes()).hexdigest()
                              for path in [args.report] + [p for p in evidence if p]}
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(result, indent=2) + '\n')
    print(json.dumps({key: result[key] for key in ('raw_counts', 'raw_gate_pass', 'explained_constant_header_outcomes', 'unresolved_outcomes')}, sort_keys=True))
    return 0 if result['raw_gate_pass'] else 1


if __name__ == '__main__':
    sys.exit(main())
