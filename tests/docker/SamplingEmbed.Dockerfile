# Official PHP ZTS images build the shared embed library and matching headers
# from PHP_SHA256-verified source (--enable-embed --enable-zts). Pin the complete
# multi-platform manifests, then assert the inherited SDK/source provenance.
ARG PHP_IMAGE=php:8.5.12-zts-bookworm@sha256:c6a2606c831a21992c2cdd0ae5a4e87af02348c4c3f66621bfb8b87702977ee3
FROM rust:1.99.1-slim-bookworm@sha256:f2c004e3d2d8b547e8cf7ffbc29205385f4e71d23e53e4e383065db5e9f822cd AS rust
FROM ${PHP_IMAGE}
ARG PHP_EXPECTED_VERSION=8.5.12
ARG PHP_EXPECTED_SOURCE_SHA256=08bc2eba2a588654ade1357174aa646b761556c862dda0678345ee29ed481209
RUN test "$PHP_VERSION" = "$PHP_EXPECTED_VERSION" \
    && test "$PHP_SHA256" = "$PHP_EXPECTED_SOURCE_SHA256" \
    && test "$(php-config --version)" = "$PHP_EXPECTED_VERSION" \
    && php -r 'exit(PHP_ZTS ? 0 : 1);' \
    && test -f /usr/local/lib/libphp.so \
    && test -f /usr/local/include/php/sapi/embed/php_embed.h \
    && php-config --configure-options | grep -- --enable-embed \
    && php-config --configure-options | grep -- --enable-zts
RUN apt-get update && apt-get install -y --no-install-recommends \
    libclang-dev clang python3 ca-certificates gcc libc6-dev pkg-config binutils \
    && rm -rf /var/lib/apt/lists/*
COPY --from=rust /usr/local/cargo /usr/local/cargo
COPY --from=rust /usr/local/rustup /usr/local/rustup
ENV PATH="/usr/local/cargo/bin:${PATH}" RUSTUP_HOME=/usr/local/rustup CARGO_HOME=/usr/local/cargo
WORKDIR /profiler
COPY . .
RUN cargo build --release --locked -p php-profiler
ENV PROFILER_EXTENSION=/profiler/target/release/libphp_profiler.so
# The CI caller must use docker run --network none. No runtime network access
# is needed by the real PHP jobs, the test observer, or the Rust extension.
CMD ["python3", "scripts/zts-sampling-tests.py"]
