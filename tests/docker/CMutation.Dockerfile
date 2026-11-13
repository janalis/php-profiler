# Test-only pinned AMD64 PHP SDK / Rust / Mull cohort. Runtime has no network use.
# Package/compiler/libc changes fail the exact equivalence context and need review.
ARG PHP_IMAGE=php:8.3.36-cli-bookworm@sha256:232331112d3a7c5b98edeeddc3b85f610172f99352c0635fe6b3e166e47062cb
FROM ${PHP_IMAGE} AS php_sdk
RUN apt-get update && apt-get install -y --no-install-recommends libsqlite3-dev \
    && docker-php-ext-install -j2 pdo_sqlite pcntl && rm -rf /var/lib/apt/lists/*
FROM rust:1.99.1-slim-bookworm@sha256:f2c004e3d2d8b547e8cf7ffbc29205385f4e71d23e53e4e383065db5e9f822cd AS rust
FROM ubuntu:24.04@sha256:2198e4c65076e9cb0b3d695219df667c0e3b0072da64ca3c2648aca17dcecaf5
ENV DEBIAN_FRONTEND=noninteractive
# Bootstrap HTTPS trust without first resolving mutable Ubuntu package versions.
COPY --from=php_sdk /etc/ssl/certs/ca-certificates.crt /etc/ssl/certs/ca-certificates.crt
# The exact audited libc is no longer on the rolling mirror. Signed historical
# Release metadata retains its signature; only its time-based expiry is disabled.
RUN sed -i -E 's#http://(archive.ubuntu.com/ubuntu/|security.ubuntu.com/ubuntu/)#https://snapshot.ubuntu.com/ubuntu/20261001T000000Z/#g' /etc/apt/sources.list.d/ubuntu.sources \
    && echo 'Acquire::Check-Valid-Until "false";' > /etc/apt/apt.conf.d/99-profiler-snapshot \
    && apt-get -o APT::Update::Error-Mode=any update && apt-get install -y --no-install-recommends 'clang-19=1:19.1.1-1ubuntu1~24.04.2' 'libclang-cpp19=1:19.1.1-1ubuntu1~24.04.2' 'libclang-19-dev=1:19.1.1-1ubuntu1~24.04.2' \
    ca-certificates curl python3 pkg-config gcc 'libc6=2.39-0ubuntu8.9' 'libc6-dev=2.39-0ubuntu8.9' 'libc-dev-bin=2.39-0ubuntu8.9' 'libc-bin=2.39-0ubuntu8.9' ripgrep \
    libargon2-1 libcurl4t64 libedit2 libonig5 libsodium23 libsqlite3-0 libssl3t64 libxml2 libpcre2-8-0 zlib1g \
    && rm -rf /var/lib/apt/lists/*
COPY --from=php_sdk /usr/local /usr/local
COPY --from=rust /usr/local/cargo /usr/local/cargo
COPY --from=rust /usr/local/rustup /usr/local/rustup
ENV PATH="/usr/local/cargo/bin:${PATH}" RUSTUP_HOME=/usr/local/rustup CARGO_HOME=/usr/local/cargo \
    RUSTUP_TOOLCHAIN=1.99.1-x86_64-unknown-linux-gnu
RUN curl --fail --show-error --location https://github.com/mull-project/mull/releases/download/0.34.2/Mull-19-0.34.2-LLVM-19.1.1-ubuntu-amd64-24.04.deb -o /tmp/mull.deb \
    && echo '755955a2253290ec2d5d23376c0bffaa42152b806533df1ac581081ae27a0b2b  /tmp/mull.deb' | sha256sum --check \
    && apt-get update && apt-get install -y /tmp/mull.deb && rm -rf /var/lib/apt/lists/* \
    && php -n -v && php-config --version && clang-19 --version && mull-runner-19 --version
ENV CC=clang-19 CLANG_PATH=/usr/bin/clang-19 LIBCLANG_PATH=/usr/lib/llvm-19/lib \
    MULL_PLUGIN=/usr/lib/mull-ir-frontend-19 MULL_RUNNER=mull-runner-19 \
    CARGO_BUILD_JOBS=1 CARGO_INCREMENTAL=0
WORKDIR /source
COPY Cargo.toml Cargo.lock ./
COPY crates ./crates
COPY vendor ./vendor
COPY tests/support ./tests/support
RUN cargo fetch --locked \
    && cargo fetch --locked --manifest-path tests/support/exception-fixture/Cargo.toml
