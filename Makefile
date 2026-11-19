.PHONY: help doctor fmt fmt-check check lint build test test-php test-native-units test-matrix coverage mutation clean
export PATH := $(CURDIR)/target/tools/bin:$(PATH)
CLANG_FORMAT ?= $(if $(wildcard target/tools/bin/clang-format),target/tools/bin/clang-format,clang-format)
MAGO ?= $(if $(wildcard target/tools/bin/mago),target/tools/bin/mago,mago)
PHP_FILES := $(shell find php scripts tests -type f -name '*.php' -not -path '*/target/*')
C_FILES := $(shell find crates tests -type f -not -path '*/target/*' \( -name '*.c' -o -name '*.h' \))
help:
	@echo 'bootstrap doctor fmt fmt-check lint build test test-php test-native-units test-matrix coverage mutation check verify package package-install-smoke release-dry-run bench hooks clean'
doctor:
	scripts/check-tool-versions.sh "$(MAGO)" "$(CLANG_FORMAT)"
	rustc --version
	cargo --version
	php --version
	php-config --version
	shellcheck --version
	actionlint -version
	$(MAGO) --version
	$(CLANG_FORMAT) --version
fmt: formatter-versions
	cargo fmt --all
	$(MAGO) format $(PHP_FILES)
	$(CLANG_FORMAT) -i $(C_FILES)
fmt-check: formatter-versions
	cargo fmt --all -- --check
	$(MAGO) format --check $(PHP_FILES)
	$(CLANG_FORMAT) --dry-run --Werror $(C_FILES)
lint:
	cargo clippy --workspace --all-targets -- -D warnings
	shellcheck scripts/*.sh
	actionlint
build:
	cargo build --locked -p php-profiler
test:
	cargo test --locked --workspace
	scripts/php-tool.sh tests/tools/run.php
test-php: build
	scripts/php-tests.sh
test-native-units:
	scripts/native-unit-tests.sh
test-matrix:
	scripts/docker-matrix.sh
coverage:
	scripts/native-coverage.sh
mutation:
	scripts/php-tool.sh scripts/rust-mutation.php --timeout 240 --jobs 2
	scripts/c-mutation.sh
check: fmt-check lint test test-php test-native-units
clean:
	scripts/clean-builds.sh

.PHONY: package package-check verify
package:
	scripts/php-tool.sh scripts/package-source.php
package-check:
	php -n -r 'json_decode((string) file_get_contents("composer.json"), flags: JSON_THROW_ON_ERROR);'
	sh -n scripts/build-extension.sh
verify: check coverage mutation test-matrix package-check

.PHONY: release-dry-run
release-dry-run:
	npm run release:plan --silent

.PHONY: bootstrap hooks bench
bootstrap:
	scripts/php-tool.sh scripts/bootstrap-tools.php
	npm ci --ignore-scripts
hooks:
	git config --local core.hooksPath .githooks
bench:
	cargo build --locked --release -p php-profiler
	scripts/php-tool.sh scripts/benchmark.php --extension target/release/libphp_profiler.$(if $(filter Darwin,$(shell uname -s)),dylib,so)

.PHONY: formatter-versions
formatter-versions:
	scripts/check-tool-versions.sh "$(MAGO)" "$(CLANG_FORMAT)"

.PHONY: package-install-smoke
package-install-smoke: package
	scripts/package-install-smoke.sh
