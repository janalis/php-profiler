#!/usr/bin/env bash
# Requires matching Mull/Clang; test-time tooling only, never a runtime dependency.
set -euo pipefail
cd "$(dirname "$0")/.."
: "${MULL_PLUGIN:?Set MULL_PLUGIN to the installed matching Mull compiler plugin}"
export CC="${CC:-clang-22}"
export CARGO_TARGET_DIR="${CARGO_TARGET_DIR:-$PWD/target/c-mutants}"
mkdir -p "$CARGO_TARGET_DIR"
CARGO_TARGET_DIR="$(cd "$CARGO_TARGET_DIR" && pwd)"
export CARGO_TARGET_DIR
mkdir -p "$CARGO_TARGET_DIR/results"
report_directory=$(mktemp -d "$CARGO_TARGET_DIR/results/run-XXXXXXXX")
mkdir -p "$report_directory/provenance"
python3 scripts/coverage-snapshot.py "$PWD" > "$report_directory/provenance/source-before.json"
# Invoked indirectly by the EXIT trap; verified by provenance regression tests.
# shellcheck disable=SC2329
finish_mutation_run() {
  final_status=$?
  trap - EXIT
  python3 scripts/coverage-snapshot.py "$PWD" > "$report_directory/provenance/source-after.json" || final_status=1
  python3 - "$report_directory" "$final_status" <<'PYFINAL'
import hashlib, json, sys
from pathlib import Path
run = Path(sys.argv[1]); provenance = run / 'provenance'
before = json.loads((provenance / 'source-before.json').read_text())
after = json.loads((provenance / 'source-after.json').read_text())
changed = sorted(path for path in before.keys() | after.keys() if before.get(path) != after.get(path))
status = int(sys.argv[2]) or bool(changed)
manifest = {'exit_code': int(status), 'source_snapshot_matches': not changed, 'changed_inputs': changed,
            'elements_report_sha256': {str(path.relative_to(run)): hashlib.sha256(path.read_bytes()).hexdigest()
                                      for path in run.glob('*.json')}}
(provenance / 'run.json').write_text(json.dumps(manifest, indent=2) + '\n')
sys.exit(int(status))
PYFINAL
}
trap finish_mutation_run EXIT
export MULL_CONFIG="$CARGO_TARGET_DIR/mull-runtime-sources.json"
python3 - "$MULL_CONFIG" <<'PYCONFIG'
import json, re, sys
from pathlib import Path
sys.path.insert(0, 'scripts')
from runtime_sources import runtime_sources
root = Path.cwd()
Path(sys.argv[1]).write_text(json.dumps({'includePaths': [re.escape(str(path.relative_to(root))) + '$'
    for path in sorted(runtime_sources(root, 'c'))], 'mutators': ['cxx_all']}, indent=2) + '\n')
PYCONFIG
export CFLAGS="${CFLAGS:-} -fpass-plugin=$MULL_PLUGIN -g -grecord-command-line -fsanitize=array-bounds -fsanitize-trap=array-bounds"
export PROFILER_C_UNIT_BOUNDARIES=1
export PROFILER_SAMPLING_UNIT=1
cargo build -p php-profiler --lib
if [[ "$OSTYPE" != darwin* ]]; then source scripts/link-native-faults.sh; fi
suffix=so
if [[ "$OSTYPE" == darwin* ]]; then suffix=dylib; fi
export PROFILER_EXTENSION="$CARGO_TARGET_DIR/debug/libphp_profiler.$suffix"
python3 - "$report_directory" "$PROFILER_EXTENSION" <<'PY'
import hashlib, json, pathlib, sys
sys.path.insert(0, 'scripts')
from runtime_sources import runtime_sources
paths = ['crates/php-profiler/src/sampling.h', 'tests/native/sampling-unit.c',
         'tests/native/sampling-controller.php', 'scripts/sampling-controller-tests.py',
         'tests/native/bridge-unit.c',
         'tests/native/bailout-fault.c', 'scripts/c-mutation-tests.sh',
         'scripts/native-bailout-tests.py', 'scripts/native-fault-tests.sh',
         'tests/native/clock-fault.c', 'tests/native/clock-boundary.php',
         'Cargo.lock']
paths += [str(path.relative_to(pathlib.Path.cwd())) for path in sorted(runtime_sources(pathlib.Path.cwd(), 'c'))]
manifest = {path: hashlib.sha256(pathlib.Path(path).read_bytes()).hexdigest() for path in paths}
manifest['extension_binary'] = hashlib.sha256(pathlib.Path(sys.argv[2]).read_bytes()).hexdigest()
pathlib.Path(sys.argv[1], 'provenance/source-manifest.json').write_text(json.dumps(manifest, indent=2) + '\n')
PY
runner="${MULL_RUNNER:-mull-runner}"
help_file="$report_directory/provenance/runner-help.txt"
# Keep the EXIT provenance trap active; this tiny help artifact belongs to the run.
"$runner" --help > "$help_file"
for flag in test-program include-not-covered mutation-score-threshold allow-surviving; do
  if ! grep -q -- "--$flag" "$help_file"; then
    echo "Installed Mull lacks required --$flag capability" >&2
    exit 1
  fi
