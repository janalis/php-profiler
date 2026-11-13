"""Create a reproducible PECL source package from a clean committed HEAD."""
import argparse
import io
import gzip
import re
from datetime import datetime, timezone
import subprocess
import tarfile
import xml.etree.ElementTree as ET
from pathlib import Path
import tomllib

parser = argparse.ArgumentParser()
parser.add_argument("--output", default="target/php-profiler-source.tgz")
parser.add_argument("--maintainer", required=True)
parser.add_argument("--name", required=True)
parser.add_argument("--email", required=True)
args = parser.parse_args()
root = Path(__file__).resolve().parent.parent
def git(*arguments):
    return subprocess.check_output(["git", *arguments], cwd=root)

# Require a clean reviewed tree; metadata and archive must describe identical bytes.
if git("status", "--porcelain", "--untracked-files=no").strip():
    parser.error("Commit all tracked changes before packaging")
version = tomllib.loads(git("show", "HEAD:Cargo.toml").decode())["workspace"]["package"]["version"]
if not re.fullmatch(r"0\.\d+\.\d+-alpha\.[1-9]\d*", version):
    parser.error("Only alpha distribution is enabled")
if not all((args.maintainer.strip(), args.name.strip(), args.email.strip())):
    parser.error("Maintainer identity fields must not be empty")
files = git("ls-tree", "-r", "--name-only", "-z", "HEAD").split(b"\0")
namespace = "http://pear.php.net/dtd/package-2.0"
ET.register_namespace("", namespace)
def element(parent, tag, text=None, **attributes):
    child = ET.SubElement(parent, "{" + namespace + "}" + tag, attributes)
    child.text = text
    return child
package = ET.Element("{" + namespace + "}package", {"version": "2.0"})
for key, value in [("name", "php_profiler"), ("channel", "pecl.php.net"), ("summary", "Local PHP profiler in Rust"), ("description", "Local runtime-controlled PHP profiler. See project documentation for measurement limitations.")]:
    element(package, key, value)
lead = element(package, "lead")
for key, value in [("name", args.name), ("user", args.maintainer), ("email", args.email), ("active", "yes")]:
    element(lead, key, value)
# Use the source commit date for reproducible package metadata.
timestamp = int(git("show", "-s", "--format=%ct", "HEAD"))
date = datetime.fromtimestamp(timestamp, timezone.utc).date().isoformat()
element(package, "date", date)
versions = element(package, "version")
element(versions, "release", version.replace("-alpha.", "alpha"))
element(versions, "api", "0.1.0")
stability = element(package, "stability")
element(stability, "release", "alpha")
element(stability, "api", "alpha")
element(package, "license", "MIT", uri="https://opensource.org/license/mit")
element(package, "notes", "Alpha release; consult CHANGELOG.md and documented limitations.")
contents = element(package, "contents")
directory = element(contents, "dir", name="/")
entries = {}
for item in sorted(filter(None, files)):
    path = Path(item.decode())
    if path.parts[0] in {"target", "mutants.out", ".github"}:
        continue
    entries[str(path)] = git("show", "HEAD:" + str(path))
for name in ("config.m4", "Makefile.frag"):
    entries[name] = entries["packaging/" + name]
for name in sorted(entries):
    element(directory, "file", name=name, role="src")
dependencies = element(package, "dependencies")
required = element(dependencies, "required")
php = element(required, "php")
element(php, "min", "8.3.0")
element(php, "max", "8.5.99")
installer = element(required, "pearinstaller")
element(installer, "min", "1.10.0")
element(package, "providesextension", "php_profiler")
element(package, "extsrcrelease")
output = root / args.output
output.parent.mkdir(parents=True, exist_ok=True)
with output.open("wb") as raw, gzip.GzipFile(filename="", mode="wb", fileobj=raw, mtime=timestamp) as compressed, tarfile.open(fileobj=compressed, mode="w") as archive:
    for name, body in [("package.xml", ET.tostring(package, encoding="utf-8", xml_declaration=True)), *[("php_profiler-" + version.replace("-alpha.", "alpha") + "/" + name, body) for name, body in sorted(entries.items())]]:
        info = tarfile.TarInfo(name)
        info.size = len(body)
        info.mode = 0o755 if name.endswith(".sh") else 0o644
        info.mtime = timestamp
        archive.addfile(info, io.BytesIO(body))
print(output)
