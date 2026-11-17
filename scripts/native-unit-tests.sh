#!/usr/bin/env bash
# Runs the C unit-boundary suite that CI otherwise reaches only through mutation
# and coverage, in its own debug build so the ordinary extension stays untouched.
set -euo pipefail
cd "$(dirname "$0")/.."
export CARGO_TARGET_DIR="${PROFILER_NATIVE_UNIT_TARGET:-$PWD/target/native-units}"
export PROFILER_C_UNIT_BOUNDARIES=1 PROFILER_SAMPLING_UNIT=1
export CC="${CC:-cc}"
cargo build --locked -p php-profiler --lib
suffix=so
if [[ "$OSTYPE" == darwin* ]]; then suffix=dylib; fi
export PROFILER_EXTENSION="$CARGO_TARGET_DIR/debug/libphp_profiler.$suffix"
if [[ "$OSTYPE" == darwin* ]]; then
  # macOS cannot --wrap the Zend helpers that c-boundaries.php needs beyond its
  # first contract, so run that contract alone; the bailout modes run in full.
  php -n -d "extension=$PROFILER_EXTENSION" -r \
    'if (!profiler_test_automatic_transports()) { fwrite(STDERR, "automatic C transport contract failed\n"); exit(1); }'
  printf 'automatic C transport contract passed\n'
else
  source scripts/link-native-faults.sh
  php -n -d "extension=$PROFILER_EXTENSION" tests/native/c-boundaries.php
fi
python3 scripts/native-bailout-tests.py
