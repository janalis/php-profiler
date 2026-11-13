#!/usr/bin/env bash
set -euo pipefail
# Freeze the runner too: Bash can otherwise read an edited tail after a long loop.
if [[ -z "${PROFILER_FROZEN_RUNNER:-}" ]]; then
  workspace="${PROFILER_WORKSPACE_ROOT:-$(cd "$(dirname "$0")/.." && pwd)}"
  compatibility="${PROFILER_COMPATIBILITY_DIR:-$workspace/target/compatibility}"
  mkdir -p "$compatibility"
  runner=$(mktemp "$compatibility/runner-XXXXXXXX")
  cp "$0" "$runner"
  result=0
  PROFILER_FROZEN_RUNNER=1 PROFILER_WORKSPACE_ROOT="$workspace" bash "$runner" || result=$?
  exit "$result"
fi
cd "$PROFILER_WORKSPACE_ROOT"
compatibility="${PROFILER_COMPATIBILITY_DIR:-$PWD/target/compatibility}"
mkdir -p "$compatibility"
# Freeze one source snapshot so every matrix cell validates identical inputs.
snapshot=$(mktemp -d "$compatibility/source-XXXXXXXX")
# Keep the immutable source/runner receipt for every cell, including failures.
python3 - "$snapshot" <<'PYCOPYROOT'
import pathlib, shutil, sys
sys.path.insert(0, 'scripts')
from validation_inputs import snapshot
root = pathlib.Path.cwd()
destination = pathlib.Path(sys.argv[1])
for name in snapshot(root):
    source = root / name
    target = destination / name
    target.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(source, target)
PYCOPYROOT
python3 - "$snapshot" <<'PYCOPY'
import pathlib, shutil, sys
snapshot = pathlib.Path(sys.argv[1])
for name in ('crates', 'scripts', 'tests', 'vendor', 'schema', '.github'):
    shutil.copytree(name, snapshot / name, dirs_exist_ok=True,
                    ignore=shutil.ignore_patterns('target', '__pycache__', 'mutants.out', '.DS_Store'))
PYCOPY
python3 - "$snapshot" "$compatibility/source-sha256.json" <<'PYHASH'
import hashlib, json, pathlib, sys
root = pathlib.Path(sys.argv[1])
files = {str(p.relative_to(root)): hashlib.sha256(p.read_bytes()).hexdigest()
         for p in root.rglob('*') if p.is_file() and '__pycache__' not in p.parts}
