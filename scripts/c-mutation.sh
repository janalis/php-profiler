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
scripts/php-tool.sh scripts/coverage-snapshot.php "$PWD" > "$report_directory/provenance/source-before.json"
# Invoked indirectly by the EXIT trap; verified by provenance regression tests.
# shellcheck disable=SC2329
finish_mutation_run() {
  final_status=$?
  trap - EXIT
  scripts/php-tool.sh scripts/coverage-snapshot.php "$PWD" > "$report_directory/provenance/source-after.json" || final_status=1
  scripts/php-tool.sh scripts/c-mutation-provenance.php finish "$report_directory" "$final_status"
}
trap finish_mutation_run EXIT
export MULL_CONFIG="$CARGO_TARGET_DIR/mull-runtime-sources.json"
scripts/php-tool.sh scripts/c-mutation-provenance.php config "$MULL_CONFIG"
export CFLAGS="${CFLAGS:-} -fpass-plugin=$MULL_PLUGIN -g -grecord-command-line -fsanitize=array-bounds -fsanitize-trap=array-bounds"
export PROFILER_C_UNIT_BOUNDARIES=1
export PROFILER_SAMPLING_UNIT=1
cargo build -p php-profiler --lib
if [[ "$OSTYPE" != darwin* ]]; then source scripts/link-native-faults.sh; fi
suffix=so
if [[ "$OSTYPE" == darwin* ]]; then suffix=dylib; fi
export PROFILER_EXTENSION="$CARGO_TARGET_DIR/debug/libphp_profiler.$suffix"
scripts/php-tool.sh scripts/c-mutation-provenance.php manifest "$report_directory" "$PROFILER_EXTENSION"
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
scripts/php-tool.sh scripts/c-mutation-provenance.php context "$report_directory" "$runner"
scripts/php-tool.sh scripts/check-c-mutation-inventory.php --workspace-root "$PWD" \
  --extension "$PROFILER_EXTENSION" \
  --context "$report_directory/provenance/equivalence-context.json" \
  --output "$report_directory/provenance/ordinary-inventory"
result=0
"$runner" --include-not-covered --timeout "${MULL_TIMEOUT_MS:-120000}" \
  --workers "${MULL_WORKERS:-2}" --allow-surviving --mutation-score-threshold 0 \
  --test-program "$PWD/scripts/c-mutation-tests.sh" --reporters Elements \
  --report-dir "$report_directory" "$PROFILER_EXTENSION" || result=$?
scripts/php-tool.sh scripts/check-c-mutations.php "$report_directory" --workspace-root "$PWD" --equivalence-ledger tests/native/c-equivalents.json || result=1
exit "$result"
