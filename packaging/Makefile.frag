all: profiler-rust-build

.PHONY: profiler-rust-build
profiler-rust-build:
	@set -eu; root="$(srcdir)"; if test ! -f "$$root/Cargo.toml"; then root="$$root/.."; fi; PHP_CONFIG="$(PHP_CONFIG)" CARGO="$(CARGO)" sh "$$root/scripts/build-extension.sh" "$$root" "$(builddir)/modules"

install: profiler-rust-build
	$(mkinstalldirs) "$(INSTALL_ROOT)$(EXTENSION_DIR)"
	$(INSTALL) -m 0755 modules/php_profiler.so "$(INSTALL_ROOT)$(EXTENSION_DIR)/php_profiler.so"
