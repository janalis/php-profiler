#!/usr/bin/env python3
"""Require every non-equivalent C mutant killed; never rewrite raw outcomes."""
import argparse
from collections import Counter
import hashlib
import json
from pathlib import Path
import sys
from runtime_sources import runtime_sources

parser = argparse.ArgumentParser()
parser.add_argument('report_directory', type=Path)
parser.add_argument('--workspace-root', type=Path)
parser.add_argument('--equivalence-ledger', type=Path)
args = parser.parse_args()
expected = runtime_sources(args.workspace_root, 'c') if args.workspace_root else None
ledger = {}
CONTEXT_KEYS = {'compiler_sha256', 'compiler_version', 'mull_version', 'plugin_sha256',
                'compiler_flags', 'native_compile_commands', 'native_fixture_symbols', 'system', 'machine',
                'php_zts', 'php_version_id', 'php_int_size', 'rustc_version',
                'rustflags', 'cargo_encoded_rustflags', 'libc_sha256'}

def signature(source, mutant):
    location = mutant['location']
    coordinates = tuple(location[side][axis] for side in ('start', 'end') for axis in ('line', 'column'))
    if any(type(value) is not int or value <= 0 for value in coordinates):
        sys.exit('C mutation gate: invalid exact source span')
    return source, mutant['mutatorName'], coordinates, mutant['replacement']

if args.equivalence_ledger:
    if args.workspace_root is None:
        sys.exit('C mutation gate: equivalence classification requires an exhaustive workspace registry')
    root = args.workspace_root.resolve()
    document = json.loads(args.equivalence_ledger.read_text())
    if document.get('schema_version') not in (1, 2) or not isinstance(document.get('equivalents'), list):
        sys.exit('C mutation gate: unsupported equivalence ledger')
    context_path = args.report_directory / 'provenance/equivalence-context.json'
    context = json.loads(context_path.read_text()) if context_path.is_file() else {}
    audited_contexts = document.get('contexts', {})
    dependencies = document.get('dependency_sha256', {})
    if document['schema_version'] == 2:
        if not isinstance(audited_contexts, dict) or not audited_contexts or any(
                not isinstance(value, dict) or not CONTEXT_KEYS.issubset(value)
                for value in audited_contexts.values()):
            sys.exit('C mutation gate: equivalence contexts must pin the complete native configuration')
        if not isinstance(dependencies, dict) or not dependencies:
            sys.exit('C mutation gate: equivalence dependency hashes missing')
        for name, digest in dependencies.items():
            dependency = (root / name).resolve()
            if not dependency.is_relative_to(root) or not dependency.is_file() or \
                    hashlib.sha256(dependency.read_bytes()).hexdigest() != digest:
                sys.exit('C mutation gate: equivalence dependency changed; new audit required')
    for entry in document['equivalents']:
        if document['schema_version'] == 2:
            # Missing/different context never grants an equivalent classification.
            names = entry.get('contexts')
            if not isinstance(names, list) or not names or any(
                    not isinstance(name, str) or name not in audited_contexts for name in names):
                sys.exit('C mutation gate: unknown or missing equivalence context reference')
            if len(names) != len(set(names)):
                sys.exit('C mutation gate: duplicate equivalence context reference')
            variants = [audited_contexts[name] for name in names]
            if not any(all(key in context and type(context[key]) is type(value)
                           and json.dumps(context[key], sort_keys=True) == json.dumps(value, sort_keys=True)
                           for key, value in variant.items()) for variant in variants):
                continue
        source = (root / entry['source']).resolve()
        if source not in expected:
            sys.exit('C mutation gate: equivalence source outside runtime registry')
        if hashlib.sha256(source.read_bytes()).hexdigest() != entry['source_sha256']:
            sys.exit('C mutation gate: equivalence source hash changed; new audit required')
        proof = (root / entry['proof'].split('#', 1)[0]).resolve()
        if not proof.is_relative_to(root) or not proof.is_file():
            sys.exit('C mutation gate: audited equivalence proof missing')
        if document['schema_version'] == 2:
            proof_name = str(proof.relative_to(root))
            if proof_name not in dependencies:
                sys.exit('C mutation gate: equivalence proof hash missing')
        key = signature(source, entry)
        if key in ledger:
            sys.exit('C mutation gate: duplicate equivalence classification')
        ledger[key] = entry

files = sorted(args.report_directory.glob('*.json'))
if not files:
    sys.exit('C mutation gate: no Elements JSON report produced')
statuses, seen, equivalent = Counter(), set(), []
failures = []
classified_keys = set()
for path in files:
    report = json.loads(path.read_text())
    if 'files' not in report:
        sys.exit(f'C mutation gate: malformed Elements report {path}')
    for filename, source in report['files'].items():
        resolved = Path(filename).resolve()
        if expected is not None:
            if resolved not in expected or resolved in seen:
                sys.exit(f'C mutation gate: unexpected or duplicate runtime source: {filename}')
            seen.add(resolved)
            if not source.get('mutants'):
                sys.exit(f'C mutation gate: runtime source generated no mutations: {filename}')
        for mutant in source['mutants']:
            status = mutant['status']
            statuses[status] += 1
            if status == 'Killed':
                continue
            if status == 'Survived' and ledger and signature(resolved, mutant) in ledger:
                key = signature(resolved, mutant)
                if key in classified_keys:
                    sys.exit('C mutation gate: duplicate audited mutant match')
                classified_keys.add(key)
                equivalent.append({'raw_id': mutant['id'], 'source': str(resolved),
                                   'raw_status': status, 'classification': 'Equivalent',
                                   'ledger': ledger[signature(resolved, mutant)]})
            else:
                failures.append(mutant.get('id', filename) + ': ' + status)
if expected is not None and (not expected or seen != expected):
    sys.exit('C mutation gate: exhaustive runtime registry mismatch; missing=' + str(sorted(map(str, expected - seen))))
raw_total = sum(statuses.values())
non_equivalent = raw_total - len(equivalent)
print('C mutation raw outcomes:', dict(statuses))
classification = {'raw_outcomes': dict(statuses), 'raw_killed': statuses['Killed'],
                  'raw_generated': raw_total, 'audited_equivalent_count': len(equivalent),
                  'audited_equivalents': equivalent, 'non_equivalent_generated': non_equivalent,
                  'non_equivalent_killed': statuses['Killed'], 'unclassified_failures': failures}
provenance = args.report_directory / 'provenance'
provenance.mkdir(exist_ok=True)
if args.equivalence_ledger:
    (provenance / 'audited-equivalence-ledger.json').write_text(args.equivalence_ledger.read_text())
    classification['audit_manifest_sha256'] = hashlib.sha256(args.equivalence_ledger.read_bytes()).hexdigest()
(provenance / 'classification.json').write_text(json.dumps(classification, indent=2) + '\n')
if not raw_total or not non_equivalent:
    sys.exit('C mutation gate: no non-equivalent mutants; instrumentation/filter not verified')
if failures or statuses['Killed'] != non_equivalent:
    sys.exit('C mutation gate: non-equivalent mutants not killed: ' + '; '.join(failures))
print(f'C mutation gate: raw {statuses["Killed"]}/{raw_total} killed; '
      f'{len(equivalent)} audited equivalent; non-equivalent {statuses["Killed"]}/{non_equivalent} killed')
