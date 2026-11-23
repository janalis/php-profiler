#include <php.h>
#include <Zend/zend_observer.h>
#include <Zend/zend_extensions.h>
#include <ext/json/php_json.h>
#include <time.h>
#include <stdint.h>
#include <stdatomic.h>
#include <limits.h>
#include <string.h>
#include <main/SAPI.h>
#include <main/fopen_wrappers.h>

extern void profiler_enter(uint64_t frame, const char *name, size_t length, const char *scope,
                           size_t scope_length, uint64_t wall, uint64_t cpu, uint64_t memory,
                           int internal, uint64_t closure_identity);
extern void profiler_observer_failed(void);
extern void profiler_exit(uint64_t frame, uint64_t wall, uint64_t cpu, uint64_t memory,
                          int exception);
extern void profiler_fiber_destroy(uint64_t pointer, uint64_t wall, uint64_t cpu, uint64_t memory);
extern void profiler_fiber_cleanup(uint64_t pointer);
extern void profiler_fiber_switch(uint64_t context, uint64_t wall, uint64_t cpu, uint64_t memory);
typedef struct {
    bool capture_active;
    bool pending_bailout;
    bool clock_failed;
    bool context_tracking;
} profiler_thread_flags;
ZEND_TLS profiler_thread_flags profiler_flags = {false, false, false, false};
/* Cache words are opaque request-runtime storage, never op-array/SHM fields. */
_Static_assert(sizeof(void *) == sizeof(uint64_t), "profiler requires 64-bit PHP cache words");
/* Monotonic process hint: capture/pending true transitions publish first. Never
 * reset: another request/thread or fork can only cause conservative extra work. */
#ifdef ZTS
static _Atomic bool profiler_ever_used = false;
static zend_always_inline void profiler_mark_used(void) {
    atomic_store_explicit(&profiler_ever_used, true, memory_order_release);
}
#else
static zend_always_inline void profiler_mark_used(void) {}
#endif
static zend_always_inline void profiler_defer_bailout(void) {
    profiler_mark_used();
    profiler_flags.pending_bailout = true;
}
static int closure_identity_handle = -1;
/* Never reset: a retained runtime cache must not collide with a later request. */
static _Atomic uint64_t last_closure_identity = 0;
/* Pure saturating sequence allocation: safe for concurrent PHP request threads. */
static uint64_t allocate_closure_identity(void) {
    uint64_t previous = atomic_load_explicit(&last_closure_identity, memory_order_relaxed);
    do {
        if (previous == UINT64_MAX)
            return 0;
    } while (!atomic_compare_exchange_weak_explicit(&last_closure_identity, &previous, previous + 1,
                                                    memory_order_relaxed, memory_order_relaxed));
    return previous + 1;
}
static uint64_t closure_generation(zend_function *function) {
    void *cache = RUN_TIME_CACHE(&function->op_array);
    if (closure_identity_handle < 0 || cache == NULL)
        return 0;
    void **slot = &ZEND_OP_ARRAY_EXTENSION(&function->op_array, closure_identity_handle);
    uint64_t identity;
    memcpy(&identity, slot, sizeof(identity));
    if (identity != 0)
        return identity;
    identity = allocate_closure_identity();
    if (identity == 0)
        return 0;
    memcpy(slot, &identity, sizeof(identity));
    return identity;
}
/* Sampling runs synchronously on the PHP thread and uses the same opaque
 * runtime-cache identity; it never invents a call or retains a Zend pointer. */
