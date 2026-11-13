.PHONY: help doctor fmt fmt-check check lint build test test-php test-matrix coverage mutation clean
export PATH := $(CURDIR)/target/tools/bin:$(PATH)
CLANG_FORMAT ?= $(if $(wildcard target/tools/venv/bin/clang-format),target/tools/venv/bin/clang-format,clang-format)
PYTHON ?= $(if $(wildcard target/tools/venv/bin/python),target/tools/venv/bin/python,python3)
MAGO ?= $(if $(wildcard target/tools/bin/mago),target/tools/bin/mago,mago)
PHP_FILES := $(shell find php tests -type f -name '*.php' -not -path '*/target/*')
C_FILES := $(shell find crates tests -type f -not -path '*/target/*' \( -name '*.c' -o -name '*.h' \))
help:
	@echo 'doctor fmt fmt-check lint build test test-php test-matrix coverage mutation check clean'
doctor:
	$(PYTHON) scripts/check-tool-versions.py "$(MAGO)" "$(CLANG_FORMAT)"
	rustc --version
	cargo --version
	php --version
	php-config --version
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
	$(PYTHON) -m unittest discover -s tests/python
test-php: build
	scripts/php-tests.sh
test-matrix:
	scripts/docker-matrix.sh
coverage:
	scripts/native-coverage.sh
mutation:
	$(PYTHON) scripts/rust-mutation.py --timeout 240 --jobs 2
	scripts/c-mutation.sh
check: fmt-check lint test test-php
clean:
	$(PYTHON) scripts/clean-builds.py

.PHONY: package package-check verify
package:
	$(PYTHON) scripts/package-source.py --maintainer "$(PECL_MAINTAINER)" --name "$(PECL_NAME)" --email "$(PECL_EMAIL)"
package-check:
	$(PYTHON) -m json.tool composer.json >/dev/null
	sh -n scripts/build-extension.sh
verify: check coverage mutation test-matrix package-check

.PHONY: release-dry-run
release-dry-run:
	npm run release:plan --silent

.PHONY: bootstrap hooks bench
bootstrap:
	python3 scripts/bootstrap-tools.py
	npm ci --ignore-scripts
hooks:
	git config --local core.hooksPath .githooks
bench:
	cargo build --locked --release -p php-profiler
	$(PYTHON) scripts/benchmark.py --extension target/release/libphp_profiler.$(if $(filter Darwin,$(shell uname -s)),dylib,so)

.PHONY: formatter-versions
formatter-versions:
	$(PYTHON) scripts/check-tool-versions.py "$(MAGO)" "$(CLANG_FORMAT)"
