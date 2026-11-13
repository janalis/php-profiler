#!/usr/bin/env python3
"""Require test transports to preserve the ordinary production C inventory."""
import argparse
import hashlib
import json
from pathlib import Path
import subprocess
import sys
from runtime_sources import runtime_sources


def identities(nm_output, root, expected):
    result = set()
    for line in nm_output.splitlines():
        fields = line.split(maxsplit=2)
        if len(fields) != 3 or fields[1] not in ('t', 'T') or not fields[2].startswith('cxx_'):
            continue
        mutator, location = fields[2].split(':', 1)
        filename, *coordinates = location.rsplit(':', 4)
        if len(coordinates) != 4 or any(not value.isdigit() or int(value) <= 0 for value in coordinates):
            raise ValueError('Malformed native mutation identity')
        source = Path(filename).resolve()
        if source not in expected:
            raise ValueError('Mutation outside exhaustive runtime registry: ' + filename)
        result.add(mutator + ':' + str(source.relative_to(root)) + ':' + ':'.join(coordinates))
    return result


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--workspace-root', required=True, type=Path)
    parser.add_argument('--extension', required=True, type=Path)
    parser.add_argument('--context', required=True, type=Path)
    parser.add_argument('--output', required=True, type=Path)
    args = parser.parse_args()
    root = args.workspace_root.resolve()
    expected = runtime_sources(root, 'c')
    context = json.loads(args.context.read_text())
    args.output.mkdir(parents=True, exist_ok=True)
    ordinary, objects = set(), []
    for source in sorted(expected):
        unit = source.stem + '-unit.c'
        recorded = context['native_compile_commands'][unit]
        # Actual DW_AT_producer text starts with version words followed by the
        # absolute compiler executable. Do not execute the version preamble.
        start = next(index for index, value in enumerate(recorded)
                     if value.startswith('/') and Path(value).is_file() and
                     hashlib.sha256(Path(value).read_bytes()).hexdigest() == context['compiler_sha256'])
        command = recorded[start:]
        input_position = command.index('-c') + 1
        if not command[input_position].endswith('/' + unit):
            raise ValueError('Native input provenance does not identify ' + unit)
        command[input_position] = str(source)
        object_path = args.output.resolve() / (source.stem + '.o')
        command += ['-o', str(object_path)]
        compiled = subprocess.run(command, cwd=root, capture_output=True, text=True)
        (args.output / (source.stem + '-compile.log')).write_text(compiled.stdout + compiled.stderr)
        if compiled.returncode:
            raise RuntimeError('Ordinary C inventory compilation failed: ' + str(source))
        symbols = subprocess.check_output(['nm', '--defined-only', str(object_path)], text=True)
        (args.output / (source.stem + '.nm')).write_text(symbols)
        candidates = identities(symbols, root, expected)
        if not candidates:
            raise RuntimeError('Ordinary runtime C source emitted no mutations: ' + str(source))
        ordinary.update(candidates)
        objects.append({'source': str(source.relative_to(root)), 'command': command,
                        'source_sha256': hashlib.sha256(source.read_bytes()).hexdigest(),
                        'object_sha256': hashlib.sha256(object_path.read_bytes()).hexdigest(),
                        'generated': len(candidates)})
    symbols = subprocess.check_output(['nm', '--defined-only', str(args.extension)], text=True)
    (args.output / 'extension.nm').write_text(symbols)
    instrumented = identities(symbols, root, expected)
    result = {'ordinary_generated': len(ordinary), 'test_generated': len(instrumented),
              'missing_from_test_transport': sorted(ordinary - instrumented),
              'extra_from_test_transport': sorted(instrumented - ordinary),
              'ordinary_identities': sorted(ordinary), 'test_identities': sorted(instrumented),
              'objects': objects,
              'extension_sha256': hashlib.sha256(args.extension.read_bytes()).hexdigest()}
    result['passed'] = bool(ordinary) and ordinary == instrumented
    (args.output / 'comparison.json').write_text(json.dumps(result, indent=2) + '\n')
    if not result['passed']:
        raise RuntimeError('Test transport changes the ordinary production mutation inventory')
    print('Ordinary/test C mutation inventories match: ' + str(len(ordinary)))


if __name__ == '__main__':
    try:
        main()
    except (KeyError, ValueError, RuntimeError, StopIteration, subprocess.CalledProcessError) as failure:
        sys.exit('C mutation inventory gate: ' + str(failure))
