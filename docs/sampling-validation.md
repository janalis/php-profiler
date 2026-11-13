# Linux ZTS sampler lifecycle validation

The durable sampler concurrency gate uses the actual Cargo extension and the
matching PHP ZTS embed library. It runs PHP through the public namespace API;
it does not replace the Rust observation sink or fabricate Zend frames.

Run it in the pinned SDK image:

```sh
docker build -f tests/docker/SamplingEmbed.Dockerfile -t profiler-sampling-embed .
mkdir -p target/zts-sampling-ci
docker run --rm --network none \
  -v "$PWD/target/zts-sampling-ci:/receipts" \
  profiler-sampling-embed \
  python3 scripts/zts-sampling-tests.py --output /receipts
```

The Dockerfile pins official PHP 8.5.12 ZTS and Rust 1.99.1 manifest digests.
It checks the inherited PHP source SHA-256, matching CLI/SDK version, ZTS flag,
embed headers/library and configure options before building the extension.
Build dependencies may use the network; all runtime jobs run without it.

The gate checks:

- Four simultaneous PHP threads, each running five isolated requests. Each
  request creates a public sampling session, executes the same function twice
  across main/Fiber contexts, and validates a real schema-v2 report, exact call
  counts, independent sample contexts and request-local session ID 1.
- Twenty-three actual sampling-worker creations and successful joins: twenty
  ordinary captures, an unfinished request followed by a fresh request on the
  same PHP thread, and an abandoned request that exercises the real TSRM
  destructor fallback without RSHUTDOWN.
- A test-only `pthread_create`/`pthread_join` observer forwards original calls
  unchanged. It verifies requested 256 KiB worker stacks, one-page guards,
  blocked worker signal masks, four overlapping workers, and no live workers
  after their owners retire. It never substitutes a sampler callback.
- Two complete PHP embed module cycles on the same owning pthread, with a
  genuine sampled session and ordinary fork after each shutdown while Rust TLS
  can retain the DSO. This catches duplicate atfork registration across module
  cycles, which would deadlock when both prepare callbacks lock one registry.
- Normal PHP module shutdown and owner-thread exit, actual extension unmapping,
  and three subsequent ordinary process forks. The Cargo-linked glibc atfork
  wrapper must pass its own DSO identity.

The PHP embed lifecycle runs on a dedicated host pthread. This matters because
Rust thread-local destructors can legitimately keep a DSO mapped after PHP
module shutdown while its owning thread remains alive. The owner returns
normally, permitting those destructors to run. The host then obtains/releases
an ordinary `RTLD_NOLOAD` inspection handle so glibc can reevaluate pending
unloading; a second inspection must find no mapping. There are no forced TLS
destructors, manual unmapping, `RTLD_NODELETE` flags or permanent loader pins.

This follows glibc's documented source-level lifetime rules:
[`pthread_atfork`](https://github.com/bminor/glibc/blob/glibc-2.36/sysdeps/pthread/pthread_atfork.c),
[`__cxa_finalize`](https://github.com/bminor/glibc/blob/glibc-2.36/stdlib/cxa_finalize.c),
[TLS destructor ownership](https://github.com/bminor/glibc/blob/glibc-2.36/stdlib/cxa_thread_atexit_impl.c)
and [unload admission](https://github.com/bminor/glibc/blob/glibc-2.36/elf/dl-close.c).
The PHP resource lifecycle uses the public
[embed](https://github.com/php/php-src/blob/PHP-8.5/sapi/embed/php_embed.c) and
[TSRM](https://github.com/php/php-src/blob/PHP-8.5/TSRM/TSRM.c) APIs.

`receipt.json` records source fingerprints before/after, extension and embed
library hashes, SDK/source/compiler versions, commands and actual outcomes.
Logs and per-request receipts remain beside it. A changed source or extension
fails qualification. The abandoned-request case proves native worker retirement;
it does not claim that PHP can recover arbitrary abandoned application state or
that intentional abandonment is leak-free.

The fork evidence has a precise scope: PHP `pcntl_fork` probes use one PHP
executor thread plus the sampler workers. This embed gate runs four PHP
executors concurrently, then forks only after they have joined. It does not
prove arbitrary forks while other PHP executor threads are still active; PHP's
own TSRM/allocator locks and embedding fork contract remain relevant.

Bounded native test commands run in owned process groups. Their watchdog kills
and reaps only those groups and descendants on timeout or interruption; it does
not use global process-name cleanup.

This gate proves Linux/glibc ZTS lifecycle behavior. It makes no OPcache or JIT
claim; those require their separate compatibility workloads. Sampling remains
subject to the documented wall-timer and VM-safepoint biases.
