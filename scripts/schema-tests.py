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

# Automatic lifecycle reasons and configured sampling use the same schemas.
import tempfile
with tempfile.TemporaryDirectory(prefix='profiler-auto-schema-') as directory:
    for sampled in ('0', '1'):
        auto_command = command[:4] + ['-d', 'php_profiler.mode=always', '-d',
            'php_profiler.output_dir=' + directory, '-d', 'php_profiler.sampling=' + sampled,
            '-r', 'function automatic_schema_leaf() {} automatic_schema_leaf();']
        result = subprocess.run(auto_command, capture_output=True, check=True)
        paths = list(pathlib.Path(directory).glob('*.json'))
        if not paths:
            assert sampled == '1' and os.environ.get('CI_PROFILER_REQUIRE_SAMPLING') != '1', result
            continue
        assert len(paths) == 1
        report = json.loads(paths[0].read_text())
        validators[report['schema_version']].validate(report)
        assert report['termination_reason'] == 'request_complete'
        paths[0].unlink()
print('automatic request reports also validate against published schemas')
