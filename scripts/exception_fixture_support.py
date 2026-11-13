"""Build or locate the test-only exception ownership extension."""
import os
from pathlib import Path
import subprocess
import sys


def exception_fixture():
    root = Path(__file__).resolve().parent.parent
    configured = os.environ.get('PROFILER_FIXTURE_EXTENSION')
    if configured:
        return str(Path(configured).resolve())
    target = Path(os.environ.get('CARGO_TARGET_DIR', root / 'target')) / 'exception-fixture'
    cargo = ['cargo']
    if os.environ.get('PROFILER_VENDOR_TOOLCHAIN'):
        cargo.append('+' + os.environ['PROFILER_VENDOR_TOOLCHAIN'])
    subprocess.run(cargo + ['build', '--manifest-path', 'tests/support/exception-fixture/Cargo.toml',
                            '--target-dir', str(target)], cwd=root, check=True)
    suffix = 'dylib' if sys.platform == 'darwin' else 'so'
    return str((target / 'debug' / ('libprofiler_exception_fixture.' + suffix)).resolve())
