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
scripts/php-tool.sh scripts/source-snapshot.php "$snapshot" "$compatibility/source-sha256.json"
run_fpm_suite() {
  docker run --rm "$image" scripts/php-tool.sh scripts/automatic-tests.php --fpm | tee "$compatibility/${version}-${sapi}-automatic.log"
  docker run --rm "$image" scripts/php-tool.sh scripts/fpm-test.php | tee "$compatibility/${version}-${sapi}-fpm.log"
  docker run --rm "$image" scripts/php-tool.sh scripts/sampling-jit-tests.php --fpm \
    --extension /profiler/target/release/libphp_profiler.so --expected-php "$version" --expected-zts "$expected_zts" \
    | tee "$compatibility/${version}-${sapi}-sampling-jit-reuse.json"
  docker run --rm "$image" scripts/php-tool.sh scripts/fpm-recovery-test.php | tee "$compatibility/${version}-${sapi}-fpm-recovery.json"
  docker run --rm "$image" scripts/php-tool.sh scripts/fpm-vendor-ownership-test.php | tee "$compatibility/${version}-${sapi}-fpm-vendor-ownership.json"
  opcache_environment=()
  if ! docker run --rm "$image" php -n -r 'exit(extension_loaded("Zend OPcache") ? 0 : 1);'; then
    opcache_environment=(-e PROFILER_FPM_OPCACHE_EXTENSION=opcache)
  fi
  for fpm_mode in disabled opcache jit preload preload-jit; do
    jit_requirement=()
    if [[ "$fpm_mode" == jit || "$fpm_mode" == preload-jit ]]; then jit_requirement=(--require-jit); fi
    docker run --rm ${opcache_environment[@]+"${opcache_environment[@]}"} "$image" scripts/php-tool.sh scripts/fpm-closure-tests.php \
      --mode "$fpm_mode" --expected-php "$version" --expected-zts "$expected_zts" ${jit_requirement[@]+"${jit_requirement[@]}"} | tee "$compatibility/${version}-${sapi}-closure-${fpm_mode}.json"
  done
  docker run --rm ${opcache_environment[@]+"${opcache_environment[@]}"} "$image" scripts/php-tool.sh scripts/fpm-closure-tests.php \
    --mode preload-jit --require-jit --workers 4 --expected-php "$version" --expected-zts "$expected_zts" | tee "$compatibility/${version}-${sapi}-closure-preload-jit-four-workers.json"
}
for version in ${PHP_VERSIONS:-8.3 8.4 8.5 8.6}; do
  for sapi in ${PHP_SAPIS:-cli zts fpm apache frankenphp}; do
    # The official 8.6 images are all ZTS, so there is no php:8.6-zts tag; FrankenPHP has no
    # build on PHP 8.6 yet.
    if [[ "$version" == 8.6 && ( "$sapi" == zts || "$sapi" == frankenphp* ) ]]; then
      echo "skip ${version}-${sapi}: no upstream image" >&2
      continue
    fi
    image="${PROFILER_IMAGE_PREFIX:-local-php-profiler}:${version}-${sapi}"
    php_image="php:${version}-${sapi}-bookworm"
    # FrankenPHP's image builds PHP the official images' way, so extensions load as usual. A
    # frankenphp-X.Y.Z cell runs that FrankenPHP release instead of the latest one.
    frankenphp_release=
    if [[ "$sapi" == frankenphp-* ]]; then frankenphp_release="${sapi#frankenphp-}"; fi
    if [[ "$sapi" == frankenphp* ]]; then
      php_image="dunglas/frankenphp:${frankenphp_release:+${frankenphp_release}-}php${version}-bookworm"
    fi
    docker build -f "$snapshot/tests/docker/Dockerfile" --build-arg "PHP_IMAGE=$php_image" -t "$image" "$snapshot"
    expected_zts=nts
    if [[ "$sapi" == zts || "$sapi" == frankenphp* ]]; then expected_zts=ts; fi
    # From 8.6 the official images build every SAPI with --enable-zts.
    case "$version" in 8.3 | 8.4 | 8.5) ;; *) expected_zts=ts ;; esac
    docker run --rm "$image" scripts/php-tool.sh scripts/php-environment-check.php --version "$version" --zts "$expected_zts" \
      | tee "$compatibility/${version}-${sapi}-runtime.json"
    if [[ "$sapi" == apache || "$sapi" == frankenphp* ]]; then
      # The other cells cover the CLI; these check only how each server hands requests to PHP.
      server=(-e APACHE=apache2 -e APACHE_PHP_MODULE=/usr/lib/apache2/modules/libphp.so)
      label=Apache
      if [[ "$sapi" == frankenphp* ]]; then server=(-e FRANKENPHP=frankenphp); label=FrankenPHP; fi
      docker run --rm "${server[@]}" "$image" scripts/php-tool.sh scripts/automatic-tests.php \
        | tee "$compatibility/${version}-${sapi}-automatic.log"
      grep -qx "automatic $label trigger checks passed" "$compatibility/${version}-${sapi}-automatic.log"
      if [[ "$sapi" == frankenphp* ]]; then
        if [[ -n "$frankenphp_release" ]]; then
          grep -Fqx "automatic FrankenPHP version $frankenphp_release" "$compatibility/${version}-${sapi}-automatic.log"
        fi
        grep -qx "automatic FrankenPHP worker checks passed" "$compatibility/${version}-${sapi}-automatic.log"
        grep -qx "automatic FrankenPHP classic-mode checks passed" "$compatibility/${version}-${sapi}-automatic.log"
      fi
      printf '%s %s\n' "$version" "$sapi" >> "$compatibility/cells-passed.txt"
      continue
    fi
    if [[ "${PROFILER_TEST_MODE:-full}" != overlay ]]; then
      docker run --rm "$image" scripts/php-tool.sh scripts/test-vendored-exception.php | tee "$compatibility/${version}-${sapi}-vendor-exception.log"
    fi
    if [[ "${PROFILER_TEST_MODE:-full}" == full || "${PROFILER_TEST_MODE:-full}" == final ]]; then
      # Dedicated once per cell, never part of the repeated native mutation runner.
      docker run --rm "$image" php -n -d extension=/profiler/target/release/libphp_profiler.so \
        tests/native/large-profile-boundary.php | tee "$compatibility/${version}-${sapi}-large-profile-boundary.log"
      # The tracing-JIT/coobserver regression requires a JIT-enabled PHP build;
      # all nine official image companions provide one, unlike some host SDKs.
      docker run --rm "$image" scripts/php-tool.sh scripts/jit-megamorphic-tests.php \
        --extension /profiler/target/release/libphp_profiler.so \
        | tee "$compatibility/${version}-${sapi}-megamorphic-jit.json"
      docker run --rm "$image" scripts/php-tool.sh scripts/sampling-jit-tests.php \
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
        scripts/php-tool.sh scripts/basedir-tests.php
        scripts/php-tool.sh scripts/test-report-oom.php
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
