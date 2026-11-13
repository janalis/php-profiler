#!/usr/bin/env bash
# Build a source archive through actual PIE without installing into PHP or changing php.ini.
set -euo pipefail
cd "$(dirname "$0")/.."
archive="${1:-$PWD/target/php-profiler-source.tar.gz}"
pie="${PIE_PHAR:-$PWD/target/tools/pie.phar}"
php_config="${PHP_CONFIG:-$(command -v php-config)}"
phpize="${PHPIZE:-$(command -v phpize)}"
php_binary="${PHP_BINARY:-$(command -v php)}"
test -f "$archive"
test -f "$pie"
mkdir -p target
work=$(mktemp -d "$PWD/target/pie-package-smoke.XXXXXX")
python3 - "$archive" "$work/source" <<'PY'
import sys
import tarfile
from pathlib import Path
output = Path(sys.argv[2])
output.mkdir()
with tarfile.open(sys.argv[1]) as source:
    source.extractall(output, filter='data')
PY
sources=("$work"/source/*)
if [[ ${#sources[@]} != 1 || ! -f "${sources[0]}/composer.json" ]]; then
  echo 'Expected one PIE source root containing composer.json' >&2
  exit 1
fi
export PIE_WORKING_DIRECTORY="$work/pie"
"$php_binary" "$pie" repository:add path "${sources[0]}" --no-interaction --with-php-config="$php_config"
"$php_binary" "$pie" build janalis/php-profiler:@dev --no-interaction --with-php-config="$php_config" --with-phpize-path="$phpize"
module="${sources[0]}/packaging/modules/php_profiler.so"
test -f "$module"
"$php_binary" -n -d "extension=$module" -r 'if (!extension_loaded("php_profiler")) { exit(1); } echo "PIE built extension loads\n";'
echo "PIE build-only evidence: $work"
