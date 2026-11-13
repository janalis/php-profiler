#!/usr/bin/env bash
# Clang's gcov edge counters independently cover setjmp's second return.
set -euo pipefail
cd "$(dirname "$0")/.."
export CC="${CC:-clang}"
llvm_cov="${LLVM_COV:-llvm-cov}"
coverage_root=$(python3 -c 'from pathlib import Path; import sys; print(Path(sys.argv[1]).resolve())' "${CARGO_TARGET_DIR:-$PWD/target/clang-gcov}")
mkdir -p "$coverage_root"
CARGO_TARGET_DIR="$(mktemp -d "$coverage_root/run-XXXXXXXX")"
export CARGO_TARGET_DIR
python3 scripts/coverage-snapshot.py "$PWD" > "$CARGO_TARGET_DIR/source-before.json"
finish_coverage_run() {
  final_status=$?
  trap - EXIT
  python3 scripts/coverage-snapshot.py "$PWD" > "$CARGO_TARGET_DIR/source-after.json" || final_status=1
  python3 scripts/coverage-run-manifest.py "$CARGO_TARGET_DIR" "$coverage_root" "$final_status" \
    --artifact reports/strict-summary.json --artifact clang.txt --artifact llvm-cov.txt --artifact flags.json \
    --artifact-directory reports || { if [[ "$final_status" == 0 ]]; then final_status=1; fi; }
  exit "$final_status"
}
trap finish_coverage_run EXIT
"$CC" --version > "$CARGO_TARGET_DIR/clang.txt"
"$llvm_cov" --version > "$CARGO_TARGET_DIR/llvm-cov.txt"
runtime_directory=$("$CC" --print-runtime-dir)
target_triple=$("$CC" -print-target-triple)
runtime=$(python3 - "$runtime_directory" "$target_triple" <<'PY'
from pathlib import Path
import sys
architecture = sys.argv[2].split('-')[0]
runtime = Path(sys.argv[1]) / ('libclang_rt.profile-' + architecture + '.a')
if not runtime.is_file():
    sys.exit('Native Clang coverage runtime missing for ' + sys.argv[2])
print(runtime.resolve())
PY
)
CARGO_ENCODED_RUSTFLAGS="$(python3 - "$runtime" <<'PY'
import os, shlex, sys
flags = os.environ.get('CARGO_ENCODED_RUSTFLAGS')
flags = flags.split(chr(31)) if flags else shlex.split(os.environ.get('RUSTFLAGS', ''))
print(chr(31).join(flags + ['-C', 'link-arg=' + sys.argv[1], '-C', 'link-arg=-lc']))
PY
)"
export CARGO_ENCODED_RUSTFLAGS
export CFLAGS="${CFLAGS:+$CFLAGS }--coverage -fprofile-update=atomic"
unset PROFILER_C_GCOV PROFILER_C_COVERAGE PROFILER_ADAPTER_CONTRACT_TEST
export PROFILER_C_UNIT_BOUNDARIES=1
export PROFILER_SAMPLING_UNIT=1
python3 - "$CARGO_TARGET_DIR/flags.json" "$runtime" <<'PYFLAGS'
import hashlib, json, os, sys
from pathlib import Path
runtime = Path(sys.argv[2])
with open(sys.argv[1], 'w') as output:
    json.dump({'CC': os.environ['CC'], 'CFLAGS': os.environ['CFLAGS'],
               'CARGO_ENCODED_RUSTFLAGS': os.environ['CARGO_ENCODED_RUSTFLAGS'],
               'runtime': str(runtime), 'runtime_sha256': hashlib.sha256(runtime.read_bytes()).hexdigest()}, output, indent=2)
    output.write(chr(10))
PYFLAGS
cargo build -p php-profiler --lib
if [[ "$OSTYPE" == darwin* ]]; then
  echo 'Clang-gcov bridge suite currently requires GNU test transports on Linux' >&2
  exit 1
fi
source scripts/link-native-faults.sh
export PROFILER_EXTENSION="$CARGO_TARGET_DIR/debug/libphp_profiler.so"
scripts/php-tests.sh
scripts/native-fault-tests.sh
python3 scripts/native-bailout-tests.py
PROFILER_SAMPLING_EXTENSION="$PROFILER_EXTENSION" python3 scripts/sampling-controller-tests.py
php -n -d "extension=$PROFILER_EXTENSION" tests/native/c-boundaries.php
mkdir -p "$CARGO_TARGET_DIR/reports"
while IFS= read -r notes; do
  (cd "$CARGO_TARGET_DIR/reports" && "$llvm_cov" gcov -b -c -i "$notes")
done < <(find "$CARGO_TARGET_DIR/debug/build" -path '*php-profiler*/out/*.gcno' -print)
python3 scripts/check-clang-gcov.py "$CARGO_TARGET_DIR/reports" --workspace-root "$PWD"