uint64_t profiler_sampling_closure_identity(zend_function *function) {
    return closure_generation(function);
}
void profiler_set_active(int active) {
#ifdef ZTS
    if (active)
        profiler_mark_used();
#endif
    profiler_flags.capture_active = active != 0;
}
void profiler_set_context_tracking(int tracking) {
    profiler_flags.context_tracking = tracking != 0;
}
void profiler_reset_clocks(void) {
    profiler_flags.clock_failed = false;
    profiler_flags.pending_bailout = false;
}
const char *profiler_php_version(void) { return PHP_VERSION; }
const char *profiler_sapi(void) { return sapi_module.name; }
int profiler_clock_failed(void) { return profiler_flags.clock_failed; }
/* Reject malformed providers and uptime beyond the u64 nanosecond domain. */
static uint64_t clock_nanoseconds(const struct timespec *time) {
    if (time->tv_sec < 0 || time->tv_nsec < 0 || time->tv_nsec >= 1000000000L) {
        profiler_flags.clock_failed = true;
        return 0;
    }
    uint64_t seconds = (uint64_t)time->tv_sec;
    uint64_t nanoseconds = (uint64_t)time->tv_nsec;
    if (seconds > (UINT64_MAX - nanoseconds) / UINT64_C(1000000000)) {
        profiler_flags.clock_failed = true;
        return 0;
    }
    return seconds * UINT64_C(1000000000) + nanoseconds;
}
/* Read-only engine access and OS clocks cannot trigger Zend bailout. */
uint64_t profiler_wall(void) {
    struct timespec t;
    if (clock_gettime(CLOCK_MONOTONIC, &t)) {
        profiler_flags.clock_failed = true;
        return 0;
    }
    return clock_nanoseconds(&t);
}
uint64_t profiler_cpu(void) {
    struct timespec t;
    if (clock_gettime(CLOCK_THREAD_CPUTIME_ID, &t)) {
        profiler_flags.clock_failed = true;
        return 0;
    }
    return clock_nanoseconds(&t);
}
uint64_t profiler_memory(void) { return zend_memory_usage(0); }
uint64_t profiler_request_peak(void) { return zend_memory_peak_usage(0); }
int profiler_has_zend_mm(void) { return is_zend_mm(); }
static int profiler_control(zend_execute_data *data) {
    zend_string *scope = data->func->common.scope ? data->func->common.scope->name : NULL;
    return scope && (zend_string_equals_literal(scope, "PhpProfiler\\Profiler") ||
                     zend_string_equals_literal(scope, "PhpProfiler\\Session"));
}
static zend_never_inline void profiler_begin_active(zend_execute_data *data) {
    if (profiler_control(data))
        return;
    zend_string *name = data->func->common.function_name;
    zend_string *scope = data->func->common.scope ? data->func->common.scope->name : NULL;
    uint64_t identity = 0;
    if (data->func->type == ZEND_USER_FUNCTION &&
        (data->func->common.fn_flags & ZEND_ACC_CLOSURE) &&
        !(data->func->common.fn_flags & ZEND_ACC_FAKE_CLOSURE)) {
        identity = closure_generation(data->func);
        if (identity == 0) {
            profiler_observer_failed();
            profiler_flags.capture_active = false;
            return;
        }
    }
    profiler_enter((uint64_t)(uintptr_t)data, name ? ZSTR_VAL(name) : "{main}",
                   name ? ZSTR_LEN(name) : 6, scope ? ZSTR_VAL(scope) : NULL,
                   scope ? ZSTR_LEN(scope) : 0, profiler_wall(), profiler_cpu(), profiler_memory(),
                   data->func->type == ZEND_INTERNAL_FUNCTION, identity);
}
static zend_never_inline void profiler_end_active(zend_execute_data *data) {
    if (profiler_control(data))
        return;
    profiler_exit((uint64_t)(uintptr_t)data, profiler_wall(), profiler_cpu(), profiler_memory(),
                  EG(exception) != NULL);
}
/* Keep disabled dispatch out of the register-heavy active instrumentation bodies. */
#ifdef ZTS
static zend_never_inline void profiler_begin_used(zend_execute_data *data)
#else
static void profiler_begin(zend_execute_data *data)
#endif
{
    if (!profiler_flags.capture_active)
        return;
    profiler_begin_active(data);
}
#ifdef ZTS
static zend_never_inline void profiler_end_used(zend_execute_data *data, zval *retval)
#else
static void profiler_end(zend_execute_data *data, zval *retval)
#endif
{
    (void)retval;
    if (profiler_flags.pending_bailout) {
        profiler_flags.pending_bailout = false;
        zend_bailout();
    }
    if (!profiler_flags.capture_active)
        return;
    profiler_end_active(data);
}
#ifdef ZTS
static void profiler_begin(zend_execute_data *data) {
    if (!atomic_load_explicit(&profiler_ever_used, memory_order_acquire))
        return;
    profiler_begin_used(data);
}
static void profiler_end(zend_execute_data *data, zval *retval) {
    if (!atomic_load_explicit(&profiler_ever_used, memory_order_acquire))
        return;
    profiler_end_used(data, retval);
}
#endif
static zend_observer_fcall_handlers profiler_init(zend_execute_data *data) {
    (void)data;
    return (zend_observer_fcall_handlers){profiler_begin, profiler_end};
}
int profiler_register(void) {
    /* Reject invalid/capacity-exhausted handle state before changing registration. */
    if (zend_op_array_extension_handles < 0 ||
        zend_op_array_extension_handles >= INT_MAX / (int)sizeof(void *))
        return FAILURE;
    int handle = zend_get_op_array_extension_handle("PHP Profiler closure identity");
    if (handle < 0)
        return FAILURE;
    closure_identity_handle = handle;
    zend_observer_fcall_register(profiler_init);
    return SUCCESS;
}
static void profiler_switch(zend_fiber_context *from, zend_fiber_context *to) {
    (void)from;
    if (profiler_flags.capture_active)
        profiler_fiber_switch((uint64_t)(uintptr_t)to, profiler_wall(), profiler_cpu(),
                              profiler_memory());
}
uint64_t profiler_context(void) { return (uint64_t)(uintptr_t)EG(current_fiber_context); }
static void profiler_destroy(zend_fiber_context *context) {
    if (profiler_flags.capture_active) {
        profiler_fiber_destroy((uint64_t)(uintptr_t)context, profiler_wall(), profiler_cpu(),
                               profiler_memory());
    } else if (profiler_flags.context_tracking) {
        profiler_fiber_cleanup((uint64_t)(uintptr_t)context);
    }
}
void profiler_register_fibers(void) {
    zend_observer_fiber_switch_register(profiler_switch);
    zend_observer_fiber_destroy_register(profiler_destroy);
}
/* All allocating Zend calls here run under C-owned bailout boundaries. A bailout
 * is caught before returning to a Rust frame with live owned values. */
