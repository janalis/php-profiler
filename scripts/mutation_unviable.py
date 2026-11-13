"""Fail closed on build failures that are not explicit Rust compile rejection."""
import hashlib
from pathlib import Path
import re

ANSI = re.compile(r'\x1b\[[0-?]*[ -/]*[@-~]')
# Explicit source-language diagnostic families observed in real proposals.
# New compiler errors require review; loader/linker/cache failures never qualify.
SOURCE_ERRORS = {'E0080', 'E0271', 'E0277', 'E0308', 'E0599', 'E0790'}
DIAGNOSTIC = re.compile(r'^error(?:\[(E[0-9]{4})\])?: (.*)$', re.MULTILINE)


def classify_log(text):
    # Ignore the printed mutation diff, which can itself contain string literals.
    command = re.search(r'^\*\*\* .*(?:cargo|nextest).*$', text, re.MULTILINE)
    if command is None:
        return {'classification': 'infrastructure_or_unknown', 'reason': 'Missing build command boundary'}
    diagnostics = DIAGNOSTIC.findall(ANSI.sub('', text[command.end():]))
    genuine = []
    unknown = []
    for code, message in diagnostics:
        if code in SOURCE_ERRORS:
            genuine.append(code)
        elif code:
            unknown.append(code + ': ' + message)
        elif message == '`||` operators are not supported in let chain conditions':
            genuine.append('let_chain_syntax')
        elif message.startswith('could not compile `') and 'due to ' in message:
            continue
        elif message.startswith('command `') and message.endswith('exited with code 101'):
            continue
        else:
            unknown.append(message)
    return {'classification': 'compiler_rejected' if genuine and not unknown else 'infrastructure_or_unknown',
            'diagnostics': genuine, 'unrecognized_errors': unknown}


def audit(outcomes, directory):
    directory = Path(directory).resolve()
    records = []
    for outcome in outcomes['outcomes']:
        if outcome['summary'] != 'Unviable':
            continue
        path = (directory / outcome['log_path']).resolve()
        if not path.is_relative_to(directory):
            raise ValueError('Mutation log escapes its owned result directory')
        content = path.read_bytes()
        result = classify_log(content.decode('utf-8', errors='replace'))
        build = outcome.get('phase_results', [])
        if (len(build) != 1 or build[0].get('phase') != 'Build' or
                build[0].get('process_status') != {'Failure': 101}):
            result['classification'] = 'infrastructure_or_unknown'
            result['reason'] = 'Unviable outcome has missing or unexpected phases'
        records.append({'scenario': outcome['scenario'], 'log_path': outcome['log_path'],
                        'sha256': hashlib.sha256(content).hexdigest(), **result})
    if len(records) != outcomes['unviable']:
        raise ValueError('Unviable outcome count disagrees with records')
    return {'compiler_rejected': sum(r['classification'] == 'compiler_rejected' for r in records),
            'infrastructure_or_unknown': sum(r['classification'] != 'compiler_rejected' for r in records),
            'records': records}
