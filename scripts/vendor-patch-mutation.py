#!/usr/bin/env python3
"""Target the local exception-ownership patch in an isolated source copy.

This is a small, explicit mutation experiment, not an upstream MSI claim.
The checked-out vendor source and PHP extension are never modified.
"""
from __future__ import annotations

import argparse
import difflib
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import time

ROOT = Path(__file__).resolve().parent.parent
LEAK_MARKER = "Rust exception ownership leaked across Zend bailout"
INJECTED_MARKER = "Rust exception ownership survived injected bailout"
CLONE_MARKER = "owned Rust allocation survived clone bailout"
ATTACHED_MARKER = "ATTACHED_OBJECT_REGRESSION"
ATTACHED_PROBE = r'''try {profiler_fixture_throw(4);fwrite(STDERR,"ATTACHED_OBJECT_REGRESSION: no throw\n");exit(1);}
catch(Throwable $error) {if($error::class!==Exception::class || $error->getMessage()!=="attached exception") {fwrite(STDERR,"ATTACHED_OBJECT_REGRESSION: original object lost\n");exit(1);}}''' 


def without_guard(source: str, variable: str) -> str:
    """Remove one complete single-line ownership wrapper, preserving its value."""
    prefix = f"let {'mut ' if variable == 'exception' else ''}{variable} = BailoutGuard::new("
    matching = [line for line in source.splitlines() if prefix in line]
    if len(matching) != 1 or not matching[0].endswith(");"):
        raise ValueError(f"expected exactly one single-line guard for {variable}")
    old = matching[0]
    new = old.replace(prefix, prefix.replace("BailoutGuard::new(", ""), 1)
    # Remove only the final parenthesis of the guard, leaving nested calls intact.
    new = new[:-2] + ";"
    changed = source.replace(old, new, 1)
    if variable == "err":
        # Error does not Deref: preserve formatting semantics in this coherent mutant.
        if changed.count("&*err") != 1:
            raise ValueError("expected one guarded Error formatting reference")
        changed = changed.replace("&*err", "&err", 1)
    return changed


def without_fallback_guard(source: str) -> str:
    start = "let fallback = BailoutGuard::new(format!("
    end = "            let _ = throw_with_code(ce::error(), 0, &fallback);"
    if source.count(start) != 1 or source.count(end) != 1:
        raise ValueError("unexpected fallback formatting source")
    beginning = source.index(start)
    ending = source.index(end, beginning)
    block = source[beginning:ending]
    close = block.rfind("));")
    if close < 0:
        raise ValueError("missing fallback wrapper terminator")
    block = block[:close] + ");" + block[close + 3:]
    block = block.replace(start, "let fallback = format!(", 1)
    return source[:beginning] + block + source[ending:]


def without_attached_object(source: str) -> str:
    original = "match exception.object.take()"
    if source.count(original) != 1:
        raise ValueError("expected one attached-object ownership transfer")
    return source.replace(original, "match None", 1)



def with_owned_clone_message(source: str) -> str:
    start = source.index("            Ok(None) => {", source.index("match cloned_val"))
    end = source.index("            Err(panic) => {", start)
    if "c\"Trying to clone an uncloneable object of class %s\"" not in source[start:end]:
        raise ValueError("expected the borrowed uncloneable-handler message")
    restored = '''            Ok(None) => {
                let msg = CString::new(format!(
                    "Trying to clone an uncloneable object of class {}",
                    T::CLASS_NAME
                ))
                .expect("Failed to create error message");
                unsafe { zend_throw_error(ptr::null_mut(), msg.as_ptr()) };
                Self::released_placeholder::<T>()
            }
'''
    return source[:start] + restored + source[end:]

