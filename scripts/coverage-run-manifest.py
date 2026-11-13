#!/usr/bin/env python3
"""Record immutable coverage inputs/artifacts and fail closed on source drift."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import sys
import tempfile

parser = argparse.ArgumentParser()
parser.add_argument('run', type=Path)
parser.add_argument('parent', type=Path)
parser.add_argument('status', type=int)
parser.add_argument('--artifact', action='append', default=[])
parser.add_argument('--artifact-directory', action='append', default=[])
args = parser.parse_args()
run, parent, status = args.run.resolve(), args.parent.resolve(), args.status
before = json.loads((run / 'source-before.json').read_text())
after = json.loads((run / 'source-after.json').read_text())
changed = sorted(key for key in before.keys() | after.keys() if before.get(key) != after.get(key))
missing = [name for name in args.artifact if not (run / name).is_file()]
artifacts = {run / name for name in args.artifact if (run / name).is_file()}
for name in args.artifact_directory:
    directory = run / name
    if not directory.is_dir():
        missing.append(name)
    else:
        artifacts.update(p for p in directory.rglob('*') if p.is_file())
if (changed or missing) and status == 0:
    status = 1
manifest = {
    'schema_version': 1, 'run_directory': str(run), 'exit_code': status,
    'source_snapshot_matches': not changed, 'changed_inputs': changed,
    'missing_artifacts': missing,
    'artifact_sha256': {str(p.relative_to(run)): hashlib.sha256(p.read_bytes()).hexdigest() for p in sorted(artifacts)},
    'fresh_object_directory': str(run / 'build') if (run / 'build').is_dir() else str(run),
}
(run / 'run.json').write_text(json.dumps(manifest, indent=2) + '\n')
with tempfile.NamedTemporaryFile(mode='w', dir=parent, prefix='.latest-', suffix='.json', delete=False) as output:
    json.dump(manifest, output, indent=2)
    output.write('\n')
    temporary = output.name
os.replace(temporary, parent / 'latest.json')
if changed:
    print('Coverage inputs changed: ' + ', '.join(changed), file=sys.stderr)
if missing:
    print('Coverage artifacts missing: ' + ', '.join(missing), file=sys.stderr)
print('Coverage run manifest: ' + str(run / 'run.json'))
sys.exit(status)
