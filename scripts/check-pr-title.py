"""Require a Conventional Commit subject suitable for squash releases."""
import os
import re

subject = os.environ['PR_TITLE']
if not re.fullmatch(r'(feat|fix|docs|test|style|chore|ci|build|perf|refactor|revert)(\([^()\r\n]+\))?!?: [^\r\n]+', subject):
    raise SystemExit('Use a Conventional Commit PR title, for example: fix: preserve session isolation')
