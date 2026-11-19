#!/usr/bin/env bash
# Source only in isolated Linux test builds, after exporting CC/CARGO_TARGET_DIR.
php_include_flags=$(php-config --includes) || return $?
read -r -a php_includes <<< "$php_include_flags"
# External object contents are not a Cargo input. Link an immutable content-
# addressed path so an actual native edit invalidates Cargo's link command.
fault_object=$("$(dirname "${BASH_SOURCE[0]}")/php-tool.sh" "$(dirname "${BASH_SOURCE[0]}")/native-fault-object.php" \
  "$CC" "$CARGO_TARGET_DIR" "${php_includes[@]}") || return $?
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
