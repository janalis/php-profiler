"""Shared validation input fingerprinting without a Git dependency."""
import hashlib
from pathlib import Path

def snapshot(root):
    files = []
    for name in ('package.json', 'package-lock.json', 'Makefile', '.editorconfig', '.clang-format', '.gitattributes', '.gitignore', 'mago.toml', 'composer.json', 'CHANGELOG.md', 'Cargo.toml', 'Cargo.lock', 'rust-toolchain.toml', 'rust-toolchain',
                 '.cargo/config.toml', '.dockerignore', 'requirements-dev.txt', 'Dockerfile',
                 'schema/report-v1.json',
                 'vendor/ext-php-rs/src/exception.rs', 'vendor/ext-php-rs/README.md'):
        path = root / name
        if path.is_file():
            files.append(path)
    for directory in ('crates', 'tests', 'scripts', 'vendor', '.github', 'schema', 'packaging', 'php', '.githooks'):
        for path in (root / directory).rglob('*'):
            if (path.is_file() and 'target' not in path.relative_to(root).parts and
                    not {'__pycache__', 'mutants.out'}.intersection(path.relative_to(root).parts) and
                    (path.name in ('Dockerfile', 'pre-commit', 'Makefile.frag') or path.name.endswith('.Dockerfile') or
                     path.suffix in ('.mjs', '.m4', '.frag', '.rs', '.c', '.h', '.php', '.py', '.sh', '.toml', '.lock', '.yml', '.yaml', '.json'))):
                files.append(path)
    return {str(path.relative_to(root)): hashlib.sha256(path.read_bytes()).hexdigest()
            for path in sorted(set(files))}

