#!/usr/bin/env bash
# AddressSanitizer for first-party Rust/C loaded into a real PHP interpreter.
set -euo pipefail
cd "$(dirname "$0")/.."
asan_toolchain="${PROFILER_SANITIZER_TOOLCHAIN:-nightly}"
asan_target=$(rustc +"$asan_toolchain" -vV | sed -n 's/^host: //p')
export CARGO_TARGET_DIR="${CARGO_TARGET_DIR:-$PWD/target/asan}"
export CC="${CC:-clang}"
export CFLAGS="${CFLAGS:-} -fsanitize=address"
export RUSTFLAGS="${RUSTFLAGS:-} -Zsanitizer=address"
# Explicit target keeps host proc-macros/build scripts free of target sanitizers.
cargo +"$asan_toolchain" build -p php-profiler --target "$asan_target"
if [[ "$OSTYPE" == darwin* ]]; then
  asan_runtime_directory=$(rustc +"$asan_toolchain" --print target-libdir)
  PROFILER_ASAN_RUNTIME="$asan_runtime_directory/librustc-nightly_rt.asan.dylib"
  suffix=dylib
else
  suffix=so
  asan_architecture=$(uname -m)
  PROFILER_ASAN_RUNTIME=$("$CC" "-print-file-name=libclang_rt.asan-$asan_architecture.so")
fi
export PROFILER_ASAN_RUNTIME
export PROFILER_EXTENSION="$CARGO_TARGET_DIR/$asan_target/debug/libphp_profiler.$suffix"
export ASAN_OPTIONS="${ASAN_OPTIONS:-abort_on_error=1}"
# AddressSanitizer does not instrument the interpreter itself. LeakSanitizer
# availability depends on the platform; worker retention is a separate gate.
scripts/php-tests.sh
