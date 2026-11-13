# Separate development-only edge-coverage tooling; no runtime extension dependency.
ARG BASE_IMAGE=local-php-profiler-coverage:8.3
FROM ${BASE_IMAGE}
RUN apt-get update && apt-get install -y --no-install-recommends \
    clang-14=1:14.0.6-12 llvm-14=1:14.0.6-12 libclang-rt-14-dev=1:14.0.6-12 \
    && rm -rf /var/lib/apt/lists/*
ENV CC=clang-14 LLVM_COV=llvm-cov-14
CMD ["bash", "scripts/clang-gcov.sh"]
