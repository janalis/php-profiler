"""Install checksum-pinned development tools locally, never into host directories."""
import hashlib
import io
import json
import os
import platform
from pathlib import Path
import subprocess
import tarfile
import tempfile

ROOT = Path(__file__).resolve().parents[1]


def cohort(manifest, system, machine):
    key = f'{system}/{machine}'
    if key not in manifest:
        raise RuntimeError(f'Unsupported development tool platform: {key}')
    return manifest[key]


def install_archive(name, source, destination):
    data = subprocess.check_output(['curl', '--fail', '--show-error', '--location', '--max-time', '60', source['url']])
    if hashlib.sha256(data).hexdigest() != source['sha256']:
        raise RuntimeError(f'{name} archive checksum mismatch')
    with tarfile.open(fileobj=io.BytesIO(data)) as archive:
        candidates = [member for member in archive.getmembers() if Path(member.name).name == name]
        if len(candidates) != 1 or not candidates[0].isfile():
            raise RuntimeError(f'Expected exactly one regular {name} executable')
        executable = archive.extractfile(candidates[0]).read()
    destination.mkdir(parents=True, exist_ok=True)
    # Atomic replacement leaves an existing installation intact on failure.
    descriptor, temporary = tempfile.mkstemp(prefix=f'.{name}-', dir=destination)
    try:
        with os.fdopen(descriptor, 'wb') as stream:
            stream.write(executable)
            stream.flush()
            os.fchmod(stream.fileno(), 0o755)
        os.replace(temporary, destination / name)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def main():
    manifest = json.loads((ROOT / 'scripts/tool-manifest.json').read_text())
    tools = cohort(manifest, platform.system(), platform.machine())
    destination = ROOT / 'target/tools'
    for name, source in tools.items():
        install_archive(name, source, destination / 'bin')
    venv = destination / 'venv'
    subprocess.run(['python3', '-m', 'venv', str(venv)], check=True)
    subprocess.run([str(venv / 'bin/pip'), 'install', 'clang-format==23.1.3', '-r', str(ROOT / 'requirements-dev.txt')], check=True)
    for name in tools:
        subprocess.run([str(destination / 'bin' / name), '--version' if name != 'actionlint' else '-version'], check=True)
    subprocess.run([str(venv / 'bin/clang-format'), '--version'], check=True)


if __name__ == '__main__':
    main()
