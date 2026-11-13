"""Reject incompatible Rust/C LLVM instrumentation before compiling a mixed DSO."""
import re
from pathlib import Path
import shlex
import subprocess
import sys


def require_matching_llvm(rust_version, compiler_version):
    rust = re.search(r'^LLVM version:\s*(\d+)\.', rust_version, re.MULTILINE)
    compiler = re.search(r'\bclang version\s+(\d+)\.', compiler_version)
    if rust is None:
        raise ValueError('rustc did not report its LLVM version')
    if compiler is None:
        raise ValueError('Mixed Rust/C coverage requires a Clang compiler with a reported LLVM version')
    rust_major, compiler_major = int(rust[1]), int(compiler[1])
    if rust_major != compiler_major:
        raise ValueError(f'Coverage LLVM major mismatch: rustc uses {rust_major}, Clang uses {compiler_major}; set CC, CLANG_PATH and LIBCLANG_PATH to the matching LLVM installation')
    return rust_major


def check(compiler, toolchain, run=subprocess.check_output):
    command = [compiler] if Path(compiler).is_file() else shlex.split(compiler)
    if not command:
        raise ValueError('CC must name a Clang compiler')
    rust = run(['rustc', '+' + toolchain, '-vV'], text=True)
    clang = run([*command, '--version'], text=True)
    return require_matching_llvm(rust, clang)


if __name__ == '__main__':
    try:
        major = check(sys.argv[1], sys.argv[2])
    except (ValueError, subprocess.CalledProcessError) as error:
        raise SystemExit(str(error))
    print(f'Mixed coverage toolchain uses LLVM {major}')