int profiler_json_decode(const unsigned char *text, size_t length, zval *output) {
    volatile int status;
    zend_try {
        status = php_json_decode_ex(output, (const char *)text, length,
                                    PHP_JSON_OBJECT_AS_ARRAY | PHP_JSON_BIGINT_AS_STRING, 512);
    }
    zend_catch {
        profiler_defer_bailout();
        ZVAL_NULL(output);
        status = SUCCESS;
    }
    zend_end_try();
    return status;
}
void profiler_string(const unsigned char *text, size_t length, zval *output) {
    zend_try { ZVAL_STRINGL(output, (const char *)text, length); }
    zend_catch {
        profiler_defer_bailout();
        ZVAL_NULL(output);
    }
    zend_end_try();
}

int profiler_is_zts(void) {
#ifdef ZTS
    return 1;
#else
    return 0;
#endif
}
const unsigned char *profiler_ini(const unsigned char *name, size_t length, size_t *value_length) {
    *value_length = 0;
    if (!EG(ini_directives))
        return NULL;
    zend_ini_entry *entry = zend_hash_str_find_ptr(EG(ini_directives), (const char *)name, length);
    if (!entry || !entry->value)
        return NULL;
    *value_length = ZSTR_LEN(entry->value);
    return (const unsigned char *)ZSTR_VAL(entry->value);
}

/* Suppress warnings: invoking a user error handler beneath Rust is forbidden. */
int profiler_path_allowed(const char *path) {
    volatile bool allowed = false;
    zend_try { allowed = php_check_open_basedir_ex(path, false) == 0; }
    zend_catch { profiler_defer_bailout(); }
    zend_end_try();
    return allowed;
}

/* Interpret relative paths using Zend virtual CWD, including ZTS requests. */
int profiler_resolve_path(const char *path, unsigned char *output, size_t capacity) {
    char resolved[MAXPATHLEN];
    volatile int success = 0;
    zend_try {
        if (expand_filepath(path, resolved) != NULL) {
            size_t size = strlen(resolved);
            if (size < capacity) {
                memcpy(output, resolved, size + 1);
                success = 1;
            }
        }
    }
    zend_catch { profiler_defer_bailout(); }
    zend_end_try();
    return success;
}
static ZEND_INI_DISP(profiler_secret_display);
PHP_INI_BEGIN()
PHP_INI_ENTRY("php_profiler.mode", "off", PHP_INI_SYSTEM, NULL)
PHP_INI_ENTRY_EX("php_profiler.trigger_secret", "", PHP_INI_SYSTEM, NULL, profiler_secret_display)
PHP_INI_ENTRY("php_profiler.output_dir", "", PHP_INI_SYSTEM, NULL)
PHP_INI_ENTRY("php_profiler.timeline", "0", PHP_INI_SYSTEM, NULL)
PHP_INI_ENTRY("php_profiler.sampling", "0", PHP_INI_SYSTEM, NULL)
PHP_INI_ENTRY("php_profiler.max_events", "512", PHP_INI_SYSTEM, NULL)
PHP_INI_END()
int profiler_config_register(int number) { return zend_register_ini_entries(ini_entries, number); }
void profiler_config_unregister(int number) { zend_unregister_ini_entries(number); }
static int profiler_materialize_server(void) {
    volatile int success = 0;
    zend_try {
        zend_is_auto_global_str(ZEND_STRL("_SERVER"));
        success = 1;
    }
    zend_catch { profiler_defer_bailout(); }
    zend_end_try();
    return success;
}
/* Whether the SAPI's name is one of the "|"-delimited names in `list`. A truncated
 * name matches no entry. */
