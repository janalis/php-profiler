#!/bin/sh
set -eu
root=$(cd "$1" && pwd)
output=$2
mkdir -p "$output"
output=$(cd "$output" && pwd)
: "${PHP_CONFIG:=php-config}"
: "${CARGO:=cargo}"
PHP_CONFIG=$(command -v "$PHP_CONFIG")
export PHP_CONFIG
CARGO_TARGET_DIR="$root/target/package-build" "$CARGO" build --manifest-path "$root/Cargo.toml" --release --locked -p php-profiler
case $(uname -s) in
 Darwin) library=libphp_profiler.dylib ;;
 Linux) library=libphp_profiler.so ;;
 *) echo 'Unsupported packaging platform' >&2; exit 1 ;;
esac
cp "$root/target/package-build/release/$library" "$output/php_profiler.so"
