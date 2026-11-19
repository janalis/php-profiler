#!/bin/sh
# Runs development tooling on a PHP that loads no host INI, so the profiler
# under test can never observe, or be measured through, the tooling itself.
# Shared FFI, pcntl and posix modules load when installed; code that needs a
# missing one fails where it uses it.
set -eu
php=${PROFILER_TOOL_PHP:-php}
loaded=$("$php" -n -m)
directory=$("$php" -n -r 'echo ini_get("extension_dir");')
for extension in ffi pcntl posix; do
  if ! printf '%s\n' "$loaded" | grep -qix "$extension" && [ -f "$directory/$extension.so" ]; then
    set -- -d "extension=$extension" "$@"
  fi
done
exec "$php" -n -d ffi.enable=1 -d display_errors=stderr -d error_reporting=-1 "$@"
