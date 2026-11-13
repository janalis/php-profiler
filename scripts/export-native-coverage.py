#!/usr/bin/env python3
"""Export exact Cargo test artifacts with the executing native module primary."""
import json
from pathlib import Path
import subprocess
import sys

llvm_cov, run, extension = sys.argv[1], Path(sys.argv[2]), Path(sys.argv[3])
objects = set()
for name in ('core-tests.jsonl', 'adapter-tests.jsonl'):
    unit_object_found = False
    for line in (run / name).read_text().splitlines():
        try:
            message = json.loads(line)
        except json.JSONDecodeError:
            continue  # Cargo's test executable also prints non-JSON test outcomes.
        if (message.get('reason') == 'compiler-artifact' and message.get('executable') and
                message.get('profile', {}).get('test')):
            artifact = Path(message['executable']).resolve()
            if not artifact.is_file():
                sys.exit('Coverage test executable disappeared: ' + str(artifact))
            objects.add(str(artifact))
            unit_object_found |= bool({'lib', 'rlib', 'dylib', 'cdylib', 'staticlib', 'proc-macro'} & set(message.get('target', {}).get('kind', [])))
    if not unit_object_found:
        sys.exit('Exact unit-test executable absent from Cargo records: ' + name)
command = [llvm_cov, 'export', str(extension),
           '-instr-profile=' + str(Path(sys.argv[4]).resolve())]
for artifact in sorted(objects):
    command += ['-object', artifact]
result = subprocess.run(command, capture_output=True)
(run / 'coverage-native-first.json').write_bytes(result.stdout)
(run / 'export-diagnostics.json').write_text(json.dumps({
    'command': command, 'primary_object': str(extension), 'test_objects': sorted(objects),
    'exit_code': result.returncode, 'stderr': result.stderr.decode(errors='replace')
}, indent=2) + '\n')
if result.stderr:
    sys.stderr.write(result.stderr.decode(errors='replace'))
if result.returncode:
    sys.exit(result.returncode)
# Coverage-map mismatches cannot silently become a complete-coverage claim.
if b'mismatched' in result.stderr:
    sys.exit('LLVM coverage map mismatch: raw export retained; strict gate fails')
