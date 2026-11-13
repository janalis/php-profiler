#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
fault_directory="${CARGO_TARGET_DIR:-$PWD/target}/fault-tests"
mkdir -p "$fault_directory"
fault_directory=$(cd "$fault_directory" && pwd)
suffix=so
extension="${PROFILER_EXTENSION:-$PWD/target/debug/libphp_profiler.so}"
if [[ "$OSTYPE" == darwin* ]]; then
  suffix=dylib
  extension="${PROFILER_EXTENSION:-$PWD/target/debug/libphp_profiler.dylib}"
  "${CC:-cc}" -dynamiclib tests/native/clock-fault.c -o "$fault_directory/clock-fault.$suffix"
  injection=DYLD_INSERT_LIBRARIES
else
  "${CC:-cc}" -shared -fPIC tests/native/clock-fault.c -ldl -o "$fault_directory/clock-fault.$suffix"
  injection=LD_PRELOAD
fi
export PROFILER_EXTENSION="$extension"
for mode in cpu wall cpu_negative wall_negative cpu_negative_nsec wall_negative_nsec cpu_nsec wall_nsec cpu_overflow wall_overflow cpu_add_overflow wall_add_overflow; do
  env "$injection=$fault_directory/clock-fault.$suffix" "PROFILER_TEST_CLOCK=$mode" \
    php -n -d "extension=$extension" tests/native/clock-fault.php
done
for mode in cpu_boundary wall_boundary cpu_zero wall_zero; do
  env "$injection=$fault_directory/clock-fault.$suffix" "PROFILER_TEST_CLOCK=$mode" \
    php -n -d "extension=$extension" tests/native/clock-boundary.php
done
for mode in cpu wall; do
  env "$injection=$fault_directory/clock-fault.$suffix" \
    php -n -d "extension=$extension" tests/native/inactive-fiber-clocks.php "$mode"
  env "$injection=$fault_directory/clock-fault.$suffix" \
    php -n -d "extension=$extension" tests/native/late-output-clocks.php "$mode"
done
# An interrupted capture still needs real final measurements during cleanup.
php -n -d "extension=$extension" tests/native/fiber-failure-metrics.php
# Zend MM may be intentionally disabled; report unavailable memory explicitly.
env USE_ZEND_ALLOC=0 php -n -d "extension=$extension" tests/native/memory-unavailable.php
python3 scripts/runtime-dl-tests.py
