# Automatic request capture

Administrator-controlled PHP INI settings can start captures without application
changes. A capture starts when the request starts and ends with it. Its report is
written to a private directory on the server.

```ini
php_profiler.mode=trigger
php_profiler.trigger_secret=replace-with-a-private-random-token
php_profiler.output_dir=/var/private/php-profiles
php_profiler.timeline=0
php_profiler.sampling=0
php_profiler.max_events=512
```

```sh
curl -H "X-Php-Profiler-Trigger: $PROFILER_TOKEN" -D - https://example.test/
```

Create the absolute output directory in advance and grant the PHP worker write
access.

## Settings

Every setting is system-only (`PHP_INI_SYSTEM`). `ini_set()` cannot change it.

| Setting | Default | Values | Meaning |
| --- | --- | --- | --- |
| `php_profiler.mode` | `off` | `off`, `always`, `trigger` | When to capture. |
| `php_profiler.trigger_secret` | empty | 1–4096 bytes, no comma, no NUL | Value the trigger header must match. Only used in `trigger` mode. |
| `php_profiler.output_dir` | empty | Existing absolute directory | Where reports are written. |
| `php_profiler.timeline` | `0` | `0` or `1` | Record a timeline, as the PHP API option. |
| `php_profiler.sampling` | `0` | `0` or `1` | Enable sampling, as the PHP API option. |
| `php_profiler.max_events` | `512` | `1` through `8192` | Event limit, as the PHP API option. |

PHP's INI parser passes unquoted `Off`, `false`, `no` and `none` as an empty value,
so they also mean `off` for `mode` and `0` for the boolean settings. `On`, `true`
and `yes` mean `1`.

Errors are logged and application execution continues. An unusable setting logs
`php_profiler: automatic capture failed; check configuration and output directory`
on every request in `always` mode, on every request with a matching trigger header
in `trigger` mode, and on every request when `mode` itself is unknown. Unusable
settings include:

- an unknown `mode`;
- an empty, relative, missing or non-UTF-8 `output_dir`, or one blocked by
  `open_basedir`;
- a `timeline` or `sampling` value other than `0` or `1`;
- a `max_events` value that is not a number from 1 through 8192;
- `sampling=1` where sampling is unsupported. Sampling has the same platform limits
  as the PHP API: see [sampling](sampling.md).

An empty `output_dir` therefore disables automatic capture, with a logged
configuration error whenever capture is requested.

### FPM

Place the settings in the PHP INI loaded by the pool, or use pool entries, then
reload FPM:

```ini
php_admin_value[php_profiler.mode] = trigger
php_admin_value[php_profiler.trigger_secret] = replace-with-a-private-random-token
php_admin_value[php_profiler.output_dir] = /var/private/php-profiles
```

## Modes

- `off` (default): nothing is captured.
- `always`: every request is captured, including CLI runs.
- `trigger`: a request is captured only when its `X-Php-Profiler-Trigger` header
  matches the configured nonempty secret.

### Trigger matching

The secret is compared byte for byte with the header value. A header that is
missing or does not match starts no capture and logs nothing.

A header value never matches when it is empty, longer than 4096 bytes, or contains
a comma. Combined or comma-separated trigger values are therefore rejected.

The secret itself must be nonempty, at most 4096 bytes, and free of commas and NUL
bytes. Any other secret is a configuration error, logged whenever a request carries
the trigger header.

