ARG PHP_IMAGE=php:8.3-cli-bookworm
FROM rust:1-slim-bookworm AS rust
FROM ${PHP_IMAGE}
RUN apt-get update && apt-get install -y --no-install-recommends libclang-dev clang python3 ca-certificates gcc libc6-dev pkg-config libsqlite3-dev libcurl4-openssl-dev && rm -rf /var/lib/apt/lists/*
RUN docker-php-ext-install -j2 pdo_sqlite pcntl
COPY --from=rust /usr/local/cargo /usr/local/cargo
COPY --from=rust /usr/local/rustup /usr/local/rustup
ENV PATH="/usr/local/cargo/bin:${PATH}" RUSTUP_HOME=/usr/local/rustup CARGO_HOME=/usr/local/cargo
WORKDIR /profiler
COPY Cargo.toml Cargo.lock ./
COPY crates ./crates
COPY vendor ./vendor
COPY schema ./schema
RUN --mount=type=cache,target=/usr/local/cargo/registry cargo build --release -p php-profiler
COPY .dockerignore requirements-dev.txt ./
COPY .github ./.github
COPY scripts ./scripts
COPY tests ./tests
ENV PROFILER_EXTENSION=/profiler/target/release/libphp_profiler.so CI_PROFILER_REQUIRE_SAMPLING=1
CMD ["bash", "scripts/php-tests.sh"]
