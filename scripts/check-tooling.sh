#!/usr/bin/env bash
# The development tooling is PHP, shell and Makefile commands: no Python script, no python
# or pip command, and no Dependabot pip ecosystem. Historical records keep their original wording: the validation history,
# the changelog, the audited mutation equivalence record, and one receipt row.
set -euo pipefail
cd "$(dirname "$0")/.."
status=0
files=$(git ls-files '*.py' '*.pyi' 'requirements*.txt')
if [[ -n "$files" ]]; then
  printf 'Python files are tracked:\n%s\n' "$files" >&2
  status=1
fi
# Bracket expressions, not \b: git grep -E does not support word boundaries everywhere.
word='[^[:alnum:]_]'
matches=$(git grep -nIE "(^|$word)python3?($word|\$)|[[:alnum:]_]\.py($word|\$)|(^|$word)pip3? install|package-ecosystem:[[:space:]]*[\"']?pip[\"']?($word|\$)" -- . \
  ':!docs/validation-history.md' ':!CHANGELOG.md' ':!docs/mutation-equivalence.md' ':!scripts/check-tooling.sh' \
  | grep -vF 'target/minit-startup-audit/python-main.log' || true)
if [[ -n "$matches" ]]; then
  printf 'Python commands or paths remain:\n%s\n' "$matches" >&2
  status=1
fi
exit "$status"