HTTP servers must forward the trigger header to PHP as
`HTTP_X_PHP_PROFILER_TRIGGER`. A value inherited from the PHP process environment
never triggers a capture. See [server APIs](#server-apis) for how each SAPI reads
the header.

### Protecting the secret

`phpinfo()` shows the secret as `********`, or `no value` when it is unset. Keep its
value out of public PHP configuration diagnostics anyway: PHP code running in the
same pool can read it with `ini_get()`.

## Output

A capture is written to `php-profile-<id>.json` in the output directory. The ID is
32 hexadecimal characters from 16 bytes of system entropy.

A successfully started HTTP capture queues an `X-Php-Profiler-Id: <id>` response
header. This ID describes an attempted capture, not guaranteed persistence. CLI
emits no header.

Reports are private. No report download endpoint is provided. Operators must
arrange retention and cleanup.

### Sessions

An automatic capture is an ordinary session. It occupies one of the 16 sessions a
request may hold and takes the first session ID, so applications see one fewer slot
for their own sessions.

Existing PHP API stop, disable and reset operations also apply to automatic
sessions:

| Request action | Automatic report |
| --- | --- |
| None | Written at request end, reason `request_complete`. |
| `Profiler::stop()` | Written, reason `stopped`. |
| `Profiler::disable()` | Written, reason `disabled`. |
| `Profiler::resetAll()` | Discarded, no report. |

A capture that is still running at request end includes shutdown functions and
output already flushed to the client. An uncaught exception still produces a
`request_complete` report.

## Server APIs

Some SAPIs read request headers only. Others may also read the process
environment as if it were a header. The extension decides once, at startup, which
kind the SAPI is.

On a SAPI that may read the process environment, a PHP process started with
`HTTP_X_PHP_PROFILER_TRIGGER` in its environment makes trigger mode ignore every
request in that process. This is logged once per process:
`php_profiler: HTTP_X_PHP_PROFILER_TRIGGER is set in the process environment, so trigger mode ignores every request in this process`.
A variable a host sets after PHP starts is not detected.

| SAPI | Name | Trigger header read from | Process environment |
| --- | --- | --- | --- |
| CLI | `cli` | Never read: CLI has no trigger. | Ignored. |
| php-fpm | `fpm-fcgi` | FastCGI parameters, through the SAPI. | Ignored while serving requests. |
| php-cgi | `cgi-fcgi` | FastCGI parameters, through the SAPI. | Used for missing parameters, so an inherited value disables trigger mode. |
| Apache mod_php | `apache2handler` | The request table, through the SAPI. | Ignored. |
| LiteSpeed, server mode | `litespeed` | Request headers, through the SAPI. | Ignored while serving requests. |
| Built-in server | `cli-server` | `$_SERVER`. | Ignored while serving requests. |
| NGINX Unit | `cli-server` | `$_SERVER`. | Ignored while serving requests. |
| phpdbg, embed, phpmicro | various | `$_SERVER`. | Imported into `$_SERVER`, so an inherited value disables trigger mode. |
| FrankenPHP before 1.1 | `frankenphp` | `$_SERVER`. | Imported into `$_SERVER`, so an inherited value disables trigger mode. |
| FrankenPHP 1.1+ | `frankenphp` | `frankenphp_request_headers()`. | Ignored, including FrankenPHP `env` settings. |

Notes:

- No SAPI reads a trigger outside a request, for example LiteSpeed's command-line
  mode or `php-fpm -i`. There, these SAPIs would read the process environment
  instead of headers.
- Under php-cgi with an inherited value, even a request carrying a real FastCGI
  trigger parameter is ignored. The fallback cannot be told apart from a header.
- Classic CGI, where the web server passes headers through the environment, is not
  supported.
- On FrankenPHP before 1.1, an `env` setting for the trigger header answers for
  every request. FrankenPHP 1.1 and later ignore it.
- In `always` mode the SAPI makes no difference: every request is captured.

### FrankenPHP worker mode

In worker mode, each request a worker serves through `frankenphp_handle_request()`
is captured as its own request, with its own `X-Php-Profiler-Id` header and report.
Each such request also gets its own 16 sessions.

To do this, the extension passes its own callback to FrankenPHP. That callback,
`PhpProfiler\Internal\frankenphp_request()`, calls the application's callback. It
appears in backtraces and runs only as that callback. Calling it directly throws an
`Error`.

- In `always` mode, a worker's start, up to its first `frankenphp_handle_request()`
  call, writes one report too.
- Sessions still running when a worker request ends are completed with it, as at
  the end of any request.
- Sessions started between requests are completed when the worker next calls
  `frankenphp_handle_request()`, before it waits.
- Automatic capture skips code between requests.

Outside worker mode, FrankenPHP refuses `frankenphp_handle_request()`:

| FrankenPHP | Effect of the refused call |
| --- | --- |
| 1.13.4+ | The current request's capture keeps running. |
| Before 1.13.4, or an unknown version | The refused call ends the capture. These versions cannot tell the extension whether a script is a worker. |

A call FrankenPHP rejects for its arguments, such as a callback that is not
callable, leaves the capture running on every version.

## Limitations

- Trigger mode never captures CLI runs.
- On SAPIs that may read the process environment, an inherited
  `HTTP_X_PHP_PROFILER_TRIGGER` disables trigger mode for the whole process.
- Classic CGI is not supported.
- A variable a host sets after PHP starts is not detected.
- An automatic capture uses one of the 16 sessions a request may hold.
- The response header describes an attempted capture. The report may still fail to
  persist.
- No report download endpoint, retention or cleanup is provided.
- In FrankenPHP worker mode, code between requests is not captured automatically.
- Before FrankenPHP 1.13.4, a refused `frankenphp_handle_request()` outside worker
  mode ends the capture.
- If another extension already registered
  `PhpProfiler\Internal\frankenphp_request()`, worker requests are not captured.
  Zend reports the duplicate at startup.
