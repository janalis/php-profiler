#!/usr/bin/env bash
# Clang's gcov edge counters independently cover setjmp's second return.
set -euo pipefail
cd "$(dirname "$0")/.."
# shellcheck source=scripts/rustflags.sh
source scripts/rustflags.sh
export CC="${CC:-clang}"
llvm_cov="${LLVM_COV:-llvm-cov}"
mkdir -p "${CARGO_TARGET_DIR:-$PWD/target/clang-gcov}"
coverage_root=$(cd "${CARGO_TARGET_DIR:-$PWD/target/clang-gcov}" && pwd -P)
CARGO_TARGET_DIR="$(mktemp -d "$coverage_root/run-XXXXXXXX")"
export CARGO_TARGET_DIR
scripts/php-tool.sh scripts/coverage-snapshot.php "$PWD" > "$CARGO_TARGET_DIR/source-before.json"
finish_coverage_run() {
  final_status=$?
  trap - EXIT
  scripts/php-tool.sh scripts/coverage-snapshot.php "$PWD" > "$CARGO_TARGET_DIR/source-after.json" || final_status=1
  scripts/php-tool.sh scripts/coverage-run-manifest.php "$CARGO_TARGET_DIR" "$coverage_root" "$final_status" \
    --artifact reports/strict-summary.json --artifact clang.txt --artifact llvm-cov.txt --artifact flags.json \
    --artifact-directory reports || { if [[ "$final_status" == 0 ]]; then final_status=1; fi; }
  exit "$final_status"
}
trap finish_coverage_run EXIT
"$CC" --version > "$CARGO_TARGET_DIR/clang.txt"
"$llvm_cov" --version > "$CARGO_TARGET_DIR/llvm-cov.txt"
runtime_directory=$("$CC" --print-runtime-dir)
target_triple=$("$CC" -print-target-triple)
runtime="$runtime_directory/libclang_rt.profile-${target_triple%%-*}.a"
if [[ ! -f "$runtime" ]]; then
  echo "Native Clang coverage runtime missing for $target_triple" >&2
  exit 1
fi
runtime=$(cd -P "$(dirname "$runtime")" && printf '%s/%s' "$(pwd -P)" "$(basename "$runtime")")
append_rustflags -C "link-arg=$runtime" -C link-arg=-lc
export CFLAGS="${CFLAGS:+$CFLAGS }--coverage -fprofile-update=atomic"
unset PROFILER_C_GCOV PROFILER_C_COVERAGE PROFILER_ADAPTER_CONTRACT_TEST
export PROFILER_C_UNIT_BOUNDARIES=1
export PROFILER_SAMPLING_UNIT=1
scripts/php-tool.sh scripts/environment-receipt.php "$CARGO_TARGET_DIR/flags.json" CC CFLAGS CARGO_ENCODED_RUSTFLAGS \
  --value "runtime=$runtime" --sha256 "runtime_sha256=$runtime"
cargo build -p php-profiler --lib
if [[ "$OSTYPE" == darwin* ]]; then
  echo 'Clang-gcov bridge suite currently requires GNU test transports on Linux' >&2
  exit 1
fi
source scripts/link-native-faults.sh
export PROFILER_EXTENSION="$CARGO_TARGET_DIR/debug/libphp_profiler.so"
scripts/php-tests.sh
scripts/native-fault-tests.sh
scripts/php-tool.sh scripts/native-bailout-tests.php
PROFILER_SAMPLING_EXTENSION="$PROFILER_EXTENSION" scripts/php-tool.sh scripts/sampling-controller-tests.php
php -n -d "extension=$PROFILER_EXTENSION" tests/native/c-boundaries.php
mkdir -p "$CARGO_TARGET_DIR/reports"
while IFS= read -r notes; do
  (cd "$CARGO_TARGET_DIR/reports" && "$llvm_cov" gcov -b -c -i "$notes")
done < <(find "$CARGO_TARGET_DIR/debug/build" -path '*php-profiler*/out/*.gcno' -print)
scripts/php-tool.sh scripts/check-clang-gcov.php "$CARGO_TARGET_DIR/reports" --workspace-root "$PWD"
