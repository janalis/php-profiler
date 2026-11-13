#!/usr/bin/env python3
"""Validate real native JSON reports against the published schema; no remote refs."""
import json
import os
import pathlib
import subprocess
import sys
import jsonschema

root = pathlib.Path(__file__).resolve().parent.parent
validators = {}
for version in (1, 2):
    schema = json.loads((root / f'schema/report-v{version}.json').read_text())
    jsonschema.Draft202012Validator.check_schema(schema)
    validators[version] = jsonschema.Draft202012Validator(schema)
command = [os.environ.get('PHP', 'php'), '-n', '-d',
           'extension=' + os.environ['PROFILER_EXTENSION'], 'tests/native/schema-reports.php']
reports = json.loads(subprocess.check_output(command, cwd=root, text=True))
for index, report in enumerate(reports):
    version = report.get('schema_version')
    if type(version) is not int or version not in validators:
        sys.exit(f'report {index}: unsupported schema version {version!r}')
    validator = validators[version]
    errors = sorted(validator.iter_errors(report), key=lambda error: str(error.path))
    if errors:
        sys.exit(f'report {index}: ' + '\n'.join(str(error) for error in errors))
assert len(reports) in (3, 5)
if os.environ.get('CI_PROFILER_REQUIRE_SAMPLING') == '1':
    assert len(reports) == 5, 'supported CI platform must validate native v2 sampling reports'
assert sum(report['schema_version'] == 1 for report in reports) == 3
assert sum(report['schema_version'] == 2 for report in reports) == len(reports) - 3
assert {report['status'] for report in reports} >= {'complete', 'truncated'}
print(f'published JSON Schemas validate all {len(reports)} real native reports (versions {sorted({r["schema_version"] for r in reports})})')
