#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
: "${PROFILER_EXTENSION:?Set the instrumented extension path}"
# Every Mull worker loads the same immutable extension, while each test-program
# invocation owns the interposers that its subprocesses compile and load.
extension_directory=$(cd "$(dirname "$PROFILER_EXTENSION")" && pwd)
extension_name=$(basename "$PROFILER_EXTENSION")
PROFILER_EXTENSION="$extension_directory/$extension_name"
export PROFILER_EXTENSION
scratch=$(mktemp -d "${TMPDIR:-/tmp}/profiler-c-mutation.XXXXXXXX")
export CARGO_TARGET_DIR="$scratch"
# Invoked indirectly by the EXIT trap; preserve the original failed assertion.
# shellcheck disable=SC2329
cleanup_mutation_scratch() {
  result=$?
  trap - EXIT
  rm -rf "$scratch" || result=1
  exit "$result"
}
trap cleanup_mutation_scratch EXIT
trap 'exit 143' TERM
trap 'exit 130' INT
echo "C mutation scratch: $scratch"

# Exercise native contracts before broader PHP workloads, without filtering any
# test. A failing mutation can stop promptly without rebuilding shared helpers.
python3 - <<'PYPHP'
import os
import sys
sys.path.insert(0, 'scripts')
from process_groups import ProcessGroups
with ProcessGroups() as processes:
    processes.run([os.environ.get('PHP', 'php'), '-n', '-d',
                   'extension=' + os.environ['PROFILER_EXTENSION'],
                   'tests/native/c-boundaries.php'],
                  timeout=30, check=True, env=os.environ.copy())
PYPHP
PROFILER_SAMPLING_EXTENSION="$PROFILER_EXTENSION" python3 scripts/sampling-controller-tests.py
python3 scripts/native-bailout-tests.py
scripts/native-fault-tests.sh
scripts/php-tests.sh
