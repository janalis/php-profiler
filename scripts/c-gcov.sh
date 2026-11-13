#!/usr/bin/env bash
# GCC handles setjmp/longjmp arcs that Clang's source counters misattribute.
set -euo pipefail
cd "$(dirname "$0")/.."
export CC="${CC:-gcc}"
export CARGO_TARGET_DIR="${CARGO_TARGET_DIR:-$PWD/target/c-gcov}"
export PROFILER_C_GCOV=1
cargo build -p php-profiler --lib
# Link-time fault injection only in this test artifact. Release builds never
# contain the wrappers or read their fault-selection environment variable.
source scripts/link-native-faults.sh
export PROFILER_EXTENSION="$CARGO_TARGET_DIR/debug/libphp_profiler.so"
scripts/php-tests.sh
scripts/native-fault-tests.sh
PROFILER_LINKED_BAILOUT_FAULT=1 python3 scripts/native-bailout-tests.py
php -n -d "extension=$PROFILER_EXTENSION" tests/native/c-boundaries.php
mkdir -p "$CARGO_TARGET_DIR/reports"
while IFS= read -r notes; do
  (cd "$CARGO_TARGET_DIR/reports" && "${GCOV:-gcov}" --json-format -b -c "$notes")
done < <(find "$CARGO_TARGET_DIR/debug/build" -path '*php-profiler*/out/*bridge.gcno' -print)
python3 scripts/check-gcov.py "$CARGO_TARGET_DIR/reports"
