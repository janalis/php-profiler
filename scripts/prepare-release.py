"""Prepare release metadata and a narrowly proven version-only ledger transition."""
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
tags = subprocess.check_output(["git", "tag", "--merged", "HEAD", "--sort=-version:refname", "--list", "v*"], cwd=root, text=True).splitlines()
revision = tags[0] + "..HEAD" if tags else "HEAD"
subjects = subprocess.check_output(["git", "log", "--format=%s", revision], cwd=root, text=True).splitlines()
notes = list(dict.fromkeys(subject for subject in subjects if re.match(r"(?:feat|fix)(?:\([^\n]+\))?!?: |[a-z]+(?:\([^\n]+\))?!: ", subject)))
generated = "\n\n### Commits\n\n" + "\n".join("- " + subject for subject in notes) if notes else ""
source = source.replace(heading, f"{heading}\n\n## [{args.version}] - {date}{generated}", 1)
source = source.replace("No version has been published. ", "")
changelog.write_text(source)

# This refuses stale proofs; it is not a general source/formatting proof renewal.
subprocess.run(["python3", "scripts/validate-version-transition.py", "HEAD", args.version, "--apply"], cwd=root, check=True)
