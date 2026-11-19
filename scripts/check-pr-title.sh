#!/usr/bin/env bash
# Require a Conventional Commit subject suitable for squash releases.
set -euo pipefail
# POSIX ERE without REG_NEWLINE: ^ and $ anchor the whole value, and the
# bracket expressions hold real CR and LF, so a second line never passes.
pattern=$'^(feat|fix|docs|test|style|chore|ci|build|perf|refactor|revert)(\\([^()\r\n]+\\))?!?: [^\r\n]+$'
if [[ ! ${PR_TITLE?PR_TITLE is required} =~ $pattern ]]; then
  echo 'Use a Conventional Commit PR title, for example: fix: preserve session isolation' >&2
  exit 1
fi