static int profiler_sapi_in(const char *list) {
    char name[64];
    snprintf(name, sizeof(name), "|%s|", sapi_module.name);
    return strstr(list, name) != NULL;
}
/* FrankenPHP 1.1+'s frankenphp_request_headers(), which reads the request's headers alone;
 * NULL under every other SAPI. */
static zend_function *profiler_request_headers;
/* Calls frankenphp_request_headers() into `headers`, which the caller releases. The call
 * may allocate: contain bailout. */
static void profiler_read_request_headers(zval *headers) {
    zend_try {
        zend_call_known_function(profiler_request_headers, NULL, NULL, headers, 0, NULL, NULL);
    }
    zend_catch {
        /* A bailout may leave a half-built array: never read or release it. */
        profiler_defer_bailout();
        ZVAL_UNDEF(headers);
    }
    zend_end_try();
}
static void profiler_log(const char *message) {
    zend_try { php_log_err((char *)message); }
    zend_catch { profiler_defer_bailout(); }
    zend_end_try();
}
/* Whether the SAPI answers HTTP_* lookups from the request alone: FPM and NGINX Unit
 * ("cli-server") from request parameters, Apache from its request table, the built-in
 * server and LiteSpeed in server mode from headers, FrankenPHP from
 * frankenphp_request_headers(). Every other SAPI may return the process environment
 * (php-cgi falls back to it, phpdbg, embed and phpmicro import it into _SERVER, as does
 * FrankenPHP before 1.1, whose getenv also serves it), so it is distrusted. */
static int profiler_trusted_sapi;
/* Whether the SAPI's getenv answers HTTP_* from the request: FastCGI parameters, Apache's
 * request table, LiteSpeed's headers. Elsewhere getenv may serve only the process
 * environment (FrankenPHP), so the request's headers are read from _SERVER. */
static int profiler_request_getenv;
/* 0 when the SAPI is trusted or PHP started without HTTP_X_PHP_PROFILER_TRIGGER, 1 when a
 * distrusted SAPI started with it and that is not yet reported, 2 once reported. */
static atomic_int profiler_inherited_trigger;
/* Whether the SAPI is FrankenPHP, as the last startup snapshot found. */
static bool profiler_frankenphp;
/* Runs at module startup, before any ZTS request thread can putenv() and rewrite environ,
 * and before profiler_worker_register(), which reads profiler_frankenphp. */
void profiler_trigger_snapshot(void) {
    profiler_frankenphp = profiler_sapi_in("|frankenphp|");
    /* FrankenPHP's _SERVER starts from the process environment and ends with its
     * configured `env` values, so only its header function is request-owned. */
    profiler_request_headers =
        profiler_frankenphp
            ? zend_hash_str_find_ptr(CG(function_table), ZEND_STRL("frankenphp_request_headers"))
            : NULL;
    profiler_trusted_sapi = profiler_request_headers != NULL ||
                            profiler_sapi_in("|fpm-fcgi|apache2handler|cli-server|litespeed|");
    profiler_request_getenv = profiler_sapi_in("|fpm-fcgi|cgi-fcgi|apache2handler|litespeed|");
    atomic_store(&profiler_inherited_trigger,
                 !profiler_trusted_sapi && getenv("HTTP_X_PHP_PROFILER_TRIGGER") != NULL);
}
/* SAPI-owned request values only. Returns -1 when no trigger header was requested or
 * the process environment may shadow every request's header (reported once), 0 for a
 * header that does not match and 1 for a match. */
