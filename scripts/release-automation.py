"""Coordinate exact-SHA quality dispatch, protected candidate merges and PIE releases."""
import argparse
import importlib.util
import json
import os
from pathlib import Path
import re
import subprocess
import tomllib

REPOSITORY = "janalis/php-profiler"


def command(*args):
    return subprocess.check_output(list(args), text=True).strip()


def api(path):
    return json.loads(command("gh", "api", f"repos/{REPOSITORY}/{path}"))


def dispatch(branch, source):
    subprocess.run(["gh", "workflow", "run", "ci.yml", "--repo", REPOSITORY,
                    "--ref", branch, "-f", "expected_sha=" + source], check=True)


def validate_run(run, source):
    if (run.get("name") != "quality" or run.get("conclusion") != "success" or
            run.get("head_sha") != source or run.get("head_repository", {}).get("full_name") != REPOSITORY or
            run.get("event") not in {"push", "workflow_dispatch"}):
        raise ValueError("Expected a successful exact-SHA quality run from this repository")
    branch = run.get("head_branch", "")
    if branch != "main" and not (re.fullmatch(r"release/0\.\d+\.\d+-alpha\.[1-9]\d*-[0-9a-f]{12}", branch)
                                  and run.get("event") == "workflow_dispatch"
                                  and run.get("actor", {}).get("login") == "github-actions[bot]"):
        raise ValueError("Untrusted release branch or dispatch actor")
    return branch



def validate_candidate(parent, candidate, version):
    changed = command("git", "diff", "--name-only", parent, candidate).splitlines()
    if not changed or not set(changed).issubset({"Cargo.toml", "Cargo.lock", "CHANGELOG.md", "tests/native/c-equivalents.json"}):
        raise ValueError("Candidate modifies files outside release metadata")
    # Import only the trusted main checkout's helper, never candidate branch code.
    spec = importlib.util.spec_from_file_location("version_transition", Path(__file__).with_name("validate-version-transition.py"))
    transition = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(transition)
    def reader(revision):
        return lambda name: subprocess.check_output(["git", "show", revision + ":" + name])
    expected, _ = transition.expected_transition(reader(parent), reader(candidate), version)
    if reader(candidate)(transition.LEDGER) != expected:
        raise ValueError("Candidate ledger differs from the independently derived version transition")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("quality_run_id", type=int)
    args = parser.parse_args()
    root = Path(__file__).resolve().parent.parent
    os.chdir(root)
    checkout = command("git", "rev-parse", "HEAD")
    run = api(f"actions/runs/{args.quality_run_id}")
    source = run.get("head_sha", "")
    branch = validate_run(run, source)
    main_sha = api("git/ref/heads/main")["object"]["sha"]
    if checkout != main_sha:
        print("Trusted main checkout is stale; retry on current main")
        return
    if branch != "main":
        if api("git/ref/heads/" + branch)["object"]["sha"] != source:
            raise ValueError("Candidate branch advanced after quality validation")
        parent = command("git", "rev-parse", source + "^")
        if parent != main_sha or not branch.endswith("-" + main_sha[:12]):
            print("Main advanced; this candidate is obsolete")
            return
        version = branch.removeprefix("release/").rsplit("-", 1)[0]
        validate_candidate(parent, source, version)
        prs = json.loads(command("gh", "pr", "list", "--repo", REPOSITORY, "--head", branch,
                                 "--base", "main", "--state", "open", "--json", "number,headRefOid,author"))
        if len(prs) != 1 or prs[0]["headRefOid"] != source or prs[0].get("author", {}).get("login") not in {"github-actions[bot]", "app/github-actions"}:
            raise ValueError("Expected one open pull request for this exact candidate")
        subprocess.run(["gh", "pr", "merge", str(prs[0]["number"]), "--repo", REPOSITORY,
                        "--squash", "--match-head-commit", source], check=True)
        pr = json.loads(command("gh", "pr", "view", str(prs[0]["number"]), "--repo", REPOSITORY,
                                "--json", "state,mergeCommit"))
        if pr["state"] != "MERGED":
            raise ValueError("Protected merge did not complete")
        dispatch("main", pr["mergeCommit"]["oid"])
        return
    if main_sha != source:
        print("Main advanced; a newer quality run will continue release preparation")
        return
    plan = json.loads(command("node", "scripts/release-plan.mjs"))
    current = tomllib.loads(Path("Cargo.toml").read_text())["workspace"]["package"]["version"]
    version = plan["version"]
    if version is None:
        # A previous attempt may already have created the tag and release before
        # Packagist indexed it. Only resume when that tag still identifies HEAD.
        if plan["previous"] != current:
            return
        if command("git", "rev-parse", "v" + current) != source:
            return
        version = current
    if current == version:
        if os.environ.get("PUBLISHING_ENABLED") != "true":
            raise ValueError("Publication disabled until exact quality gates and Packagist ownership are verified")
        subprocess.run(["python3", "scripts/package-source.py"], check=True)
        subprocess.run(["python3", "scripts/finalize-release.py", version,
                        "target/php-profiler-source.tar.gz", "--quality-run-id", str(args.quality_run_id)], check=True)
        return
    branch = f"release/{version}-{source[:12]}"
    refs = api("git/matching-refs/heads/" + branch)
    existing = [ref for ref in refs if ref["ref"] == "refs/heads/" + branch]
    if existing:
        candidate = existing[0]["object"]["sha"]
        if command("git", "rev-parse", candidate + "^") != source:
            raise ValueError("Existing candidate branch has another parent")
        validate_candidate(source, candidate, version)
    else:
        subprocess.run(["cargo", "fetch", "--locked"], check=True)
        subprocess.run(["python3", "scripts/prepare-release.py", version], check=True)
        subprocess.run(["git", "config", "user.name", "github-actions[bot]"], check=True)
        subprocess.run(["git", "config", "user.email", "41898282+github-actions[bot]@users.noreply.github.com"], check=True)
        subprocess.run(["git", "add", "Cargo.toml", "Cargo.lock", "CHANGELOG.md", "tests/native/c-equivalents.json"], check=True)
        subprocess.run(["git", "commit", "-m", "chore(release): prepare " + version], check=True)
        candidate = command("git", "rev-parse", "HEAD")
        subprocess.run(["gh", "auth", "setup-git"], check=True)
        subprocess.run(["git", "push", "origin", f"HEAD:refs/heads/{branch}"], check=True)
    prs = json.loads(command("gh", "pr", "list", "--repo", REPOSITORY, "--head", branch,
                             "--base", "main", "--state", "open", "--json", "number,headRefOid"))
    if prs and (len(prs) != 1 or prs[0]["headRefOid"] != candidate):
        raise ValueError("Unexpected existing candidate pull request")
    if not prs:
        subprocess.run(["gh", "pr", "create", "--repo", REPOSITORY, "--base", "main", "--head", branch,
                        "--title", "chore(release): prepare " + version,
                        "--body", "Automatic alpha candidate. Full required quality checks must pass on these exact source bytes. Native proof ledgers are never automatically rewritten."], check=True)
    # GITHUB_TOKEN writes intentionally do not trigger ordinary push/PR workflows.
    # workflow_dispatch is the supported explicit exception.
    dispatch(branch, candidate)


if __name__ == "__main__":
    main()
