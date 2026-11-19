#!/usr/bin/env bash
# Sourced helper. Appends flags to CARGO_ENCODED_RUSTFLAGS, starting from RUSTFLAGS
# when no encoded flags exist yet. Cargo splits RUSTFLAGS on whitespace, so do we.
append_rustflags() {
  local flags=() rest
  if [[ -n "${CARGO_ENCODED_RUSTFLAGS:-}" ]]; then
    rest=$CARGO_ENCODED_RUSTFLAGS
    while [[ "$rest" == *$'\x1f'* ]]; do
      flags+=("${rest%%$'\x1f'*}")
      rest=${rest#*$'\x1f'}
    done
    flags+=("$rest")
  elif [[ -n "${RUSTFLAGS:-}" ]]; then
    read -r -d '' -a flags <<< "$RUSTFLAGS" || true
  fi
  flags+=("$@")
  local IFS=$'\x1f'
  CARGO_ENCODED_RUSTFLAGS="${flags[*]}"
  export CARGO_ENCODED_RUSTFLAGS
}