int profiler_trigger_matches(const unsigned char *secret, size_t length) {
    /* The CLI serves no request to trigger. Neither does a header-only SAPI without a
     * request method, such as LiteSpeed's command-line mode or `php-fpm -i`: before
     * serving requests, both read the process environment instead of headers. */
    if (!strcmp(sapi_module.name, "cli") ||
        (profiler_trusted_sapi && !SG(request_info).request_method))
        return -1;
    if (atomic_load(&profiler_inherited_trigger)) {
        /* Reported once, even when logging bails out: retrying would abort every
         * request while logging keeps failing. */
        if (atomic_exchange(&profiler_inherited_trigger, 2) == 1)
            profiler_log("php_profiler: HTTP_X_PHP_PROFILER_TRIGGER is set in the process "
                         "environment, so trigger mode ignores every request in this process");
        return -1;
    }
    const char *value = NULL;
    zval headers;
    ZVAL_UNDEF(&headers);
    if (profiler_request_headers) {
        profiler_read_request_headers(&headers);
        /* Go's http.Header stores every request header under its canonical name. */
        zval *header =
            Z_TYPE(headers) == IS_ARRAY
                ? zend_hash_str_find(Z_ARRVAL(headers), ZEND_STRL("X-Php-Profiler-Trigger"))
                : NULL;
        if (header && Z_TYPE_P(header) == IS_STRING)
            value = Z_STRVAL_P(header);
    } else if (profiler_request_getenv && sapi_module.getenv) {
        value = sapi_module.getenv("HTTP_X_PHP_PROFILER_TRIGGER",
                                   sizeof("HTTP_X_PHP_PROFILER_TRIGGER") - 1);
    } else {
        /* The built-in HTTP server, NGINX Unit and FrankenPHP before 1.1 expose headers
         * through _SERVER only.
         * Materialization is engine-owned, but may allocate: contain bailout. */
        if (!profiler_materialize_server())
            return -1;
        zval *server = &PG(http_globals)[TRACK_VARS_SERVER];
        if (Z_TYPE_P(server) == IS_ARRAY) {
            zval *header =
                zend_hash_str_find(Z_ARRVAL_P(server), ZEND_STRL("HTTP_X_PHP_PROFILER_TRIGGER"));
            if (header && Z_TYPE_P(header) == IS_STRING)
                value = Z_STRVAL_P(header);
        }
    }
    int result;
    if (!value) {
        result = -1;
    } else if (!length || length > 4096 || strnlen(value, 4097) != length ||
               memchr(value, ',', length)) {
        result = 0;
    } else {
        volatile unsigned char difference = 0;
        for (size_t i = 0; i < length; i++)
            difference |= secret[i] ^ (unsigned char)value[i];
        result = difference == 0;
    }
    zval_ptr_dtor(&headers);
    return result;
}
void profiler_auto_log(void) {
    profiler_log(
        "php_profiler: automatic capture failed; check configuration and output directory");
}
int profiler_auto_header(const char *value) {
    if (!strcmp(sapi_module.name, "cli"))
        return 1;
    volatile int success = 0;
    zend_try {
        sapi_header_line line = {0};
        line.line = (char *)value;
        line.line_len = strlen(value);
        success = sapi_header_op(SAPI_HEADER_REPLACE, &line) == SUCCESS;
    }
    zend_catch { profiler_defer_bailout(); }
    zend_end_try();
    return success;
}
/* phpinfo() must never print the trigger secret. System-only entries have no
 * separate local value, so both columns describe the same configured value. The
 * registered "" default and every INI update store a string, never NULL. */
static ZEND_INI_DISP(profiler_secret_display) {
    (void)type;
    php_printf("%s", ZSTR_LEN(ini_entry->value) ? "********" : "no value");
}
/* FrankenPHP's worker mode serves each HTTP request inside the PHP request that runs the
 * worker script: frankenphp_handle_request() runs request hooks for FrankenPHP's own modules
 * only. The bridge therefore hands it a callback of its own, which begins a profiler request,
 * calls the application's callback and ends that request, after FrankenPHP has started the
 * HTTP request and before it sends the response's end. */
extern void profiler_worker_request_begin(void);
extern void profiler_worker_request_end(void);
#define PROFILER_WORKER_CALLBACK "PhpProfiler\\Internal\\frankenphp_request"
static zend_function *profiler_handle_request_function;
static zif_handler profiler_handle_request_original;
/* The application's callback, resolved once by the frankenphp_handle_request() call that
 * armed it. A __call or __callStatic trampoline is not kept, as in PHP's own parameter
 * parsing: FrankenPHP may never call back, and the call resolves it again. */
