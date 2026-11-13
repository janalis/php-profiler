#!/usr/bin/env bash
# Source only in isolated Linux test builds, after exporting CC/CARGO_TARGET_DIR.
php_include_flags=$(php-config --includes) || return $?
read -r -a php_includes <<< "$php_include_flags"
# External object contents are not a Cargo input. Link an immutable content-
# addressed path so an actual native edit invalidates Cargo's link command.
fault_object=$(python3 - "$CC" "$CARGO_TARGET_DIR" "${php_includes[@]}" <<'PYOBJECT'
import fcntl
import hashlib
import json
import os
from pathlib import Path
import shlex
import subprocess
import sys
import tempfile

compiler, target = sys.argv[1], Path(sys.argv[2]).resolve()
target.mkdir(parents=True, exist_ok=True)
flags = shlex.split(os.environ.get('CFLAGS', ''))
source = Path('tests/native/bailout-fault.c')
intermediate = target / 'bailout-fault.o'
command = [compiler, *flags, '-fPIC', '-c', *sys.argv[3:], str(source), '-o', str(intermediate)]
# A stable compiler output path keeps DWARF/profile flags deterministic. The
# lock also prevents concurrent writers from hashing an unfinished object.
with (target / '.native-fault-object.lock').open('a') as lock:
    fcntl.flock(lock, fcntl.LOCK_EX)
    compiled = subprocess.run(command, stdout=sys.stderr)
    if compiled.returncode:
        sys.exit(compiled.returncode)
    contents = intermediate.read_bytes()
    digest = hashlib.sha256(contents).hexdigest()
    output = target / ('bailout-fault-' + digest + '.o')
    if not output.is_file() or hashlib.sha256(output.read_bytes()).hexdigest() != digest:
        temporary = None
        try:
            with tempfile.NamedTemporaryFile(dir=target, prefix='.native-fault-', delete=False) as stream:
                temporary = Path(stream.name)
                stream.write(contents)
            os.replace(temporary, output)
        finally:
            if temporary is not None:
                temporary.unlink(missing_ok=True)
    (target / 'native-fault-link.json').write_text(json.dumps({
        'command': command, 'CFLAGS': flags,
        'source_sha256': hashlib.sha256(source.read_bytes()).hexdigest(),
        'object_sha256': digest, 'link_object': str(output),
    }, indent=2) + '\n')
    print(output)
PYOBJECT
) || return $?
cargo_command=(cargo)
if [[ -n "${PROFILER_COVERAGE_TOOLCHAIN:-}" ]]; then cargo_command+=("+$PROFILER_COVERAGE_TOOLCHAIN"); fi
"${cargo_command[@]}" rustc -p php-profiler --lib -- \
  -C "link-arg=$fault_object" \
  -C link-arg=-Wl,--wrap=php_json_decode_ex \
  -C link-arg=-Wl,--wrap=php_check_open_basedir_ex \
  -C link-arg=-Wl,--wrap=expand_filepath \
  -C link-arg=-Wl,--wrap=_emalloc \
  -C link-arg=-Wl,--wrap=profiler_resolve_path \
  -C link-arg=-Wl,--wrap=zend_observer_fcall_register \
  -C link-arg=-Wl,--wrap=zend_get_op_array_extension_handle \
  -C link-arg=-Wl,--wrap=clock_gettime || return $?
export PROFILER_LINKED_BAILOUT_FAULT=1
