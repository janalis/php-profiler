#!/usr/bin/env python3
"""Mutate every runtime Rust source while executing all real native tests."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
from validation_inputs import snapshot
from runtime_sources import runtime_sources
from mutation_unviable import audit as audit_unviable

root = Path(__file__).resolve().parent.parent
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--package', action='append', default=[])
parser.add_argument('--output', type=Path, default=root / 'target/rust-mutation')
parser.add_argument('--timeout', type=int, default=120)
parser.add_argument('--jobs', type=int, default=2)
parser.add_argument('--list-only', action='store_true')
args = parser.parse_args()
if args.jobs > 1 and os.environ.get("CARGO_TARGET_DIR"):
    parser.error("Parallel native mutation workers require separate target directories; use --jobs 1 with an inherited CARGO_TARGET_DIR")
os.chdir(root)
parent = args.output.resolve()
parent.mkdir(parents=True, exist_ok=True)
run = Path(tempfile.mkdtemp(prefix='run-', dir=parent))
before = snapshot(root)
(run / 'source-before.json').write_text(json.dumps(before, indent=2) + '\n')
metadata = json.loads(subprocess.check_output(['cargo', 'metadata', '--format-version', '1', '--no-deps']))
members = {package['name']: Path(package['manifest_path']).parent.resolve()
           for package in metadata['packages'] if package['id'] in metadata['workspace_members']}
if set(args.package) - members.keys():
    parser.error('Unknown workspace package: ' + ', '.join(set(args.package) - members.keys()))
all_sources = runtime_sources(root, 'rust')
expected = all_sources if not args.package else {
    source for source in all_sources if any(source.is_relative_to(members[name] / 'src') for name in args.package)}
if not expected:
    sys.exit('No runtime Rust sources registered')
command = ['cargo', 'mutants']
if args.package:
    for name in args.package:
        command += ['-p', name]
else:
    command += ['--workspace']
unfiltered_command = list(command)
for source in sorted(expected):
    command += ['--file', str(source.relative_to(root))]
environment = dict(os.environ, PROFILER_ADAPTER_CONTRACT_TEST='1', PROFILER_C_UNIT_BOUNDARIES='1', PROFILER_SAMPLING_UNIT='1', CARGO_INCREMENTAL='0')
status = 1
manifest = {'mode': 'discovery-only' if args.list_only else 'full-mutation',
            'scope': 'all runtime Rust sources' if not args.package else 'targeted workspace packages',
            'packages': args.package or sorted(members),
            'runtime_sources': {str(p.relative_to(root)): hashlib.sha256(p.read_bytes()).hexdigest() for p in sorted(expected)},
            'all_workspace_runtime_sources': sorted(str(p.relative_to(root)) for p in all_sources),
            'test_infrastructure_is_executed_not_mutated': True,
            'discovery_command': command + ['--list', '--json'],
            'raw_counts': None,
            'compiler_cache_policy': {'CARGO_INCREMENTAL': environment['CARGO_INCREMENTAL']},
            'effective_compiler_flags': {key: environment.get(key) for key in
                ('RUSTFLAGS', 'CARGO_ENCODED_RUSTFLAGS', 'CC', 'CFLAGS', 'CLANG_PATH', 'LIBCLANG_PATH')}}
try:
    unfiltered = subprocess.run(unfiltered_command + ['--list', '--json'], env=environment, capture_output=True, text=True)
    (run / 'discovery-all-sources.json').write_text(unfiltered.stdout)
    (run / 'discovery-all-sources.stderr').write_text(unfiltered.stderr)
    if unfiltered.returncode:
        raise RuntimeError('Unfiltered Cargo mutation discovery failed')
    outside = {(root / candidate['file']).resolve() for candidate in json.loads(unfiltered.stdout)} - expected
    test_roots = [root / 'tests'] + [directory / 'tests' for directory in members.values()]
    unexpected = {path for path in outside if not any(path.is_relative_to(directory) for directory in test_roots)}
    if unexpected:
        raise RuntimeError('Unregistered runtime source outside exhaustive registry: ' + str(sorted(map(str, unexpected))))
    manifest['discovered_test_infrastructure'] = sorted(str(path.relative_to(root)) for path in outside)
    discovery = subprocess.run(command + ['--list', '--json'], env=environment, capture_output=True, text=True)
    (run / 'discovery.json').write_text(discovery.stdout)
    (run / 'discovery.stderr').write_text(discovery.stderr)
    if discovery.returncode:
        raise RuntimeError('Cargo mutation discovery failed')
    mutants = json.loads(discovery.stdout)
    discovered = {(root / candidate['file']).resolve() for candidate in mutants}
    if discovered != expected:
        raise RuntimeError('Runtime mutation source mismatch; absent=' + str(sorted(map(str, expected-discovered))) +
                           '; unexpected=' + str(sorted(map(str, discovered-expected))))
    manifest['generated'] = len(mutants)
    if args.list_only:
        status = 0
    else:
        testing = command + ['--test-workspace', 'true', '--test-tool', 'nextest',
                             '--cargo-test-arg=--max-fail=1:immediate',
                             '--cargo-test-arg=--config-file=tests/nextest.toml',
                             '--timeout', str(args.timeout),
                             '--jobs', str(args.jobs), '--output', str(run)]
        manifest['test_command'] = testing
        status = subprocess.run(testing, env=environment).returncode
        outcomes = json.loads((run / 'mutants.out/outcomes.json').read_text())
        manifest['raw_counts'] = {key: outcomes[key] for key in ('total_mutants', 'caught', 'missed', 'timeout', 'unviable')}
        unviable = audit_unviable(outcomes, run / 'mutants.out')
        (run / 'unviable-audit.json').write_text(json.dumps(unviable, indent=2) + '\n')
        manifest['unviable_classification'] = {key: unviable[key] for key in
            ('compiler_rejected', 'infrastructure_or_unknown')}
        if unviable['infrastructure_or_unknown']:
            status = 1
        if (outcomes['total_mutants'] != len(mutants) or outcomes['missed'] or outcomes['timeout']):
            status = 1
except Exception as failure:
    manifest['error'] = str(failure)
    print(str(failure), file=sys.stderr)
    status = 1
finally:
    after = snapshot(root)
    (run / 'source-after.json').write_text(json.dumps(after, indent=2) + '\n')
    changed = sorted(key for key in before.keys() | after.keys() if before.get(key) != after.get(key))
    manifest.update(exit_code=status if not changed else 1, source_snapshot_matches=not changed,
                    changed_inputs=changed)
    (run / 'run.json').write_text(json.dumps(manifest, indent=2) + '\n')
    with tempfile.NamedTemporaryFile(mode='w', dir=parent, prefix='.latest-', delete=False) as output:
        json.dump(manifest, output, indent=2)
        output.write('\n')
        temporary = output.name
    os.replace(temporary, parent / 'latest.json')
print('Rust mutation manifest: ' + str(run / 'run.json'), flush=True)
sys.exit(manifest['exit_code'])
