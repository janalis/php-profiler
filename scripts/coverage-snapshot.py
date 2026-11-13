#!/usr/bin/env python3
"""Fingerprint production/build/test inputs without relying on a Git checkout."""
import json
from pathlib import Path
import sys
from validation_inputs import snapshot

if __name__ == '__main__':
    print(json.dumps(snapshot(Path(sys.argv[1]).resolve()), indent=2, sort_keys=True))