done
# The strict checker owns equivalent classification; raw outcomes remain untouched.
# Runner/instrumentation failures still propagate even if an Elements file exists.
python3 - "$report_directory" "$runner" <<'PYTOOLS'
import hashlib, json, os, pathlib, platform, shlex, shutil, subprocess, sys
plugin = pathlib.Path(os.environ['MULL_PLUGIN'])
record = {'compiler': os.environ['CC'],
          'compiler_version': subprocess.check_output([os.environ['CC'], '--version'], text=True),
          'mull_version': subprocess.check_output([sys.argv[2], '--version'], text=True),
          'plugin_sha256': hashlib.sha256(plugin.read_bytes()).hexdigest(),
          'compiler_flags': os.environ['CFLAGS'],
          'instrumentation_registry': json.loads(pathlib.Path(os.environ['MULL_CONFIG']).read_text()),
          'baseline_timeout_ms': int(os.environ.get('MULL_TIMEOUT_MS', '120000'))}
pathlib.Path(sys.argv[1], 'provenance/tools.json').write_text(json.dumps(record, indent=2) + '\n')
# Configuration-sensitive equivalents must never silently transfer across PHP,
# compiler, mutator, target or libc versions. Capture this before runner execution.
php = json.loads(subprocess.check_output([os.environ.get('PHP', 'php'), '-n', '-r',
    'echo json_encode(["php_zts"=>(bool)PHP_ZTS,"php_version_id"=>PHP_VERSION_ID,"php_int_size"=>PHP_INT_SIZE]);'], text=True))
compiler = pathlib.Path(shutil.which(os.environ['CC'])).resolve()
commands = {}
process = subprocess.Popen(['readelf', '--debug-dump=info', os.environ['PROFILER_EXTENSION']],
                           stdout=subprocess.PIPE, text=True)
for line in process.stdout:
    if 'DW_AT_producer' not in line or ' -grecord-command-line ' not in line:
        continue
    producer = line.split('): ', 1)[-1].strip()
    tokens = shlex.split(producer)
    for unit in ('bridge-unit.c', 'sampling-unit.c'):
        if tokens[-1].endswith('/' + unit):
            output = tokens.index('-o')
            del tokens[output:output + 2]  # Object-output paths carry no codegen meaning.
            if unit in commands and commands[unit] != tokens:
                raise SystemExit('C mutation gate: ambiguous native compile-command provenance')
            commands[unit] = tokens
if process.wait() or len(commands) != 2:
    raise SystemExit('C mutation gate: native compile-command provenance missing')
native_symbols = subprocess.check_output(['nm', '--defined-only', os.environ['PROFILER_EXTENSION']], text=True)
fixture_symbols = sorted({line.split()[-1] for line in native_symbols.splitlines()
                          if line.split() and line.split()[-1].startswith(('__wrap_', '__cyg_profile_'))})
context = {**php, 'native_compile_commands': commands, 'native_fixture_symbols': fixture_symbols,
           'rustc_version': subprocess.check_output(['rustc', '--version', '--verbose'], text=True),
           'rustflags': os.environ.get('RUSTFLAGS', ''),
           'cargo_encoded_rustflags': os.environ.get('CARGO_ENCODED_RUSTFLAGS', ''), 'system': platform.system(), 'machine': platform.machine(),
           'compiler_version': record['compiler_version'], 'mull_version': record['mull_version'],
           'compiler_sha256': hashlib.sha256(compiler.read_bytes()).hexdigest(),
           'plugin_sha256': record['plugin_sha256'], 'compiler_flags': shlex.split(record['compiler_flags'])}
# Read the already-loaded libc mapping; do not execute an untrusted ldd target.
maps = pathlib.Path('/proc/self/maps')
if maps.is_file():
    libraries = {line.split()[-1] for line in maps.read_text().splitlines()
                 if len(line.split()) >= 6 and pathlib.Path(line.split()[-1]).name == 'libc.so.6'}
    if len(libraries) == 1:
        context['libc_sha256'] = hashlib.sha256(pathlib.Path(libraries.pop()).read_bytes()).hexdigest()
pathlib.Path(sys.argv[1], 'provenance/equivalence-context.json').write_text(json.dumps(context, indent=2) + '\n')
PYTOOLS
python3 scripts/check-c-mutation-inventory.py --workspace-root "$PWD" \
  --extension "$PROFILER_EXTENSION" \
  --context "$report_directory/provenance/equivalence-context.json" \
  --output "$report_directory/provenance/ordinary-inventory"
result=0
"$runner" --include-not-covered --timeout "${MULL_TIMEOUT_MS:-120000}" \
  --workers "${MULL_WORKERS:-2}" --allow-surviving --mutation-score-threshold 0 \
  --test-program "$PWD/scripts/c-mutation-tests.sh" --reporters Elements \
  --report-dir "$report_directory" "$PROFILER_EXTENSION" || result=$?
python3 scripts/check-c-mutations.py "$report_directory" --workspace-root "$PWD" --equivalence-ledger tests/native/c-equivalents.json || result=1
exit "$result"
