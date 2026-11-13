"""Remove build outputs while preserving tools and historical evidence."""
from pathlib import Path
import shutil

root = Path(__file__).resolve().parents[1] / 'target'
for name in ('debug', 'release', 'package-build', 'package-smoke'):
    path = root / name
    if path.is_symlink():
        path.unlink()
    elif path.exists():
        shutil.rmtree(path)
