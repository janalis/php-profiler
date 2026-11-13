"""Reject formatter drift before any source rewriting."""
import re
import subprocess
import sys

for executable, pattern in [(sys.argv[1], r'\b1\.28\.0\b'), (sys.argv[2], r'\b23\.1\.3\b'), ('rustc', r'\b1\.99\.1\b')]:
    version = subprocess.check_output([executable, '--version'], text=True)
    if not re.search(pattern, version):
        raise SystemExit(f'Unsupported tool version: {version.strip()}')
    print(version.strip())
