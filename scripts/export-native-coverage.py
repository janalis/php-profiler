#!/usr/bin/env python3
"""Strict cohort exports avoid unmangled symbol collisions across test/native builds."""
import hashlib
import json
from pathlib import Path
import shutil
import subprocess
import sys

from coverage_cohorts import merge_reports
from runtime_sources import runtime_sources


def cargo_objects(run, record):
    objects = set()
    unit_object_found = False
    for line in (run / record).read_text().splitlines():
        try:
            message = json.loads(line)
        except json.JSONDecodeError:
            continue
        if (message.get('reason') == 'compiler-artifact' and message.get('executable') and
                message.get('profile', {}).get('test')):
            artifact = Path(message['executable']).resolve()
            if not artifact.is_file():
                raise ValueError('Coverage test executable disappeared: ' + str(artifact))
            objects.add(str(artifact))
            unit_object_found |= bool({'lib', 'rlib', 'dylib', 'cdylib', 'staticlib', 'proc-macro'} & set(message.get('target', {}).get('kind', [])))
    if not unit_object_found:
        raise ValueError('Exact unit-test executable absent from Cargo records: ' + record)
    return sorted(objects)


def strict_export(llvm_cov, objects, profile, output, diagnostics):
    command = [llvm_cov, 'export', objects[0], '-instr-profile=' + str(profile)]
    for artifact in objects[1:]:
        command += ['-object', artifact]
    result = subprocess.run(command, capture_output=True)
    output.write_bytes(result.stdout)
    diagnostics.write_text(json.dumps({'command': command, 'objects': objects,
        'exit_code': result.returncode, 'stderr': result.stderr.decode(errors='replace')}, indent=2) + '\n')
    if result.returncode:
        raise subprocess.CalledProcessError(result.returncode, command, result.stdout, result.stderr)
    if result.stderr:
        raise ValueError('LLVM cohort export failed or emitted diagnostics; raw evidence retained: ' + str(diagnostics))
    return json.loads(result.stdout)


def demangled_names(reports):
    executable = shutil.which('llvm-cxxfilt') or shutil.which('c++filt')
    if not executable:
        raise ValueError('A Rust-capable LLVM/GNU c++filt demangler is required')
    names = sorted({function['name'] for report in reports for dataset in report['data'] for function in dataset['functions']})
    if any('\n' in name for name in names):
        raise ValueError('Unsupported newline in coverage function name')
    version = subprocess.check_output([executable, '--version'], text=True)
    command = [executable, '-n']
    if 'GNU' in version:
        command.append('-i')  # Drop crate disambiguators, retaining generic type arguments.
    output = subprocess.check_output(command, input='\n'.join(names) + '\n', text=True).splitlines()
    if len(output) != len(names):
        raise ValueError('Demangler changed function record cardinality')
    result = dict(zip(names, output))
    if any(name.startswith('_R') and result[name] == name for name in names):
        raise ValueError('Demangler does not support Rust v0 names; install a current LLVM demangler')
    return result


def main():
    llvm_cov, run, extension = sys.argv[1], Path(sys.argv[2]), str(Path(sys.argv[3]).resolve())
    build = Path(sys.argv[4]).resolve().parent
    objects = {'core': cargo_objects(run, 'core-tests.jsonl'),
               'adapter': cargo_objects(run, 'adapter-tests.jsonl'), 'native': [extension]}
    directory = run / 'cohorts'
    directory.mkdir()
    reports = []
    receipts = []
    for name, artifacts in objects.items():
        profiles = sorted(build.glob(name + '-*.profraw'))
        if not profiles:
            raise ValueError('No actual raw profiles recorded for cohort: ' + name)
        manifest = directory / (name + '-profiles.txt')
        if any('\n' in str(path) for path in profiles):
            raise ValueError('Unsupported newline in raw profile path')
        manifest.write_text('\n'.join(map(str, profiles)) + '\n')
        profile = directory / (name + '.profdata')
        subprocess.run([str(Path(llvm_cov).with_name('llvm-profdata')), 'merge', '--sparse',
                        '--failure-mode=any', '--input-files=' + str(manifest), '-o', str(profile)], check=True)
        output = directory / (name + '.json')
        reports.append(strict_export(llvm_cov, artifacts, profile, output, directory / (name + '-diagnostics.json')))
        receipts.append({'cohort': name, 'export': str(output), 'sha256': hashlib.sha256(output.read_bytes()).hexdigest(),
                         'objects': {path: hashlib.sha256(Path(path).read_bytes()).hexdigest() for path in artifacts},
                         'raw_profiles': {str(path): hashlib.sha256(path.read_bytes()).hexdigest() for path in profiles}})
    canonical = demangled_names(reports)
    merged = merge_reports(reports, runtime_sources(Path.cwd(), 'rust'), canonical.__getitem__)
    merged['cohorts'] = receipts
    (run / 'coverage-native-first.json').write_text(json.dumps(merged) + '\n')
    (run / 'export-diagnostics.json').write_text(json.dumps({'exit_code': 0, 'method': merged['method'],
        'cohorts': receipts, 'stderr': ''}, indent=2) + '\n')


if __name__ == '__main__':
    try:
        main()
    except subprocess.CalledProcessError as error:
        raise SystemExit(error.returncode)