typedef struct {
    zend_fcall_info fci;
    zend_fcall_info_cache fcc;
} profiler_worker_call;
/* The callback of the innermost frankenphp_handle_request() call. */
ZEND_TLS profiler_worker_call *profiler_worker_handler;
static ZEND_FUNCTION(profiler_worker_callback) {
    /* Every argument is variadic, so the frame holds them all in order. */
    uint32_t count = ZEND_NUM_ARGS();
    zval *arguments = ZEND_CALL_ARG(execute_data, 1);
    /* Each frankenphp_handle_request() call arms the callback once: a direct call from the
     * application's callback finds it disarmed instead of restarting the request. */
    profiler_worker_call *call = profiler_worker_handler;
    profiler_worker_handler = NULL;
    if (!call) {
        zend_throw_error(NULL, "%s() runs only as frankenphp_handle_request()'s callback",
                         PROFILER_WORKER_CALLBACK);
        return;
    }
    call->fci.retval = return_value;
    call->fci.params = arguments;
    call->fci.param_count = count;
    profiler_worker_request_begin();
    zend_try { zend_call_function(&call->fci, &call->fcc); }
    zend_catch {
        /* A fatal error still ends the request first, as PHP's request shutdown would. */
        profiler_worker_request_end();
        zend_bailout();
    }
    zend_end_try();
    profiler_worker_request_end();
}
ZEND_BEGIN_ARG_INFO_EX(arginfo_profiler_worker_callback, 0, 0, 0)
ZEND_ARG_VARIADIC_INFO(0, arguments)
ZEND_END_ARG_INFO()
static const zend_function_entry profiler_worker_functions[] = {
    ZEND_NS_NAMED_FE("PhpProfiler\\Internal", frankenphp_request, ZEND_FN(profiler_worker_callback),
                     arginfo_profiler_worker_callback) ZEND_FE_END};
/* The callback's interned name, which FrankenPHP's frame may hold without owning it. */
static zend_string *profiler_worker_callback_name;
/* Hands the frame the application's callback back and restores the enclosing call's. */
static void profiler_worker_restore(zval *argument, profiler_worker_call *call,
                                    profiler_worker_call *previous) {
    profiler_worker_handler = previous;
    ZVAL_COPY_VALUE(argument, &call->fci.function_name);
}
/* Whether FrankenPHP's getenv hook also answers from the worker script's environment, as it
 * does from 1.13.4; set when the handler is installed. */
static bool profiler_worker_environment;
/* Whether FrankenPHP's module version, such as "v1.13.6": "major.minor.patch", optionally
 * after a "v" and before any suffix, is 1.13.4 or later. An unknown version is not. */
static bool profiler_worker_environment_version(const char *version) {
    unsigned major, minor, patch;
    int parsed;
    if (version && *version == 'v')
        version++;
    /* %u also takes a sign or leading space, which would turn "-1.0.0" into a huge major. */
    if (!version || sscanf(version, "%u.%u.%u%n", &major, &minor, &patch, &parsed) != 3 ||
        strspn(version, "0123456789.") < (size_t)parsed)
        return false;
    return major > 1 || (major == 1 && (minor > 13 || (minor == 13 && patch >= 4)));
}
/* Whether this request may run a FrankenPHP worker script. Every worker's environment holds
 * FRANKENPHP_WORKER, which FrankenPHP 1.13.4+ serves through its getenv hook. Only a request
 * where neither that hook nor _SERVER holds it is certainly not a worker. Older versions
 * cannot tell, so every call counts as a worker's there, as does any request whose _SERVER
 * cannot be read. */
