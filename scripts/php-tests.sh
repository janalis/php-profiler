#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
extension="${PROFILER_EXTENSION:-$PWD/target/debug/libphp_profiler.so}"
if [[ "$OSTYPE" == darwin* && -z "${PROFILER_EXTENSION:-}" ]]; then extension="$PWD/target/debug/libphp_profiler.dylib"; fi
php_command=(php)
if [[ -n "${PROFILER_ASAN_RUNTIME:-}" ]]; then
  if [[ "$OSTYPE" == darwin* ]]; then
    php_command=(env "DYLD_INSERT_LIBRARIES=$PROFILER_ASAN_RUNTIME" php)
  else
    php_command=(env "LD_PRELOAD=$PROFILER_ASAN_RUNTIME" php)
  fi
fi
read -r -a extra_args <<< "${PHP_EXTRA_ARGS:-}"
for module in pdo_sqlite curl pcntl; do
  if ! "${php_command[@]}" -n ${extra_args[@]+"${extra_args[@]}"} -r "exit(extension_loaded('$module') ? 0 : 1);"; then
    extra_args+=(-d "extension=$module")
  fi
done
for fixture in tests/php/*.php; do
  "${php_command[@]}" -n ${extra_args[@]+"${extra_args[@]}"} -d "extension=$extension" "$fixture"
done
# Flat high-cardinality graphs remain inexpensive enough for ordinary regression runs.
# The maximal-name, deep-timeline file boundary has a separate dedicated CI command.
for mode in named generated classes; do
  "${php_command[@]}" -n ${extra_args[@]+"${extra_args[@]}"} -d "extension=$extension" tests/native/large-profiles.php "$mode"
done
# Exercise simultaneous independent processes and atomic publication paths.
pids=()
for ((index=0; index<4; ++index)); do
  "${php_command[@]}" -n ${extra_args[@]+"${extra_args[@]}"} -d "extension=$extension" tests/php/runtime.php &
  pids+=("$!")
done
failed=0
for pid in "${pids[@]}"; do
  wait "$pid" || failed=1
done
if [[ "$failed" != 0 ]]; then exit "$failed"; fi
PROFILER_EXTENSION="$extension" python3 scripts/termination-tests.py

PROFILER_EXTENSION="$extension" python3 scripts/test-report-oom.py
PROFILER_EXTENSION="$extension" python3 scripts/basedir-tests.py
PROFILER_EXTENSION="$extension" python3 scripts/metadata-tests.py
"${php_command[@]}" -n -d "extension=$extension" tests/native/session-handle-safety.php
"${php_command[@]}" -n -d "extension=$extension" tests/native/runtime-state.php

"${php_command[@]}" -n -d "extension=$extension" tests/native/root-directory.php
