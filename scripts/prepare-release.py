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
changelog = root / "CHANGELOG.md"
changelog_source = changelog.read_text()
heading = "## [Unreleased]"
if changelog_source.count(heading) != 1 or f"## [{args.version}]" in changelog_source:
    parser.error("Changelog must contain a unique Unreleased section and no duplicate version")
date = subprocess.check_output(["git", "show", "-s", "--format=%cs", "HEAD"], cwd=root, text=True).strip()
datetime.date.fromisoformat(date)
tags = subprocess.check_output(["git", "tag", "--merged", "HEAD", "--sort=-version:refname", "--list", "v*"], cwd=root, text=True).splitlines()
revision = tags[0] + "..HEAD" if tags else "HEAD"
commits = subprocess.check_output(["git", "log", "--format=%s%x1f%b%x1e", revision], cwd=root, text=True)
# List every commit release-plan.mjs releases for, including a breaking change named only in its body.
notes = list(dict.fromkeys(
    subject for subject, _, body in (entry.strip("\n").partition("\x1f") for entry in commits.split("\x1e") if entry.strip())
    if re.match(r"(?:feat|fix)(?:\([^\n]+\))?!?: |[a-z]+(?:\([^\n]+\))?!: ", subject)
    or re.search(r"(?m)^BREAKING(?: CHANGE|-CHANGE): .+", body)))
generated = "\n\n### Commits\n\n" + "\n".join("- " + subject for subject in notes) if notes else ""
unreleased = changelog_source.split(heading, 1)[1].split("\n## ", 1)[0]
# finalize-release.py publishes this section as the release notes; refuse an empty one
# before anything is written, rather than after the version bump has merged.
if not generated and not unreleased.strip():
    parser.error(f"No release notes for {args.version}: no releasable commit subject and an empty Unreleased section")
changelog_source = changelog_source.replace(heading, f"{heading}\n\n## [{args.version}] - {date}{generated}", 1)
changelog_source = changelog_source.replace("No version has been published. ", "")
manifest = root / "Cargo.toml"
source = manifest.read_text()
source, count = re.subn(r'(?m)^version = "[^"\n]+"$', f'version = "{args.version}"', source)
if count != 1:
    parser.error("Expected exactly one workspace version")
manifest.write_text(source)
# Cargo updates both workspace package entries while preserving the locked dependency graph.
subprocess.run(["cargo", "metadata", "--offline", "--format-version=1"], cwd=root, stdout=subprocess.DEVNULL, check=True)
changelog.write_text(changelog_source)

# This refuses stale proofs; it is not a general source/formatting proof renewal.
subprocess.run(["python3", "scripts/validate-version-transition.py", "HEAD", args.version, "--apply"], cwd=root, check=True)
