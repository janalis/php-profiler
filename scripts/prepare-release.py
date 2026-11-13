"""Prepare version metadata; never touch source-qualified mutation proof ledgers."""
import argparse
import datetime
import re
import subprocess
from pathlib import Path

parser = argparse.ArgumentParser()
parser.add_argument("version")
args = parser.parse_args()
if not re.fullmatch(r"0\.\d+\.\d+-alpha\.[1-9]\d*", args.version):
    parser.error("Only the reviewed alpha channel is supported")
root = Path(__file__).resolve().parent.parent
if subprocess.check_output(["git", "status", "--porcelain"], cwd=root, text=True).strip():
    parser.error("Release preparation requires a clean source tree")
manifest = root / "Cargo.toml"
source = manifest.read_text()
source, count = re.subn(r'(?m)^version = "[^"\n]+"$', f'version = "{args.version}"', source)
if count != 1:
    parser.error("Expected exactly one workspace version")
manifest.write_text(source)
# Cargo updates both workspace package entries while preserving the locked dependency graph.
subprocess.run(["cargo", "metadata", "--offline", "--format-version=1"], cwd=root, stdout=subprocess.DEVNULL, check=True)
changelog = root / "CHANGELOG.md"
source = changelog.read_text()
heading = "## [Unreleased]"
if source.count(heading) != 1 or f"## [{args.version}]" in source:
    parser.error("Changelog must contain a unique Unreleased section and no duplicate version")
date = subprocess.check_output(["git", "show", "-s", "--format=%cs", "HEAD"], cwd=root, text=True).strip()
datetime.date.fromisoformat(date)
source = source.replace(heading, f"{heading}\n\n## [{args.version}] - {date}", 1)
source = source.replace("No version has been published. ", "")
changelog.write_text(source)
