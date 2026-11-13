"""Exhaustive runtime source registry shared by coverage and mutation gates."""
from pathlib import Path


def runtime_sources(root: Path, language: str = 'all') -> set[Path]:
    suffixes = {'.rs', '.c'} if language == 'all' else {'.rs' if language == 'rust' else '.c'}
    return {path.resolve() for crate in (root.resolve() / 'crates').iterdir() if crate.is_dir()
            for path in (crate / 'src').rglob('*') if path.is_file() and path.suffix in suffixes}
