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
