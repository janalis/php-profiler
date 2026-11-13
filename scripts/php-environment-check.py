#!/usr/bin/env python3
"""Verify an actual PHP/SDK installation against the requested CI matrix label."""
import argparse
import json
import os
import subprocess
import sys

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--version', required=True, help='Expected PHP major.minor')
parser.add_argument('--zts', required=True, choices=('nts', 'ts'))
args = parser.parse_args()
php = os.environ.get('PHP', 'php')
config = os.environ.get('PHP_CONFIG', 'php-config')
metadata = json.loads(subprocess.check_output([php, '-n', '-r',
    "echo json_encode(['php_version'=>PHP_VERSION,'zts'=>(bool)PHP_ZTS,'sapi'=>PHP_SAPI],JSON_THROW_ON_ERROR);"], text=True))
header_version = subprocess.check_output([config, '--version'], text=True).strip()
actual_minor = '.'.join(metadata['php_version'].split('.')[:2])
if actual_minor != args.version:
    sys.exit(f'PHP minor label mismatch: requested {args.version}, actual {metadata["php_version"]}')
if type(metadata['zts']) is not bool or metadata['zts'] != (args.zts == 'ts'):
    sys.exit(f'PHP ZTS label mismatch: requested {args.zts}, actual {metadata["zts"]}')
if metadata['sapi'] != 'cli':
    sys.exit('Environment preflight requires the CLI companion binary')
if header_version != metadata['php_version']:
    sys.exit(f'php-config version mismatch: headers {header_version}, runtime {metadata["php_version"]}')
metadata.update(expected_minor=args.version, expected_zts=args.zts, php_config_version=header_version)
print(json.dumps(metadata, sort_keys=True))