static bool profiler_worker_request(void) {
    if (!profiler_worker_environment || !sapi_module.getenv ||
        sapi_module.getenv("FRANKENPHP_WORKER", sizeof("FRANKENPHP_WORKER") - 1))
        return true;
    if (!profiler_materialize_server())
        return true;
    zval *server = &PG(http_globals)[TRACK_VARS_SERVER];
    return Z_TYPE_P(server) != IS_ARRAY ||
           zend_hash_str_exists(Z_ARRVAL_P(server), ZEND_STRL("FRANKENPHP_WORKER"));
}
static ZEND_NAMED_FUNCTION(profiler_handle_request) {
    /* FrankenPHP rejects any call its own signature does not accept, a callback that is not
     * callable, and every call outside worker mode: that request's capture must not end
     * before the refusal. Its signature has one parameter, the callback; a later optional
     * one is accepted as its arginfo declares it. */
    uint32_t count = ZEND_NUM_ARGS();
    bool wrap = count >= 1 && count <= EX(func)->common.num_args;
    zval *argument = ZEND_CALL_ARG(execute_data, 1);
    profiler_worker_call call;
    if (wrap)
        wrap = zend_fcall_info_init(argument, 0, &call.fci, &call.fcc, NULL, NULL) == SUCCESS;
    if (wrap) {
        zend_release_fcall_info_cache(&call.fcc);
        wrap = profiler_worker_request();
    }
    if (!wrap) {
        profiler_handle_request_original(execute_data, return_value);
        return;
    }
    ZVAL_INTERNED_STR(argument, profiler_worker_callback_name);
    profiler_worker_call *previous = profiler_worker_handler;
    profiler_worker_handler = &call;
    /* End the worker script's start, or what ran since the last request, before FrankenPHP
     * waits for the next one: the wait belongs to no request. */
    profiler_worker_request_end();
    zend_try { profiler_handle_request_original(execute_data, return_value); }
    zend_catch {
        profiler_worker_restore(argument, &call, previous);
        zend_bailout();
    }
    zend_end_try();
    profiler_worker_restore(argument, &call, previous);
}
/* Runs at module startup, like FrankenPHP's own getenv() override. A callback name another
 * extension already took leaves worker requests uncaptured: Zend reports the duplicate. */
void profiler_worker_register(void) {
    profiler_handle_request_function = NULL;
    zend_module_entry *frankenphp =
        zend_hash_str_find_ptr(&module_registry, ZEND_STRL("frankenphp"));
    profiler_worker_environment =
        profiler_worker_environment_version(frankenphp ? frankenphp->version : NULL);
    if (!profiler_frankenphp)
        return;
    zend_function *function =
        zend_hash_str_find_ptr(CG(function_table), ZEND_STRL("frankenphp_handle_request"));
    if (!function)
        return;
    if (zend_register_functions(NULL, profiler_worker_functions, NULL, MODULE_PERSISTENT) !=
        SUCCESS)
        return;
    profiler_worker_callback_name = zend_string_init_interned(
        PROFILER_WORKER_CALLBACK, sizeof(PROFILER_WORKER_CALLBACK) - 1, 1);
    profiler_handle_request_function = function;
    profiler_handle_request_original = function->internal_function.handler;
    function->internal_function.handler = profiler_handle_request;
}
void profiler_worker_unregister(void) {
    if (!profiler_handle_request_function)
        return;
    profiler_handle_request_function->internal_function.handler = profiler_handle_request_original;
    profiler_handle_request_function = NULL;
    zend_unregister_functions(profiler_worker_functions, -1, NULL);
}
/* php_profiler.observer: whether module startup registers the Zend observers at all. It is
 * registered apart from the automatic capture settings, and the adapter reads it once, at
 * module startup: a later system-level change, such as an FPM pool's php_admin_value, can
 * neither remove registered observers nor add missing ones. */
static bool profiler_observer_value = true;
static ZEND_INI_MH(profiler_observer_update) {
    (void)entry;
    (void)mh_arg1;
    (void)mh_arg2;
    (void)mh_arg3;
    (void)stage;
    profiler_observer_value = zend_ini_parse_bool(new_value);
    return SUCCESS;
}
/* PHP_INI_ENTRY_EX supplies its own trailing comma; {0} is PHP_INI_END()'s terminator. */
static const zend_ini_entry_def profiler_observer_entries[] = {
    PHP_INI_ENTRY_EX("php_profiler.observer", "1", PHP_INI_SYSTEM, profiler_observer_update,
                     zend_ini_boolean_displayer_cb){0}};
/* Module shutdown's zend_unregister_ini_entries() removes it with the module's others. */
int profiler_observer_config_register(int number) {
    return zend_register_ini_entries(profiler_observer_entries, number);
}
int profiler_observer_setting(void) { return profiler_observer_value; }
/* Logged once per process, at the first request that configures automatic capture while
 * observers are Off. */
void profiler_observer_off_log(void) {
    profiler_log("php_profiler: php_profiler.observer is Off, so php_profiler.mode captures "
                 "nothing in this process");
}
