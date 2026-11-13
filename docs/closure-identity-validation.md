# Closure cache lifetime validation

These are source-qualified local integration receipts, separate from final
compatibility-matrix, coverage, mutation and production-readiness gates. The
runtime identifies a Zend runtime-cache lifetime, not an opcode address or a
promise to group every scope binding by one source site. See the lifecycle and
failure contract in [the unsafe inventory](unsafe-inventory.md#closure-cache-identity-lifecycle).

## Concurrent ZTS requests with real shared code

`scripts/zts-thread-tests.py` compiles `tests/native/zts_threads.c` against a real
ZTS embed library. Four pthreads each execute 20 complete PHP requests through
the public embed/TSRM lifecycle. They include the same real PHP fixture file;
OPcache mode warms that file before starting the threads. The SAPI name remains
`embed`. A test-only SAPI function, registered during its startup callback while
preserving default SAPI functions, reads genuine live closure opcode/runtime
cache addresses through public Zend APIs. It does not assign identities or
modify Zend functions.

Local macOS arm64 / PHP 8.5.12 ZTS results:

| Configuration | Requests/reports | Closure rows / calls per report | Actually overlapping leaf intervals | Storage evidence |
| --- | --- | --- | --- | --- |
| OPcache disabled | 80 / 80 | 21 / 64 | 4 | Independently compiled request isolation |
| OPcache enabled | 80 / 80 | 21 / 64 | 4 | One shared compiled opcode address across all requests; four distinct writable runtime-cache addresses in every concurrent round |

Every report was complete, started at session ID 1, preserved its distinct
application token/checksum, and contained the exact named-leaf/internal-call
counts. Twelve instances of the common compiled factory aggregated into one
24-call row. Twenty sequential eval code lifetimes each produced their own
two-call row. Each request wrote a unique local JSON report. PHP 8.5.12 on this
host does not provide a JIT build; this ZTS result makes no JIT claim.

Receipt: `/tmp/php-profiler-zts-closure-threads-final.log`.
Observed loaded extension SHA-256:
`e7fe4b1ec819e8f2e5a41051151ed29eae19b1d74be1154d08c0fa631eb3da15`.
The artifact's modification time preceded the receipt; this is a particular
local debug artifact, not certification of a later rebuilt matrix artifact.

## Same FPM worker, OPcache, JIT and preloading

`scripts/fpm-closure-tests.py` issues 32 concurrent-client requests through one
`pm=static`, one-child FPM worker. The worker PID must remain unchanged and every
request must produce session ID 1, 21 closure rows, 64 total closure calls and
the exact application result. Preload modes require the common functions to
exist before the entry file includes them. JIT-required modes demand actual
`enabled=true`, `on=true` and observed JIT code-buffer consumption.

Linux arm64 / PHP 8.3.36 NTS FPM results using the isolated Clang 14 CUNIT artifact:

| Mode | Requests | Same worker | OPcache file cached | Functions preloaded | JIT on | Generated JIT bytes observed |
| --- | --- | --- | --- | --- | --- | --- |
| OPcache | 32 | yes | 32 | 0 | 0 | 0 |
| JIT | 32 | yes | 32 | 0 | 32 | 7,376 |
| Preload | 32 | yes | 32 | 32 | 0 | 0 |
| Preload + JIT | 32 | yes | 32 | 32 | 32 | 7,552 |

The debug-only, read-only closure storage probe observed 32 distinct issued UIDs
in each mode despite only two recycled runtime-cache addresses and one shared
opcode address. All independent eval row/count assertions still passed. This
establishes the tested worker/request lifetime behavior, including persistent
preloaded code; it does not claim that cache payload words themselves survived
request shutdown. The global non-resetting allocator and its separate boundary
and concurrency tests provide that policy's direct proof.

The CUNIT getter is absent from ordinary runtime builds. The tested artifact was
`target/clang-gcov-uid-evidence/run-qXL7v3DQ/debug/libphp_profiler.so`, SHA-256
`3830ed0521afbcae7b8bcd87fb5a74b43bcd4b42309739f70437d1e976269341`.
Its copied runtime source hashes were:

- C bridge: `dddb38a4bce27afd8f178cc995bff852c8d1c5bb28bb3ab6bcb3fe7c9c1d6728`.
- Rust adapter: `138f5bb121fd0835789774a977f6bbb2afca409be91a54055ec2ab60e91f1fa1`.

This older isolated UID-evidence artifact is **not** the final current
StopFacts/checked-identity adapter artifact. Final matrix runs must rebuild and
validate their own current source snapshots. Local receipts are
`/tmp/php-profiler-fpm-closure-{opcache,jit,preload,preload-jit}.log`; generated
build/receipt files are not repository deliverables.

## Reproduction

For a matching ZTS embed library and normal runtime extension:

```sh
PROFILER_EXTENSION=/absolute/path/libphp_profiler.dylib \
PROFILER_REQUIRE_EMBED_OPCACHE=1 python3 scripts/zts-thread-tests.py
```

For FPM with a separately built debug CUNIT extension exposing the read-only
UID probe:

```sh
PROFILER_EXTENSION=/absolute/path/libphp_profiler.so \
PROFILER_FPM_OPCACHE_EXTENSION=opcache \
python3 scripts/fpm-closure-tests.py --mode preload-jit --require-jit --require-uid-probe
```

Omit `PROFILER_FPM_OPCACHE_EXTENSION` when OPcache is already built in. Normal
extension builds can run the same FPM assertions without `--require-uid-probe`;
they deliberately expose no raw UID/address API. Use a matching PHP-FPM binary
and development ABI. JIT availability must be measured, not inferred from an INI
setting or a successful request alone.

## Exhaustion and recovery scope

Session, frame and context counters reject exhaustion before publishing a
reused identity. Their sequence is monotonic for the request; `resetAll()` does
not rewind it. After a forced maximum, completing an empty session does not
prove instrumentation recovery: a subsequent actual call requiring a frame ID
must still disclose incomplete capture. A new PHP request restores the local
counter space. The closure UID allocator has the wider process/module lifetime;
its saturated sequence is never rewound while a cached ID could remain live.
Existing already-issued cache IDs remain valid, while new identities fail closed.