def execute(command: list[str], cwd: Path, env: dict[str, str], log: Path,
            timeout: int) -> tuple[int | None, str]:
    try:
        result = subprocess.run(command, cwd=cwd, env=env, text=True,
                                stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
                                timeout=timeout)
        output = result.stdout
        code = result.returncode
    except subprocess.TimeoutExpired as failure:
        output = failure.stdout or b""
        if isinstance(output, bytes):
            output = output.decode(errors="replace")
        output += "\nMUTATION DRIVER TIMEOUT\n"
        code = None
    log.write_text(output)
    return code, output


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--php", default=os.environ.get("PHP", "php"))
    parser.add_argument("--php-config", default=os.environ.get("PHP_CONFIG", "php-config"))
    parser.add_argument("--toolchain", default=os.environ.get("PROFILER_VENDOR_TOOLCHAIN"))
    parser.add_argument("--output-dir", type=Path, default=ROOT / "target/vendor-patch-mutation")
    parser.add_argument("--timeout", type=int, default=600)
    args = parser.parse_args()
    args.php = shutil.which(args.php) or args.php
    args.php_config = shutil.which(args.php_config) or args.php_config
    output = args.output_dir.resolve()
    output.mkdir(parents=True, exist_ok=True)
    env = dict(os.environ, PHP=args.php, PHP_CONFIG=args.php_config)
    # Always rebuild our copied fixture; never accidentally reuse a caller's DSO.
    env.pop("PROFILER_FIXTURE_EXTENSION", None)
    env.pop("PROFILER_TEST_CLONE_BAILOUT", None)
    env.pop("PROFILER_TEST_EXCEPTION_BAILOUT", None)
    cargo = [os.environ.get("CARGO", "cargo")]
    if args.toolchain:
        cargo.append("+" + args.toolchain)
    summary: dict = {"scope": "targeted local exception ownership, attached-object semantics and clone-error ownership",
                     "not_full_upstream_msi": True, "trials": [],
                     "php": args.php, "php_config": args.php_config}
    with tempfile.TemporaryDirectory(prefix="profiler-vendor-mutation-") as temporary:
        isolated = Path(temporary)
        shutil.copytree(ROOT / "vendor/ext-php-rs", isolated / "vendor/ext-php-rs",
                        ignore=shutil.ignore_patterns("target", "__pycache__", "mutants.out"))
        shutil.copytree(ROOT / "tests/support/exception-fixture",
                        isolated / "tests/support/exception-fixture",
                        ignore=shutil.ignore_patterns("target", "__pycache__", "mutants.out"))
        (isolated / "scripts").mkdir()
        for name in ("test-vendored-exception.py", "exception_fixture_support.py", "clone-bailout-tests.py", "exception-bailout-tests.py"):
            shutil.copy2(ROOT / "scripts" / name, isolated / "scripts")
        (isolated / "tests/native").mkdir(parents=True)
        for fixture in (ROOT / "tests/native").glob("vendored-exception*.php"):
            shutil.copy2(fixture, isolated / "tests/native")
        for name in ("clone-bailout.c", "clone-bailout.php", "exception-bailout.c", "exception-bailout.php"):
            shutil.copy2(ROOT / "tests/native" / name, isolated / "tests/native")
        source_path = isolated / "vendor/ext-php-rs/src/exception.rs"
        handler_path = isolated / "vendor/ext-php-rs/src/zend/handlers.rs"
        handler_original = handler_path.read_text()
        original = source_path.read_text()
        summary["source_sha256"] = hashlib.sha256(original.encode()).hexdigest()
        summary["handler_source_sha256"] = hashlib.sha256(handler_original.encode()).hexdigest()
        mutants = [("exception", lambda text: without_guard(text, "exception")),
                   ("message", lambda text: without_guard(text, "message")),
                   ("fallback", without_fallback_guard),
                   ("attached_object", without_attached_object),
                   ("clone_message", with_owned_clone_message)]
        absent = []
        if "let class = BailoutGuard::new(" in original:
            mutants.insert(1, ("class", lambda text: without_guard(text, "class")))
        else:
            absent.append("borrowed class-name guard")
        if "let err = BailoutGuard::new(err);" in original:
            mutants.insert(1, ("error", lambda text: without_guard(text, "err")))
        else:
            absent.append("heap-free Error guard")
        summary["absent_nonowning_guards"] = absent
        compiled = output / "compiled-fixture"
        build = cargo + ["build", "--locked", "--manifest-path",
                         "tests/support/exception-fixture/Cargo.toml", "--target-dir", str(compiled)]
        suffix = "dylib" if sys.platform == "darwin" else "so"
        runtime_env = dict(env, PROFILER_FIXTURE_EXTENSION=str(
            compiled / "debug" / ("libprofiler_exception_fixture." + suffix)))
        clone_env = dict(env, CARGO_TARGET_DIR=str(output / "compiled-clone-fixture"))
        if args.toolchain:
            clone_env["PROFILER_VENDOR_TOOLCHAIN"] = args.toolchain
        clone_code, _ = execute([sys.executable, "scripts/clone-bailout-tests.py"],
                                isolated, clone_env, output / "baseline.clone.log", args.timeout)
        summary["baseline_proofs"] = {"clone": clone_code}
        (output / "results.json").write_text(json.dumps(summary, indent=2) + "\n")
        if clone_code != 0:
            print("Unmutated clone ownership baseline failed; stopping.", file=sys.stderr)
            return 1
        injected_env = dict(env, CARGO_TARGET_DIR=str(output / "compiled-exception-bailout-fixture"))
        if args.toolchain:
            injected_env["PROFILER_VENDOR_TOOLCHAIN"] = args.toolchain
        injected_code, _ = execute([sys.executable, "scripts/exception-bailout-tests.py"],
                                   isolated, injected_env, output / "baseline.injected.log", args.timeout)
        summary["baseline_proofs"]["injected_exception"] = injected_code
        (output / "results.json").write_text(json.dumps(summary, indent=2) + "\n")
        if injected_code != 0:
            print("Unmutated injected exception ownership baseline failed; stopping.", file=sys.stderr)
            return 1
        for name, mutation in [("baseline", None)] + mutants:
            started = time.monotonic()
            source_path.write_text(original)
            handler_path.write_text(handler_original)
            selected_path = handler_path if name == "clone_message" else source_path
            selected_original = handler_original if name == "clone_message" else original
            mutated_source = selected_original if mutation is None else mutation(selected_original)
            selected_path.write_text(mutated_source)
            if mutation is not None:
                (output / (name + ".diff")).write_text("".join(difflib.unified_diff(
                    selected_original.splitlines(keepends=True), mutated_source.splitlines(keepends=True),
                    fromfile=selected_path.name, tofile=selected_path.name + " (mutant)")))
            code, _ = execute(build, isolated, env, output / (name + ".build.log"), args.timeout)
            if code != 0:
                status = "timeout" if code is None else "unviable"
            else:
                if name == "clone_message":
                    test_command = [sys.executable, "scripts/clone-bailout-tests.py"]
                elif name == "attached_object":
                    test_command = [args.php, "-n", "-d", "extension=" + runtime_env["PROFILER_FIXTURE_EXTENSION"], "-r", ATTACHED_PROBE]
                else:
                    test_command = [sys.executable, "scripts/test-vendored-exception.py"]
                code, text = execute(test_command, isolated, clone_env if name == "clone_message" else runtime_env,
                                     output / (name + ".test.log"), args.timeout)
                if name not in ("baseline", "clone_message", "attached_object"):
                    natural_code, natural_text = code, text
                    injected_code, injected_text = execute(
                        [sys.executable, "scripts/exception-bailout-tests.py"], isolated,
                        injected_env, output / (name + ".injected.log"), args.timeout)
                    if (natural_code not in (0, None) and LEAK_MARKER not in natural_text or
                            injected_code not in (0, None) and INJECTED_MARKER not in injected_text):
                        code, text = 1, "unexpected natural/injected ownership test failure"
                    elif natural_code is None or injected_code is None:
                        code, text = None, natural_text + injected_text
                    else:
                        code = 1 if natural_code or injected_code else 0
                        text = natural_text + injected_text
                if code is None:
                    status = "timeout"
                elif code == 0:
                    status = "passed" if mutation is None else "missed"
                elif name == "clone_message" and CLONE_MARKER in text:
                    status = "clone_ownership_rejected"
                elif LEAK_MARKER in text or INJECTED_MARKER in text:
                    status = "ownership_rejected"
                elif name == "attached_object" and ATTACHED_MARKER in text:
                    status = "attached_semantics_rejected"
                else:
                    status = "other_failure"
            trial = {"name": name, "status": status, "exit_code": code,
                     "changed_file": str(selected_path.relative_to(isolated)),
                     "source_sha256": hashlib.sha256(mutated_source.encode()).hexdigest(),
                     "seconds": round(time.monotonic() - started, 3)}
            summary["trials"].append(trial)
            (output / "results.json").write_text(json.dumps(summary, indent=2) + "\n")
            print(f"{name}: {status}", flush=True)
            if mutation is None and status != "passed":
                print("Unmutated native ownership baseline failed; stopping.", file=sys.stderr)
                return 1
    trials = summary["trials"][1:]
    summary["raw_counts"] = {status: sum(t["status"] == status for t in trials)
                             for status in ("ownership_rejected", "attached_semantics_rejected", "clone_ownership_rejected", "missed", "unviable",
                                            "timeout", "other_failure")}
    summary["generated"] = len(trials)
    (output / "results.json").write_text(json.dumps(summary, indent=2) + "\n")
    print(json.dumps(summary["raw_counts"], sort_keys=True), flush=True)
    # Survivors are never relabeled or silently excluded; equivalence needs review.
    return 0 if all(t["status"] == ("attached_semantics_rejected" if t["name"] == "attached_object" else "clone_ownership_rejected" if t["name"] == "clone_message" else "ownership_rejected") for t in trials) else 1


if __name__ == "__main__":
    raise SystemExit(main())
