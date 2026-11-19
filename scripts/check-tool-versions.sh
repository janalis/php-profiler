#!/usr/bin/env bash
# Reject formatter drift before any source rewriting.
set -euo pipefail
check() {
  local version
  version=$("$1" --version)
  # Word boundaries on both sides, as the pinned versions start and end with digits.
  if ! printf '%s\n' "$version" | grep -Eq "(^|[^[:alnum:]_])$2($|[^[:alnum:]_])"; then
    printf 'Unsupported tool version: %s\n' "$version" >&2
    exit 1
  fi
  printf '%s\n' "$version"
}
check "$1" '1\.28\.0'
check "$2" '23\.1\.3'
check rustc '1\.99\.1'
