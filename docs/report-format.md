# JSON report versions 1 and 2

The default machine-readable contract is [report-v1.json](../schema/report-v1.json).
`schema_version` is the integer `1`. Times are integer nanoseconds and byte counts
are integers. Readers select the published schema by version; each schema is strict.
Opt-in sampling produces [version 2](../schema/report-v2.json), which preserves
the instrumentation fields and adds the independently qualified
[sampling data](sampling.md). Reports without sampling remain version 1.

The schema applies to JSON reports. `getReportJson()` preserves integer JSON
tokens. The structured `getReport()` array preserves integers outside PHP's native
integer range as decimal strings, avoiding conversion to an imprecise float.

| Field | Meaning |
| --- | --- |
| `status` | `complete`, `incomplete`, or `truncated`; completeness concerns observer-visible recorded calls, not source-level builtin opcodes or feature parity |
| `elapsed_wall_ns` | Session monotonic elapsed time |
| `thread_cpu_ns` | Observed thread CPU delta, including profiler CPU |
| `memory_delta_bytes` | Signed Zend heap boundary difference |
| `observed_boundary_peak_bytes` | Largest heap value observed at call boundaries, not exact allocation peak |
| `dropped_calls` | Calls omitted because the event budget was exhausted |
| `clock_regressions` | Backward clock observations, indicating incomplete measurements |
| `unmatched_exits` | Exit observations without a matching recorded entry |
| `functions` | Symbol-keyed aggregate metrics |
| `edges` | Caller/callee names with aggregate metrics |
| `timeline` | Frame IDs, optional parent IDs, symbol, monotonic start/end timestamps, completion flag |
| `limitations` | Human-readable measurement limitations |

Metrics contain `calls`, `inclusive_wall_ns`, `exclusive_wall_ns`, `inclusive_cpu_ns`, `exclusive_cpu_ns`, and `memory_delta_bytes`. Inclusive values include recorded descendants; exclusive values subtract their recorded time. Recursion appears as repeated frames and self-edges; summing inclusive values over functions double-counts nested execution. Truncated profiles cannot support complete attribution.

No argument values, return values, SQL strings, URLs, headers, bodies, environment, or credentials are deliberately collected. Symbols remain potentially sensitive. Reports are serialized into private same-directory temporary files, synchronized, and atomically published without replacing an existing report. Publication failure leaves the in-memory report available. A killed process can leave temporary files; SIGKILL and power loss cannot guarantee a final report.

Current format limitations: execution metadata is incomplete; JSON is one document rather than a stream of independently recoverable chunks. These are open requirements, not silently estimated fields.

The advanced report also includes `metric_provenance` (metric-to-description map),
`capabilities` (feature-to-boolean map), `operations` (SQL/HTTP/I/O symbol-category
aggregates without payload inspection), and `timeline_enabled`. Timeline entries
include `context_id`, `active_wall_ns`, `active_cpu_ns`, and `suspended_wall_ns`.
Function wall/CPU attribution pauses while its fiber is suspended; session elapsed
wall time still includes real elapsed suspension. Generator resume observations
are not guaranteed to equal logical generator invocation counts; check capabilities.

`runtime_metadata` contains execution/build context strings. Its `php_version` is the
`PHP_VERSION` constant from the headers used to build the extension, not a
separately queried runtime patch version. Builds require matching PHP headers;
the native validation binaries matched their build headers. `termination_reason`
distinguishes normal stop from shutdown/error termination. Exception counts are
in aggregate metrics; timeline `outcome` distinguishes return, exception and
incomplete observations. Exceptions are counted without their messages or payloads.

Generated closure and anonymous-class identifiers are request-local opaque names.
They preserve distinctions within one execution while withholding embedded source
paths. They are not guaranteed to align across executions with different encounter
orders; cross-profile comparison must treat such identities accordingly.

Closure rows identify Zend **runtime-cache lifetimes**. Ordinary instances of a
compiled closure site share a cache and aggregate into one row. Scope rebinding
can allocate a new cache, so repeated bindings of the same code may produce
separate rows; sum those rows for total observed calls. Fresh eval/compilation
lifetimes receive distinct identities even when Zend recycles an opcode or cache
address. A saturating process-wide atomic sequence is not reset by session or
request boundaries. The report still exposes only request-local encounter labels,
not that sequence or memory addresses. Identity failure interrupts capture and
marks it incomplete; it is not reported as a clock failure.


Call graphs describe observer-visible runtime calls. Optimizers can eliminate
side-effect-free calls, and some builtins compile to dedicated VM instructions
without an ordinary call frame. For example, PHP compiles `strlen` to a constant
or `ZEND_STRLEN`; such source-level calls need not produce function records.
The profiler does not disable these optimizations or invent missing call counts.
See PHP's [compiler implementation](https://github.com/php/php-src/blob/PHP-8.3/Zend/zend_compile.c#L3725-L3740).

`request_peak_bytes` is Zend's measured request-wide heap peak counter at stop, nullable when no
native observation is available. It includes activity before the session and,
in long-running workers, earlier jobs within the same PHP request. Application calls to `memory_reset_peak_usage()` can reset that counter independently. It must not
be presented as the session's exact peak. `hotspots` contains up to 20 function
entries ranked by exclusive wall time, descending, with symbol order breaking
ties; ranking is derived from the measured aggregate metrics.

The first stop attempt seals measurements and captures the request peak,
termination reason, native failure flags and bounded execution metadata. Those
facts remain unchanged if report allocation fails and a later stop retries it.
For these observations, “report finalization” means this measurement seal, not
the later successful allocation, JSON conversion or filesystem write.

A function metric's `exceptions` counts observed exits with an exception pending;
it includes propagation through that function. Summing it across functions is
not a count of distinct thrown exception objects or `throw` instructions.

Configuration metadata copies at most 128 bytes from each selected raw INI value.
Longer values are replaced by an explicit omission marker and
`runtime_metadata.configuration_metadata_complete` is the string `"false"`. This bounds report-owned metadata;
it does not modify PHP configuration or estimate a truncated configuration value.
PHP-version and SAPI strings supplied by the interpreter/embedder have the same
128-byte bound; oversized values are omitted and
`runtime_metadata.execution_metadata_complete` becomes `"false"`.

Ordinary identifiers containing invalid UTF-8 use an injective ASCII
`{bytes:<lowercase hex>}` display. Literal identifiers beginning with that reserved
prefix, `anonymous-class#`, `sample-anonymous-class#` or `{sample-closure#` are
escaped too, preventing literal names from impersonating generated identifiers.
Generated names are recognized
before this encoding so source paths in anonymous/closure names stay hidden.
Relative output paths resolve against PHP's working directory when written,
including ZTS virtual working directories.

`metric_overflows` counts detected arithmetic overflow events.
`unavailable_metrics` names fields whose numeric values must not be interpreted
as valid measurements, including unavailable clocks/heap accounting or overflow.
A named metric is invalid across all rows conservatively. Dependent wall-time
hotspot rankings are empty when their ranking metric is unavailable; capped
integer values are never presented as successfully measured values.

## Developer API metadata

The report accessors return the value shapes documented in the PHP stub:
`getReport()` returns an array, `getReportJson()` returns a string, and
`getReportPath()` returns a string or null. Their native wrappers transport Zend
values directly, so PHP Reflection currently declares their return types as
`mixed`. This is an arginfo limitation; it does not change the runtime value
contract or JSON schema.
