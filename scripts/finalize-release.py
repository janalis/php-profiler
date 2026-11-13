"""Resume a validated PIE alpha release; never overwrite a version."""
import argparse
import hashlib
import json
import io
from pathlib import Path
import re
import subprocess
import tarfile
import tomllib
import urllib.request
import time


def fetch(url):
    with urllib.request.urlopen(url, timeout=30) as response:
        return response.read()


def verify_packagist(payload, version, source):
    package_metadata = payload.get("package", {})
    if package_metadata.get("name") != "janalis/php-profiler":
        raise ValueError("Unexpected Packagist package")
    versions = package_metadata.get("versions", {})
    for package in versions.values():
        if package.get("version", "").removeprefix("v") == version:
            metadata = package.get("source", {})
            if metadata.get("reference") != source or metadata.get("url") not in (
                "https://github.com/janalis/php-profiler.git", "https://github.com/janalis/php-profiler"
            ):
                raise ValueError("Packagist source does not match the validated candidate")
            return
    raise ValueError("Packagist has not indexed this alpha tag yet; retry after its webhook completes")



def verify_archive(body, version, committed):
    """Check every archive source byte against the immutable source commit."""
    prefix = "php-profiler-" + version + "/"
    actual = {}
    with tarfile.open(fileobj=io.BytesIO(body), mode="r:gz") as archive:
        for member in archive.getmembers():
            if ".." in Path(member.name).parts or not (member.name == prefix.rstrip("/") or member.name.startswith(prefix)):
                raise ValueError("Archive entry is outside the versioned source directory")
            if member.isdir():
                continue
            if member.name in actual:
                raise ValueError("Archive has duplicate entries")
            if member.isfile():
                actual[member.name] = archive.extractfile(member).read()
            elif member.issym():
                actual[member.name] = ("symlink", member.linkname)
            else:
                raise ValueError("Archive has unsupported entries")
    expected = {prefix + name: data for name, data in committed.items()
                if name.split("/")[0] not in {"target", "mutants.out", ".github"}}
    if actual != expected:
        raise ValueError("Archive source bytes do not match the committed candidate")


def verify_checkout(root, version, body):
    def git(*args):
        return subprocess.check_output(["git", *args], cwd=root)
    if git("status", "--porcelain", "--untracked-files=no").strip():
        raise ValueError("Finalization requires a clean committed candidate")
    manifest = tomllib.loads(git("show", "HEAD:Cargo.toml").decode())
    if manifest["workspace"]["package"]["version"] != version:
        raise ValueError("Requested version differs from the committed candidate")
    committed = {}
    # git archive reads HEAD, never the mutable Git index or untracked files.
    with tarfile.open(fileobj=io.BytesIO(git("archive", "--format=tar", "HEAD"))) as archive:
        for member in archive.getmembers():
            if member.isfile():
                committed[member.name] = archive.extractfile(member).read()
            elif member.issym():
                committed[member.name] = ("symlink", member.linkname)
    verify_archive(body, version, committed)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("version")
    parser.add_argument("archive", type=Path)
    parser.add_argument("--quality-run-id", required=True, type=int)
    args = parser.parse_args()
    if not re.fullmatch(r"0\.\d+\.\d+-alpha\.[1-9]\d*", args.version):
        parser.error("Only alpha publication is supported")
    root = Path(__file__).resolve().parent.parent
    source = subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=root, text=True).strip()
    repo = "janalis/php-profiler"
    run = json.loads(subprocess.check_output(["gh", "api", f"repos/{repo}/actions/runs/{args.quality_run_id}"], text=True))
    if (run.get("name") != "quality" or run.get("conclusion") != "success" or
            run.get("head_sha") != source or run.get("head_branch") != "main" or
            run.get("head_repository", {}).get("full_name") != repo or
            run.get("event") not in {"push", "workflow_dispatch"}):
        raise ValueError("Publication requires the exact successful main quality run")
    current = json.loads(subprocess.check_output(["gh", "api", f"repos/{repo}/git/ref/heads/main"], text=True))
    if current["object"]["sha"] != source:
        raise ValueError("Main advanced; never publish a stale candidate")
    tag = "v" + args.version
    body = args.archive.read_bytes()
    verify_checkout(root, args.version, body)
    # Tags and release assets are immutable. Registry indexing can resume after publication.
    refs = json.loads(subprocess.check_output([
        "gh", "api", f"repos/{repo}/git/matching-refs/tags/{tag}"
    ], text=True))
    existing = [ref for ref in refs if ref["ref"] == "refs/tags/" + tag]
    if existing:
        if existing[0]["object"]["sha"] != source or existing[0]["object"]["type"] != "commit":
            raise ValueError("Existing tag belongs to another source; never overwrite it")
    else:
        latest = json.loads(subprocess.check_output(["gh", "api", f"repos/{repo}/git/ref/heads/main"], text=True))
        if latest["object"]["sha"] != source:
            raise ValueError("Main advanced before tag creation; never publish a stale candidate")
        subprocess.run(["gh", "api", "--method", "POST", f"repos/{repo}/git/refs",
                        "-f", "ref=refs/tags/" + tag, "-f", "sha=" + source], check=True)
    archive = args.archive.resolve()
    checksum = archive.with_suffix(archive.suffix + ".sha256")
    checksum.write_text(hashlib.sha256(body).hexdigest() + "  " + archive.name + "\n")
    releases = json.loads(subprocess.check_output(["gh", "api", "--paginate", "--slurp", f"repos/{repo}/releases?per_page=100"], text=True))
    release = next((item for page in releases for item in page if item["tag_name"] == tag), None)
    if release:
        # Verify already published asset bytes before any retry resumes.
        for asset in release["assets"]:
            if asset["name"] in (archive.name, checksum.name):
                expected = body if asset["name"] == archive.name else checksum.read_bytes()
                if fetch(asset["browser_download_url"]) != expected:
                    raise ValueError("Existing GitHub asset differs; never overwrite it")
        names = {asset["name"] for asset in release["assets"]}
        missing = [str(path) for path in (archive, checksum) if path.name not in names]
        if missing:
            subprocess.run(["gh", "release", "upload", tag, "--repo", repo, *missing], check=True)
    else:
        subprocess.run(["gh", "release", "create", tag, "--repo", repo, "--verify-tag", "--prerelease",
                        "--title", tag, "--notes-file", str(root / "CHANGELOG.md"),
                        str(archive), str(checksum)], check=True)
    # GitHub tag/release notifications drive Packagist indexing. A missing package or
    # delayed webhook fails visibly after bounded retries, retaining immutable assets.
    for attempt in range(4):
        try:
            verify_packagist(json.loads(fetch("https://packagist.org/packages/janalis/php-profiler.json")), args.version, source)
            break
        except (ValueError, OSError):
            if attempt == 3:
                raise
            time.sleep(10)
    print(json.dumps({"version": args.version, "source": source, "sha256": hashlib.sha256(body).hexdigest(),
                      "packagist": "verified", "github": "published"}))


if __name__ == "__main__":
    main()
