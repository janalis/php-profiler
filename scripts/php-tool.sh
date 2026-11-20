#!/usr/bin/env bash
# Runs development tooling on a PHP that loads no host INI, so the profiler
# under test can never observe, or be measured through, the tooling itself.
# Shared FFI, pcntl, posix and zlib modules load when installed; code that needs a
# missing one fails where it uses it.
# Bash, not /bin/sh: dash drops variables whose names are not shell identifiers, such
# as the Mull switches that activate each C mutant in the tests this wrapper runs.
set -eu
php=${PROFILER_TOOL_PHP:-php}
loaded=$("$php" -n -m)
directory=$("$php" -n -r 'echo ini_get("extension_dir");')
for extension in ffi pcntl posix zlib; do
  if ! printf '%s\n' "$loaded" | grep -qix "$extension" && [ -f "$directory/$extension.so" ]; then
    set -- -d "extension=$extension" "$@"
  fi
done
# Coverage exports decode to large arrays; tooling is not memory-bounded like a request.
# Every warning a script raises becomes an exception, as Python would have raised.
strict="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib/Strict.php"
exec "$php" -n -d ffi.enable=1 -d memory_limit=-1 -d display_errors=stderr -d error_reporting=-1 \
  -d "auto_prepend_file=$strict" "$@"
