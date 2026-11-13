"""Create a deterministic PIE source archive from a clean committed HEAD."""
import argparse
import gzip
import os
from pathlib import Path
import re
import subprocess
import tempfile
import tomllib


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', default='target/php-profiler-source.tar.gz')
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]

    def git(*arguments):
        return subprocess.check_output(['git', *arguments], cwd=root)

    if git('status', '--porcelain', '--untracked-files=no').strip():
        parser.error('Commit all tracked changes before packaging')
    version = tomllib.loads(git('show', 'HEAD:Cargo.toml').decode())['workspace']['package']['version']
    if not re.fullmatch(r'0\.\d+\.\d+(?:-alpha\.[1-9]\d*)?', version):
        parser.error('Expected a development or alpha 0.x version')
    timestamp = int(git('show', '-s', '--format=%ct', 'HEAD'))
    # git archive preserves Git modes and symlinks and applies committed export-ignore.
    source = git('-c', 'tar.umask=022', 'archive', '--format=tar', f'--prefix=php-profiler-{version}/', 'HEAD')
    output = root / args.output
    output.parent.mkdir(parents=True, exist_ok=True)
    descriptor, temporary = tempfile.mkstemp(prefix='.php-profiler-source-', dir=output.parent)
    try:
        with os.fdopen(descriptor, 'wb') as raw, gzip.GzipFile(filename='', mode='wb', fileobj=raw, mtime=timestamp) as compressed:
            compressed.write(source)
        os.replace(temporary, output)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)
    print(output)


if __name__ == '__main__':
    main()
