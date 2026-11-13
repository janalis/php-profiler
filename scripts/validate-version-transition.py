"""Prove and apply only a release-version change to an already-current C proof ledger.

This does not renew C source spans, native contexts or equivalent classifications.
Fresh native context validation and NTS/ZTS Mull remain mandatory afterwards.
"""
import argparse
import hashlib
import json
from pathlib import Path
import re
import subprocess
import tomllib

LEDGER = "tests/native/c-equivalents.json"
DEPENDENCIES = frozenset("""Cargo.lock
Cargo.toml
crates/php-profiler/Cargo.toml
crates/php-profiler/build.rs
crates/php-profiler/src/bridge.c
crates/php-profiler/src/lib.rs
crates/php-profiler/src/sampling.c
crates/php-profiler/src/sampling.rs
crates/php-profiler/src/storage.rs
crates/profiler-core/Cargo.toml
crates/profiler-core/src/budget.rs
crates/profiler-core/src/index.rs
crates/profiler-core/src/lib.rs
crates/profiler-core/src/sampling.rs
crates/profiler-core/src/serialization.rs
crates/profiler-core/src/storage.rs
docs/mutation-equivalence.md
scripts/link-native-faults.sh
tests/native/bailout-fault.c
tests/native/bridge-unit.c
tests/native/frames-native.c
tests/native/sampling-lifecycle-native.c
tests/native/sampling-unit.c
tests/support/adapter-contract.rs
tests/support/sampling-adapter-contract.rs""".splitlines())
ALLOWED = frozenset({"Cargo.toml", "Cargo.lock", "CHANGELOG.md", LEDGER})


def digest(body):
    return hashlib.sha256(body).hexdigest()


def expected_transition(base, target, version):
    """Derive exact expected ledger bytes from immutable base bytes, never target claims."""
    if not re.fullmatch(r"0\.\d+\.\d+-alpha\.[1-9]\d*", version):
        raise ValueError("Only the reviewed alpha channel is supported")
    ledger_bytes = base(LEDGER)
    ledger = json.loads(ledger_bytes)
    hashes = ledger.get("dependency_sha256", {})
    if set(hashes) != DEPENDENCIES:
        raise ValueError("Base proof dependency inventory is incomplete or unexpected")
    for name in DEPENDENCIES:
        if hashes[name] != digest(base(name)):
            raise ValueError("Base proof hash is stale: " + name)
        if name not in {"Cargo.toml", "Cargo.lock"} and base(name) != target(name):
            raise ValueError("A non-version proof dependency changed: " + name)
    old_manifest = base("Cargo.toml").decode()
    new_manifest = target("Cargo.toml").decode()
    old_version = tomllib.loads(old_manifest)["workspace"]["package"]["version"]
    expected_manifest, count = re.subn(r'(?m)^version = "[^"\n]+"$', f'version = "{version}"', old_manifest)
    if count != 1 or old_version == version or new_manifest != expected_manifest:
        raise ValueError("Workspace manifest must change only its version")
    old_lock = base("Cargo.lock").decode()
    new_lock = target("Cargo.lock").decode()
    expected_parsed = tomllib.loads(old_lock)
    internal = {"php-profiler", "profiler-core"}
    seen = set()
    for package in expected_parsed.get("package", []):
        if package.get("name") in internal and "source" not in package:
            if package["name"] in seen or package.get("version") != old_version:
                raise ValueError("Unexpected internal lock package identity/version")
            seen.add(package["name"])
            package["version"] = version
    if seen != internal or tomllib.loads(new_lock) != expected_parsed:
        raise ValueError("Lock dependency graph differs beyond the two internal versions")
    blocks = old_lock.split("[[package]]")
    for index, block in enumerate(blocks):
        match = re.search(r'(?m)^name = "(php-profiler|profiler-core)"$', block)
        if match:
            block, count = re.subn(r'(?m)^version = "' + re.escape(old_version) + r'"$', f'version = "{version}"', block)
            if count != 1:
                raise ValueError("Unexpected internal lock text")
            blocks[index] = block
    if new_lock != "[[package]]".join(blocks):
        raise ValueError("Lock text changed beyond the two internal version values")
    expected = ledger_bytes.decode()
    updates = {}
    for name in ("Cargo.toml", "Cargo.lock"):
        replacement = digest(target(name))
        before = hashes[name]
        # Match the dependency map key, not a coincidentally equal hash elsewhere.
        expression = r'("' + re.escape(name) + r'"\s*:\s*")' + before + r'(")'
        expected, count = re.subn(expression, lambda match: match[1] + replacement + match[2], expected)
        if count != 1:
            raise ValueError("Dependency hash is not uniquely represented: " + name)
        updates[name] = {"old_sha256": before, "new_sha256": replacement}
    restored = json.loads(expected)
    restored["dependency_sha256"] = hashes
    if restored != ledger:
        raise ValueError("Unexpected proof mutation")
    # The byte substitution above preserves all source spans, contexts, replacements,
    # classifications, formatting and every other proof dependency exactly.
    if target(LEDGER) not in {ledger_bytes, expected.encode()}:
        raise ValueError("Target proof ledger changes more than the two derived Cargo hashes")
    return expected.encode(), updates


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("base")
    parser.add_argument("version")
    parser.add_argument("--apply", action="store_true")
    args = parser.parse_args()
    root = Path(__file__).resolve().parent.parent
    def git(*arguments):
        return subprocess.check_output(["git", *arguments], cwd=root)
    base_sha = git("rev-parse", "--verify", args.base + "^{commit}").decode().strip()
    changed = set(git("diff", "--name-only", base_sha, "--").decode().splitlines())
    if not changed.issubset(ALLOWED):
        raise ValueError("Unexpected release transition files: " + ", ".join(sorted(changed - ALLOWED)))
    def base(name):
        return git("show", base_sha + ":" + name)
    def target(name):
        return (root / name).read_bytes()
    expected, updates = expected_transition(base, target, args.version)
    actual = target(LEDGER)
    if actual not in {base(LEDGER), expected}:
        raise ValueError("Target ledger changes more than the two derived dependency hashes")
    if args.apply:
        (root / LEDGER).write_bytes(expected)
    elif actual != expected:
        raise ValueError("Target ledger is not the exact expected version transition")
    receipt = {"schema_version": 1, "base_sha": base_sha, "version": args.version,
               "base_ledger_sha256": digest(base(LEDGER)), "candidate_ledger_sha256": digest(expected),
               "dependency_updates": updates, "unchanged_dependency_count": len(DEPENDENCIES) - 2,
               "proof_status": "unchanged; fresh NTS/ZTS native context validation and Mull still required"}
    destination = root / "target/version-transition.json"
    destination.parent.mkdir(parents=True, exist_ok=True)
    destination.write_text(json.dumps(receipt, indent=2) + "\n")
    print(destination)


if __name__ == "__main__":
    main()
