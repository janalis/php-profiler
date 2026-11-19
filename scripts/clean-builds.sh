#!/usr/bin/env bash
# Remove build outputs while preserving tools and historical evidence.
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd -P)/target
if [[ "$root" != /*/target ]]; then
  echo "refusing to clean an unexpected target directory: $root" >&2
  exit 1
fi
for name in debug release package-build package-smoke; do
  # Without a trailing slash, rm removes a symlink itself, never what it points to.
  rm -rf -- "${root:?}/$name"
done