pathlib.Path(sys.argv[2]).write_text(json.dumps(files, sort_keys=True, indent=2))
PYHASH
run_fpm_suite() {
  docker run --rm "$image" python3 scripts/automatic-tests.py --fpm | tee "$compatibility/${version}-${sapi}-automatic.log"
  docker run --rm "$image" python3 scripts/fpm-test.py | tee "$compatibility/${version}-${sapi}-fpm.log"
  docker run --rm "$image" python3 scripts/sampling-jit-tests.py --fpm \
    --extension /profiler/target/release/libphp_profiler.so --expected-php "$version" --expected-zts nts \
    | tee "$compatibility/${version}-${sapi}-sampling-jit-reuse.json"
  docker run --rm "$image" python3 scripts/fpm-recovery-test.py | tee "$compatibility/${version}-${sapi}-fpm-recovery.json"
  docker run --rm "$image" python3 scripts/fpm-vendor-ownership-test.py | tee "$compatibility/${version}-${sapi}-fpm-vendor-ownership.json"
  opcache_environment=()
  if ! docker run --rm "$image" php -n -r 'exit(extension_loaded("Zend OPcache") ? 0 : 1);'; then
    opcache_environment=(-e PROFILER_FPM_OPCACHE_EXTENSION=opcache)
  fi
  for fpm_mode in disabled opcache jit preload preload-jit; do
    jit_requirement=()
    if [[ "$fpm_mode" == jit || "$fpm_mode" == preload-jit ]]; then jit_requirement=(--require-jit); fi
    docker run --rm ${opcache_environment[@]+"${opcache_environment[@]}"} "$image" python3 scripts/fpm-closure-tests.py \
      --mode "$fpm_mode" --expected-php "$version" --expected-zts nts ${jit_requirement[@]+"${jit_requirement[@]}"} | tee "$compatibility/${version}-${sapi}-closure-${fpm_mode}.json"
  done
  docker run --rm ${opcache_environment[@]+"${opcache_environment[@]}"} "$image" python3 scripts/fpm-closure-tests.py \
    --mode preload-jit --require-jit --workers 4 --expected-php "$version" --expected-zts nts | tee "$compatibility/${version}-${sapi}-closure-preload-jit-four-workers.json"
}
for version in ${PHP_VERSIONS:-8.3 8.4 8.5}; do
  for sapi in ${PHP_SAPIS:-cli zts fpm}; do
    image="${PROFILER_IMAGE_PREFIX:-local-php-profiler}:${version}-${sapi}"
    docker build -f "$snapshot/tests/docker/Dockerfile" --build-arg "PHP_IMAGE=php:${version}-${sapi}-bookworm" -t "$image" "$snapshot"
    expected_zts=nts
    if [[ "$sapi" == zts ]]; then expected_zts=ts; fi
    docker run --rm "$image" python3 scripts/php-environment-check.py --version "$version" --zts "$expected_zts" \
      | tee "$compatibility/${version}-${sapi}-runtime.json"
    if [[ "${PROFILER_TEST_MODE:-full}" != overlay ]]; then
      docker run --rm "$image" python3 scripts/test-vendored-exception.py | tee "$compatibility/${version}-${sapi}-vendor-exception.log"
    fi
    if [[ "${PROFILER_TEST_MODE:-full}" == full || "${PROFILER_TEST_MODE:-full}" == final ]]; then
      # Dedicated once per cell, never part of the repeated native mutation runner.
      docker run --rm "$image" php -n -d extension=/profiler/target/release/libphp_profiler.so \
        tests/native/large-profile-boundary.php | tee "$compatibility/${version}-${sapi}-large-profile-boundary.log"
      # The tracing-JIT/coobserver regression requires a JIT-enabled PHP build;
      # all nine official image companions provide one, unlike some host SDKs.
      docker run --rm "$image" python3 scripts/jit-megamorphic-tests.py \
        --extension /profiler/target/release/libphp_profiler.so \
        | tee "$compatibility/${version}-${sapi}-megamorphic-jit.json"
      docker run --rm "$image" python3 scripts/sampling-jit-tests.py \
        --extension /profiler/target/release/libphp_profiler.so --expected-php "$version" --expected-zts "$expected_zts" \
        | tee "$compatibility/${version}-${sapi}-sampling-jit-cli-companion.json"
    fi
    if [[ "${PROFILER_TEST_MODE:-full}" == delta || "${PROFILER_TEST_MODE:-full}" == overlay || "${PROFILER_TEST_MODE:-full}" == final ]]; then
      if [[ "${PROFILER_TEST_MODE:-full}" == final ]]; then
        docker run --rm "$image" bash scripts/php-tests.sh | tee "$compatibility/${version}-${sapi}-native-final.log"
        docker run --rm "$image" bash scripts/native-fault-tests.sh | tee "$compatibility/${version}-${sapi}-faults-final.log"
        if [[ "$sapi" == fpm ]]; then
          run_fpm_suite
        fi
        printf '%s %s\n' "$version" "$sapi" >> "$compatibility/cells-passed.txt"
        continue
      fi
      fixtures='adapter_bounds path_bytes extension_controls generated_symbols byte_identifiers anonymous_path_bytes closure_sites fiber_heap request_peak'
      if [[ "${PROFILER_TEST_MODE:-full}" == overlay ]]; then fixtures='capacity adapter_bounds worker session'; fi
      docker run --rm -e "PROFILER_KEY_FIXTURES=$fixtures" "$image" bash -c '
        set -euo pipefail
        for fixture in $PROFILER_KEY_FIXTURES; do
          php -n -d "extension=$PROFILER_EXTENSION" "tests/php/$fixture.php"
        done
        python3 scripts/basedir-tests.py
        python3 scripts/test-report-oom.py
        bash scripts/native-fault-tests.sh
      ' | tee "$compatibility/${version}-${sapi}-delta.log"
      if [[ "$sapi" == fpm ]]; then
        run_fpm_suite
      fi
      printf '%s %s\n' "$version" "$sapi" >> "$compatibility/cells-passed.txt"
      continue
    fi
    docker run --rm "$image" | tee "$compatibility/${version}-${sapi}-cli.log"
    docker run --rm "$image" bash scripts/native-fault-tests.sh | tee "$compatibility/${version}-${sapi}-clock-fault.log"
    builtin_opcache=0
    if docker run --rm "$image" php -n -r 'exit(extension_loaded("Zend OPcache") ? 0 : 1);'; then builtin_opcache=1; fi
    for mode in opcache jit; do
      flags=(-d opcache.enable_cli=1)
      if [[ "$builtin_opcache" == 0 ]]; then flags=(-d zend_extension=opcache "${flags[@]}"); fi
      if [[ "$mode" == jit ]]; then flags+=(-d opcache.jit_buffer_size=64M -d opcache.jit=1255); fi
      docker run --rm -e "PHP_EXTRA_ARGS=${flags[*]}" "$image" | tee "$compatibility/${version}-${sapi}-${mode}.log"
      docker run --rm "$image" php -n "${flags[@]}" -d extension=/profiler/target/release/libphp_profiler.so -r '
        $status = opcache_get_status(false);
        if (!$status || !$status["opcache_enabled"]) { throw new RuntimeException("OPcache not active"); }
        if ($argv[1] === "jit" && empty($status["jit"]["on"])) { throw new RuntimeException("JIT not active"); }
        echo json_encode(["php" => PHP_VERSION, "zts" => PHP_ZTS, "jit" => $status["jit"] ?? null]), PHP_EOL;
      ' "$mode" | tee "$compatibility/${version}-${sapi}-${mode}-status.json"
    done
    if [[ "$sapi" == fpm ]]; then
      run_fpm_suite
    fi
    printf '%s %s\n' "$version" "$sapi" >> "$compatibility/cells-passed.txt"
  done
done
