/* Test-only translation unit. Including the real source gives unit assertions
 * access to private C state without exporting it or shipping test switches. */
#include <php.h>
#include <Zend/zend_extensions.h>
#include <Zend/zend_closures.h>
#include <Zend/zend_exceptions.h>
#include <stdatomic.h>
#include <stdint.h>
#include <limits.h>
#include <stdlib.h>
#include <pthread.h>
#include <string.h>
#include <unistd.h>
/* A weak compare/exchange may legally fail spuriously. The isolated test
 * transport produces one such outcome; all ordinary operations delegate. */
static bool test_spurious_failure;
static unsigned test_spurious_consumed;
static bool profiler_test_compare_weak(volatile _Atomic uint64_t *counter, uint64_t *expected,
                                       uint64_t desired, memory_order success,
                                       memory_order failure) {
    if (test_spurious_failure) {
        uint64_t observed = atomic_load_explicit(counter, failure);
        if (*expected == observed) {
            test_spurious_failure = false;
            test_spurious_consumed++;
            *expected = observed;
            return false;
        }
    }
    return atomic_compare_exchange_weak_explicit(counter, expected, desired, success, failure);
}
#undef atomic_compare_exchange_weak_explicit
#define atomic_compare_exchange_weak_explicit profiler_test_compare_weak
/* Scalar dependency responses leave the actual Zend registry untouched. */
static int profiler_test_handle_count(void) {
    const char *mode = getenv("PROFILER_TEST_BAILOUT");
    if (mode && strcmp(mode, "handles-negative") == 0) {
        (void)write(2, "INJECTED_HANDLE_COUNT_FAILURE\n", 30);
        return -1;
    }
    if (mode && strcmp(mode, "handles-capacity") == 0) {
        (void)write(2, "INJECTED_HANDLE_COUNT_FAILURE\n", 30);
        return INT_MAX / (int)sizeof(void *);
    }
    return zend_op_array_extension_handles;
}
#define zend_op_array_extension_handles profiler_test_handle_count()
#define profiler_register profiler_register_observer
#ifndef __linux__
#define profiler_fiber_cleanup profiler_test_counted_cleanup
#define profiler_fiber_destroy profiler_test_counted_destroy
#endif
#include <main/SAPI.h>
#include <sys/mman.h>
ZEND_TLS int automatic_fault;
int automatic_test_fault(void) { return automatic_fault; }
void automatic_test_log(const char *message);
extern int automatic_test_logs;
int automatic_test_header(sapi_header_op_enum op, void *argument);
bool automatic_test_global(const char *name, size_t length);
int automatic_test_register(const zend_ini_entry_def *entries, int number);
#undef php_log_err
#define php_log_err automatic_test_log
#ifndef __linux__
#define sapi_header_op automatic_test_header
#define zend_is_auto_global_str automatic_test_global
#define zend_register_ini_entries automatic_test_register
#define profiler_worker_request_begin automatic_test_worker_begin
#define profiler_worker_request_end automatic_test_worker_end
#endif
/* The transports record worker request boundaries, then run the adapter's. */
extern char automatic_worker_log[32];
void automatic_worker_note(char event);
void automatic_test_worker_begin(void);
void automatic_test_worker_end(void);
#include "../../crates/php-profiler/src/bridge.c"
#undef php_log_err
#undef sapi_header_op
#undef zend_is_auto_global_str
#undef zend_register_ini_entries
#undef profiler_worker_request_begin
#undef profiler_worker_request_end
void profiler_worker_request_begin(void);
int profiler_observer_setting(void);
#undef profiler_register
#undef profiler_fiber_cleanup
#undef profiler_fiber_destroy
#undef zend_op_array_extension_handles
#include <stdlib.h>
#include <unistd.h>

/* Count real callback delegation without changing its arguments or behavior. */
#ifdef __linux__
/* GNU link wrapping preserves the production call expressions for mutation
 * discovery; only this private fixture observes the actual Rust callbacks. */
extern void __real_profiler_fiber_cleanup(uint64_t pointer);
extern void __real_profiler_fiber_destroy(uint64_t pointer, uint64_t wall, uint64_t cpu,
                                          uint64_t memory);
#define profiler_test_counted_cleanup __wrap_profiler_fiber_cleanup
#define profiler_test_counted_destroy __wrap_profiler_fiber_destroy
#define PROFILER_TEST_REAL_CLEANUP __real_profiler_fiber_cleanup
#define PROFILER_TEST_REAL_DESTROY __real_profiler_fiber_destroy
#else
#define PROFILER_TEST_REAL_CLEANUP profiler_fiber_cleanup
#define PROFILER_TEST_REAL_DESTROY profiler_fiber_destroy
#endif
extern void profiler_fiber_cleanup(uint64_t pointer);
extern void profiler_fiber_destroy(uint64_t pointer, uint64_t wall, uint64_t cpu, uint64_t memory);
ZEND_TLS uint64_t test_cleanup_callbacks;
ZEND_TLS uint64_t test_measured_destroy_callbacks;
typedef struct {
    bool armed;
    bool checked;
    bool valid;
    /* No recording session measures CPU: the native gate must forward exactly the
     * zero placeholder instead of a clock reading. */
    bool cpu_placeholder;
    uint64_t wall_minimum;
    uint64_t cpu_minimum;
} profiler_test_fiber_observation;
ZEND_TLS profiler_test_fiber_observation test_fiber_observation;
/* Independent system-provider reference, outside the runtime mutation scope. */
static bool test_clock_observation(clockid_t clock, uint64_t *nanoseconds) {
    struct timespec value;
    if (clock_gettime(clock, &value) != 0 || value.tv_sec < 0 || value.tv_nsec < 0 ||
        value.tv_nsec >= 1000000000L)
        return false;
    uint64_t seconds = (uint64_t)value.tv_sec;
    uint64_t fraction = (uint64_t)value.tv_nsec;
    if (seconds > (UINT64_MAX - fraction) / UINT64_C(1000000000))
        return false;
    *nanoseconds = seconds * UINT64_C(1000000000) + fraction;
    return true;
}
void profiler_test_counted_cleanup(uint64_t pointer) {
    test_cleanup_callbacks++;
    PROFILER_TEST_REAL_CLEANUP(pointer);
}
void profiler_test_counted_destroy(uint64_t pointer, uint64_t wall, uint64_t cpu, uint64_t memory) {
    test_measured_destroy_callbacks++;
    if (test_fiber_observation.armed) {
        uint64_t maximum_wall = 0, maximum_cpu = 0;
        /* Neither clock reads nor TLS stores allocate Zend memory or execute PHP.
         * Compare before Rust delegation can perform any profiling work. */
        size_t actual_memory = zend_memory_usage(false);
        bool clocks_valid = test_clock_observation(CLOCK_MONOTONIC, &maximum_wall) &&
                            test_clock_observation(CLOCK_THREAD_CPUTIME_ID, &maximum_cpu);
        test_fiber_observation.valid =
            clocks_valid && wall >= test_fiber_observation.wall_minimum && wall <= maximum_wall &&
            (test_fiber_observation.cpu_placeholder
                 ? cpu == 0
                 : cpu >= test_fiber_observation.cpu_minimum && cpu <= maximum_cpu) &&
            memory == actual_memory && memory >= 1024 * 1024;
        test_fiber_observation.checked = true;
        test_fiber_observation.armed = false;
    }
    PROFILER_TEST_REAL_DESTROY(pointer, wall, cpu, memory);
}
static ZEND_FUNCTION(profiler_test_fiber_observation_arm) {
    bool cpu_placeholder = false;
    ZEND_PARSE_PARAMETERS_START(0, 1)
    Z_PARAM_OPTIONAL
    Z_PARAM_BOOL(cpu_placeholder)
    ZEND_PARSE_PARAMETERS_END();
    test_fiber_observation = (profiler_test_fiber_observation){0};
    bool valid =
        test_clock_observation(CLOCK_MONOTONIC, &test_fiber_observation.wall_minimum) &&
        test_clock_observation(CLOCK_THREAD_CPUTIME_ID, &test_fiber_observation.cpu_minimum) &&
        test_fiber_observation.cpu_minimum > 42;
    test_fiber_observation.cpu_placeholder = cpu_placeholder;
    test_fiber_observation.armed = valid;
    RETURN_BOOL(valid);
}
static ZEND_FUNCTION(profiler_test_fiber_observation_valid) {
    ZEND_PARSE_PARAMETERS_NONE();
    RETURN_BOOL(test_fiber_observation.checked && test_fiber_observation.valid &&
                !test_fiber_observation.armed);
}
static ZEND_FUNCTION(profiler_test_fiber_callbacks) {
    array_init(return_value);
    add_assoc_bool(return_value, "tracking", profiler_flags.context_tracking);
    add_assoc_long(return_value, "cleanup", test_cleanup_callbacks);
    add_assoc_long(return_value, "measured", test_measured_destroy_callbacks);
}

static ZEND_FUNCTION(profiler_test_guard_contract) {
    const char *mode = getenv("PROFILER_TEST_BAILOUT");
    bool correct = false;
    bool initially_cold = !profiler_flags.capture_active && !profiler_flags.pending_bailout;
#ifdef ZTS
    initially_cold =
        initially_cold && !atomic_load_explicit(&profiler_ever_used, memory_order_acquire);
#endif
    /* No profiler API has run: the callback must tolerate a cold NULL frame. */
    profiler_begin(NULL);
    profiler_end(NULL, NULL);
#ifdef ZTS
    /* Negative oracle: deliberately bypass the publisher in this private test
     * transport. A false hint would lose a pending fatal; the positive guard
     * below must prove its real producer prevents this invalid state. */
    profiler_flags.pending_bailout = true;
    volatile bool unexpected_rethrow = false;
    zend_try { profiler_end(NULL, NULL); }
    zend_catch { unexpected_rethrow = true; }
    zend_end_try();
    initially_cold = initially_cold && !unexpected_rethrow && profiler_flags.pending_bailout &&
                     !atomic_load_explicit(&profiler_ever_used, memory_order_acquire);
#endif
    zval value;
    profiler_flags.pending_bailout = false;
    if (mode && strcmp(mode, "decode") == 0) {
        int status = profiler_json_decode((const unsigned char *)"{}", 2, &value);
        correct = status == SUCCESS && Z_TYPE(value) == IS_NULL && profiler_flags.pending_bailout;
    } else if (mode && strcmp(mode, "string") == 0) {
        unsigned char text[8192] = {0};
        profiler_string(text, sizeof(text), &value);
        correct = Z_TYPE(value) == IS_NULL && profiler_flags.pending_bailout;
    } else if (mode && strcmp(mode, "allowed") == 0) {
        correct =
            !profiler_path_allowed("/tmp/profiler-guard-unit") && profiler_flags.pending_bailout;
    } else if (mode && strcmp(mode, "resolve") == 0) {
        unsigned char output[MAXPATHLEN];
        correct = !profiler_resolve_path("/tmp/profiler-guard-unit", output, sizeof(output)) &&
                  profiler_flags.pending_bailout;
    } else if (mode && strcmp(mode, "exact") == 0) {
        /* Root has no symlink component (macOS /tmp resolves to /private/tmp). */
        const char *path = "/profiler-guard-unit";
        unsigned char output[MAXPATHLEN];
        memset(output, 0x5a, sizeof(output));
        correct = !profiler_resolve_path(path, output, strlen(path)) && output[0] == 0x5a;
    } else if (mode && strcmp(mode, "ini") == 0) {
        size_t length = 99;
        correct =
            profiler_ini((const unsigned char *)"profiler_fixture_absent", 23, &length) == NULL &&
            length == 0;
    }
    correct = correct && initially_cold;
    if (profiler_flags.pending_bailout) {
        volatile bool rethrown = false;
#ifdef ZTS
        bool hint_published = atomic_load_explicit(&profiler_ever_used, memory_order_acquire);
#else
        bool hint_published = true;
#endif
        zend_try { profiler_end(NULL, NULL); }
        zend_catch { rethrown = true; }
        zend_end_try();
        correct = correct && hint_published && rethrown && !profiler_flags.pending_bailout;
    }
    /* A dependency bailout clears EG(current_execute_data). Exit this isolated
     * process immediately after checking the C return contract; do not resume
     * Zend using that deliberately abandoned execution frame. */
    if (correct)
        (void)write(1, "C_GUARD_CONTRACT_OK\n", 20);
#ifdef PROFILER_LLVM_COVERAGE
    /* _exit intentionally avoids resuming the abandoned Zend frame. Persist
     * real counters before exiting this isolated test process. */
    extern int __llvm_profile_write_file(void);
    if (__llvm_profile_write_file() != 0)
        _exit(43);
#endif
    _exit(correct ? 0 : 42);
}
/* These helpers run only in isolated CLI processes. Pure allocator-issued test
 * values never enter a Zend cache, so restoring that test counter cannot reuse
 * a live identity. The real overflow scenario below deliberately never restores. */
static ZEND_FUNCTION(profiler_test_uid_allocator) {
    uint64_t original = atomic_load_explicit(&last_closure_identity, memory_order_relaxed);
    if (profiler_flags.capture_active || original > UINT64_MAX - 2)
        RETURN_FALSE;
    bool correct = allocate_closure_identity() == original + 1;
    test_spurious_failure = true;
    test_spurious_consumed = 0;
    correct = allocate_closure_identity() == original + 2 && correct;
    correct = test_spurious_consumed == 1 && !test_spurious_failure && correct;
    atomic_store_explicit(&last_closure_identity, UINT64_MAX - 1, memory_order_relaxed);
    correct = allocate_closure_identity() == UINT64_MAX && correct;
    correct = allocate_closure_identity() == 0 && correct;
    correct =
        atomic_load_explicit(&last_closure_identity, memory_order_relaxed) == UINT64_MAX && correct;
    atomic_store_explicit(&last_closure_identity, original, memory_order_relaxed);
    RETURN_BOOL(correct);
}
#define UID_THREADS 4
#define UID_PER_THREAD 4096
struct uid_thread_data {
    uint64_t *values;
};
static void *uid_thread_worker(void *opaque) {
    struct uid_thread_data *data = opaque;
    for (size_t i = 0; i < UID_PER_THREAD; ++i)
        data->values[i] = allocate_closure_identity();
    return NULL;
}
static int uid_compare(const void *left, const void *right) {
    uint64_t a = *(const uint64_t *)left, b = *(const uint64_t *)right;
    return (a > b) - (a < b);
}
static ZEND_FUNCTION(profiler_test_uid_threads) {
    uint64_t original = atomic_load_explicit(&last_closure_identity, memory_order_relaxed);
    if (profiler_flags.capture_active || original > UINT64_MAX - UID_THREADS * UID_PER_THREAD)
        RETURN_FALSE;
    uint64_t *values = malloc(UID_THREADS * UID_PER_THREAD * sizeof(uint64_t));
    if (!values)
        RETURN_FALSE;
    pthread_t threads[UID_THREADS];
    struct uid_thread_data data[UID_THREADS];
    size_t created = 0;
    for (; created < UID_THREADS; ++created) {
        data[created].values = values + created * UID_PER_THREAD;
        if (pthread_create(&threads[created], NULL, uid_thread_worker, &data[created]) != 0)
            break;
    }
    for (size_t i = 0; i < created; ++i)
        pthread_join(threads[i], NULL);
    bool correct = created == UID_THREADS;
    if (correct) {
        qsort(values, UID_THREADS * UID_PER_THREAD, sizeof(uint64_t), uid_compare);
        for (size_t i = 0; i < UID_THREADS * UID_PER_THREAD; ++i) {
            if (values[i] != original + i + 1)
                correct = false;
        }
    }
    atomic_store_explicit(&last_closure_identity, original, memory_order_relaxed);
    free(values);
    RETURN_BOOL(correct);
}
static ZEND_FUNCTION(profiler_test_uid_cache) {
    zval *closure;
    ZEND_PARSE_PARAMETERS_START(1, 1)
    Z_PARAM_OBJECT_OF_CLASS(closure, zend_ce_closure)
    ZEND_PARSE_PARAMETERS_END();
    /* This is a genuine core Closure object, never a synthetic Zend frame. */
    zend_function *function = (zend_function *)zend_get_closure_method_def(Z_OBJ_P(closure));
    uint64_t issued = closure_generation(function);
    bool correct = issued != 0 && closure_generation(function) == issued;
    int saved_handle = closure_identity_handle;
    closure_identity_handle = -1;
    correct = closure_generation(function) == 0 && correct;
    closure_identity_handle = saved_handle;
    /* A real newly compiled, unexecuted op array has no runtime cache yet. */
    zend_string *source = zend_string_init("return 7;", sizeof("return 7;") - 1, false);
    zend_op_array *uncalled = zend_compile_string(source, "profiler-cache-contract",
                                                  ZEND_COMPILE_POSITION_AFTER_OPEN_TAG);
    zend_string_release(source);
    if (!uncalled)
        RETURN_FALSE;
    correct = RUN_TIME_CACHE(uncalled) == NULL &&
              closure_generation((zend_function *)uncalled) == 0 && correct;
    destroy_op_array(uncalled);
    efree(uncalled);
    RETURN_BOOL(correct);
}
/* OPcache's authentic immutable named function uses Zend's tagged map slot. */
static ZEND_FUNCTION(profiler_test_named_cache) {
    zend_string *name;
    ZEND_PARSE_PARAMETERS_START(1, 1)
    Z_PARAM_STR(name)
    ZEND_PARSE_PARAMETERS_END();
    zend_function *function = zend_hash_find_ptr(EG(function_table), name);
    if (!function || function->type != ZEND_USER_FUNCTION)
        RETURN_FALSE;
    uint64_t first = closure_generation(function);
    bool mapped = ZEND_MAP_PTR_IS_OFFSET(function->op_array.run_time_cache);
    RETURN_BOOL(mapped && first != 0 && closure_generation(function) == first);
}
/* Read-only identity receipt from a genuine Closure after its first invocation. */
static ZEND_FUNCTION(profiler_test_closure_storage) {
    zval *closure;
    ZEND_PARSE_PARAMETERS_START(1, 1)
    Z_PARAM_OBJECT_OF_CLASS(closure, zend_ce_closure)
    ZEND_PARSE_PARAMETERS_END();
    zend_function *function = (zend_function *)zend_get_closure_method_def(Z_OBJ_P(closure));
    void *cache = RUN_TIME_CACHE(&function->op_array);
    uint64_t identity = 0;
    if (cache && closure_identity_handle >= 0) {
        void **slot = &ZEND_OP_ARRAY_EXTENSION(&function->op_array, closure_identity_handle);
        memcpy(&identity, slot, sizeof(identity));
    }
    char opcodes[2 + sizeof(void *) * 2 + 1];
    char runtime_cache[2 + sizeof(void *) * 2 + 1];
    char uid[21];
    snprintf(opcodes, sizeof(opcodes), "%p", (void *)function->op_array.opcodes);
    snprintf(runtime_cache, sizeof(runtime_cache), "%p", cache);
    snprintf(uid, sizeof(uid), "%llu", (unsigned long long)identity);
    array_init(return_value);
    add_assoc_string(return_value, "opcodes", opcodes);
    add_assoc_string(return_value, "runtime_cache", runtime_cache);
    add_assoc_string(return_value, "uid", uid);
}
static ZEND_FUNCTION(profiler_test_exhaust_closure_ids) {
    atomic_store_explicit(&last_closure_identity, UINT64_MAX, memory_order_relaxed);
    RETURN_NULL();
}
static ZEND_FUNCTION(profiler_test_uid_clock_failed) { RETURN_BOOL(profiler_clock_failed()); }
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_guard_contract, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_uid_bool, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_fiber_callbacks, 0, 0, IS_ARRAY, 0)
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_uid_closure, 0, 1, _IS_BOOL, 0)
ZEND_ARG_OBJ_INFO(0, closure, Closure, 0)
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_closure_storage, 0, 1, IS_ARRAY, 0)
ZEND_ARG_OBJ_INFO(0, closure, Closure, 0)
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_observation_arm, 0, 0, _IS_BOOL, 0)
ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, cpu_placeholder, _IS_BOOL, 0, "false")
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_named_cache, 0, 1, _IS_BOOL, 0)
ZEND_ARG_TYPE_INFO(0, name, IS_STRING, 0)
ZEND_END_ARG_INFO()
#define AUTOMATIC_ASSERT(condition)                                                                \
    do {                                                                                           \
        if (!(condition))                                                                          \
            zend_error_noreturn(E_ERROR, "automatic transport contract failed: %s", #condition);   \
    } while (0)
static char *automatic_test_value;
static char *automatic_test_getenv(const char *name, size_t length) {
    (void)name;
    (void)length;
    return automatic_test_value;
}
/* A stand-in for FrankenPHP's frankenphp_request_headers(): an unrelated header, then
 * automatic_test_value under automatic_test_header_name, as a string or (kind 1) an
 * integer; kind 2 returns no array. */
static const char *automatic_test_header_name = "X-Php-Profiler-Trigger";
static int automatic_test_headers_kind;
/* A reference the test keeps to the last returned array: the bridge must release its own. */
static zval automatic_test_last_headers;
static bool automatic_test_headers_released(void) {
    return Z_TYPE(automatic_test_last_headers) == IS_ARRAY &&
           Z_REFCOUNT(automatic_test_last_headers) == 1;
}
static PHP_FUNCTION(automatic_test_request_headers) {
    (void)execute_data;
    if (automatic_test_headers_kind == 2)
        RETURN_FALSE;
    array_init(return_value);
    add_assoc_string(return_value, "Accept", "*/*");
    if (automatic_test_headers_kind == 1)
        add_assoc_long(return_value, automatic_test_header_name, 42);
    else if (automatic_test_value)
        add_assoc_string(return_value, automatic_test_header_name, automatic_test_value);
    /* A bailout after the matching header leaves an array the bridge must not read. */
    if (automatic_fault == 5)
        zend_bailout();
    zval_ptr_dtor(&automatic_test_last_headers);
    ZVAL_COPY(&automatic_test_last_headers, return_value);
}
ZEND_BEGIN_ARG_INFO_EX(arginfo_automatic_headers, 0, 0, 0)
ZEND_END_ARG_INFO()
static const zend_function_entry automatic_test_header_functions[] = {
    ZEND_NAMED_FE(frankenphp_request_headers, ZEND_FN(automatic_test_request_headers),
                  arginfo_automatic_headers) ZEND_FE_END};
/* A stand-in for FrankenPHP's frankenphp_handle_request(): calls its callback once with
 * the argument 7 and returns what the callback returned. Unlike FrankenPHP's, its signature
 * also takes an optional, unused second argument, as a later version could. */
static int automatic_worker_mode;
static PHP_FUNCTION(automatic_test_handle_request) {
    zend_fcall_info fci;
    zend_fcall_info_cache fcc;
    zval *unused = NULL;
    ZEND_PARSE_PARAMETERS_START(1, 2)
    Z_PARAM_FUNC(fci, fcc)
    Z_PARAM_OPTIONAL
    Z_PARAM_ZVAL(unused)
    ZEND_PARSE_PARAMETERS_END();
    (void)unused;
    /* Shutting down, FrankenPHP returns (5) or bails out (6) without calling the callback. */
    if (automatic_worker_mode == 5)
        RETURN_FALSE;
    if (automatic_worker_mode == 6)
        zend_bailout();
    zval argument, result;
    ZVAL_LONG(&argument, 7);
    ZVAL_UNDEF(&result);
    fci.retval = &result;
    fci.params = &argument;
    fci.param_count = 1;
    if (zend_call_function(&fci, &fcc) == SUCCESS && !Z_ISUNDEF(result))
        RETURN_COPY_VALUE(&result);
    RETURN_FALSE;
}
/* The application's callback: returns six times its argument (mode 0), throws (1), serves
 * a nested request (2), bails out (3) or calls the bridge's callback directly (4). */
static PHP_FUNCTION(automatic_test_worker_handler) {
    zval *arguments = NULL;
    uint32_t count = 0;
    ZEND_PARSE_PARAMETERS_START(0, -1)
    Z_PARAM_VARIADIC('*', arguments, count)
    ZEND_PARSE_PARAMETERS_END();
    automatic_worker_note('h');
    if (automatic_worker_mode == 1) {
        zend_throw_exception(NULL, "worker handler failure", 0);
        RETURN_THROWS();
    }
    if (automatic_worker_mode == 3)
        zend_bailout();
    if (automatic_worker_mode == 4) {
        zval name;
        ZVAL_STRING(&name, "PhpProfiler\\Internal\\frankenphp_request");
        call_user_function(NULL, NULL, &name, return_value, 0, NULL);
        zval_ptr_dtor(&name);
        return;
    }
    if (automatic_worker_mode == 2) {
        automatic_worker_mode = 0;
        zval name, callback;
        ZVAL_STRING(&name, "frankenphp_handle_request");
        ZVAL_STRING(&callback, "automatic_test_worker_handler");
        call_user_function(NULL, NULL, &name, return_value, 1, &callback);
        zval_ptr_dtor(&name);
        zval_ptr_dtor(&callback);
        return;
    }
    RETURN_LONG(count == 1 && Z_TYPE(arguments[0]) == IS_LONG ? Z_LVAL(arguments[0]) * 6 : -1);
}
ZEND_BEGIN_ARG_INFO_EX(arginfo_automatic_handle_request, 0, 0, 1)
ZEND_ARG_INFO(0, callback)
ZEND_ARG_INFO(0, unused)
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_INFO_EX(arginfo_automatic_worker_handler, 0, 0, 0)
ZEND_ARG_VARIADIC_INFO(0, arguments)
ZEND_END_ARG_INFO()
static const zend_function_entry automatic_test_worker_functions[] = {
    ZEND_NAMED_FE(frankenphp_handle_request, ZEND_FN(automatic_test_handle_request),
                  arginfo_automatic_handle_request)
        ZEND_FE(automatic_test_worker_handler, arginfo_automatic_worker_handler) ZEND_FE_END};
/* FrankenPHP's module, as far as the bridge reads it: its version. */
static zend_module_entry automatic_test_frankenphp = {
    STANDARD_MODULE_HEADER,    "frankenphp", NULL, NULL, NULL, NULL, NULL, NULL, "v1.13.4",
    STANDARD_MODULE_PROPERTIES};
/* FrankenPHP 1.13.4+'s getenv hook: answers FRANKENPHP_WORKER, by its exact name and length,
 * with automatic_worker_environment, and nothing else. */
static const char *automatic_worker_environment;
static char *automatic_worker_getenv(const char *name, size_t length) {
    return length == strlen("FRANKENPHP_WORKER") && !strcmp(name, "FRANKENPHP_WORKER")
               ? (char *)automatic_worker_environment
               : NULL;
}
/* Serves one worker request whose callback is `callback`; returns its result. */
/* Whether the last served callback was handed back for the frame to release. */
static bool automatic_worker_released;
/* Passes `count` arguments: the callback, then integers. */
static zval automatic_worker_serve_with(const char *callback, uint32_t count) {
    zval name, arguments[3], result;
    ZVAL_STRING(&name, "frankenphp_handle_request");
    ZVAL_STRING(&arguments[0], callback);
    ZVAL_LONG(&arguments[1], 1);
    ZVAL_LONG(&arguments[2], 2);
    ZVAL_UNDEF(&result);
    automatic_worker_log[0] = 0;
    call_user_function(NULL, NULL, &name, &result, count, arguments);
    automatic_worker_released = Z_REFCOUNT(arguments[0]) == 1;
    zval_ptr_dtor(&name);
    zval_ptr_dtor(&arguments[0]);
    return result;
}
static zval automatic_worker_serve(const char *callback) {
    return automatic_worker_serve_with(callback, 1);
}
static bool automatic_worker_function_exists(const char *name) {
    return zend_hash_str_find_ptr(CG(function_table), name, strlen(name)) != NULL;
}
static bool automatic_worker_contract(void) {
    bool correct = zend_register_functions(NULL, automatic_test_worker_functions, NULL,
                                           MODULE_PERSISTENT) == SUCCESS;
    zend_function *handle =
        zend_hash_str_find_ptr(CG(function_table), ZEND_STRL("frankenphp_handle_request"));
    correct &= handle != NULL;
    if (!correct)
        return false;
    zif_handler original = handle->internal_function.handler;
    /* Other SAPIs keep FrankenPHP's function as it is. */
    sapi_module.name = "cli";
    profiler_trigger_snapshot();
    profiler_worker_register();
    correct &= handle->internal_function.handler == original;
    correct &= !automatic_worker_function_exists("phpprofiler\\internal\\frankenphp_request");
    profiler_worker_unregister();
    /* Registration reads the startup snapshot's SAPI, not the name as it is now. */
    sapi_module.name = "frankenphp";
    profiler_worker_register();
    correct &= handle->internal_function.handler == original;
    profiler_worker_unregister();
    profiler_trigger_snapshot();
    profiler_worker_register();
    correct &= handle->internal_function.handler != original;
    correct &= automatic_worker_function_exists("phpprofiler\\internal\\frankenphp_request");
    /* The callback runs inside one profiler request and receives FrankenPHP's arguments. */
    automatic_worker_mode = 0;
    zval result = automatic_worker_serve("automatic_test_worker_handler");
    correct &= Z_TYPE(result) == IS_LONG && Z_LVAL(result) == 42;
    correct &= !strcmp(automatic_worker_log, "ebhe") && profiler_worker_handler == NULL;
    correct &= automatic_worker_released;
    /* Shutting down, FrankenPHP returns without calling the callback: the slot is emptied. */
    automatic_worker_mode = 5;
    result = automatic_worker_serve("automatic_test_worker_handler");
    correct &= Z_TYPE(result) == IS_FALSE && !strcmp(automatic_worker_log, "e");
    correct &= profiler_worker_handler == NULL && automatic_worker_released;
    /* An exception still ends the request and reaches FrankenPHP. */
    automatic_worker_mode = 1;
    result = automatic_worker_serve("automatic_test_worker_handler");
    correct &= EG(exception) != NULL && !strcmp(automatic_worker_log, "ebhe");
    zend_clear_exception();
    zval_ptr_dtor(&result);
    /* The callback runs once per frankenphp_handle_request() call: called directly from the
     * application's callback, it refuses instead of restarting the request. */
    automatic_worker_mode = 4;
    result = automatic_worker_serve("automatic_test_worker_handler");
    correct &= EG(exception) != NULL && !strcmp(automatic_worker_log, "ebhe");
    zend_clear_exception();
    zval_ptr_dtor(&result);
    /* A nested request runs inside the outer one's callback. */
    automatic_worker_mode = 2;
    result = automatic_worker_serve("automatic_test_worker_handler");
    correct &= Z_TYPE(result) == IS_LONG && Z_LVAL(result) == 42;
    correct &= !strcmp(automatic_worker_log, "ebhebhee") && profiler_worker_handler == NULL;
    /* Any argument count FrankenPHP's signature takes is wrapped; it refuses others itself,
     * before any request ends. */
    result = automatic_worker_serve_with("automatic_test_worker_handler", 2);
    correct &= Z_TYPE(result) == IS_LONG && Z_LVAL(result) == 42;
    correct &= !strcmp(automatic_worker_log, "ebhe") && profiler_worker_handler == NULL;
    correct &= automatic_worker_released;
    result = automatic_worker_serve_with("automatic_test_worker_handler", 3);
    correct &= EG(exception) != NULL && automatic_worker_log[0] == 0;
    zend_clear_exception();
    zval_ptr_dtor(&result);
    /* Without its callback argument, too. */
    result = automatic_worker_serve_with("automatic_test_worker_handler", 0);
    correct &= EG(exception) != NULL && automatic_worker_log[0] == 0;
    zend_clear_exception();
    zval_ptr_dtor(&result);
    /* A __callStatic callback resolves to a trampoline, which the handler never keeps:
     * PHP's single trampoline slot is free again even when FrankenPHP never calls back. */
    correct &= zend_eval_string("class AutomaticTestMagic { public static function __callStatic("
                                "$name, $arguments) { return $arguments[0] * 6; } }",
                                NULL, "worker trampoline") == SUCCESS;
    result = automatic_worker_serve("AutomaticTestMagic::run");
    correct &= Z_TYPE(result) == IS_LONG && Z_LVAL(result) == 42;
    correct &= !strcmp(automatic_worker_log, "ebe") && EG(trampoline).common.function_name == NULL;
    automatic_worker_mode = 5;
    result = automatic_worker_serve("AutomaticTestMagic::run");
    correct &= Z_TYPE(result) == IS_FALSE && !strcmp(automatic_worker_log, "e");
    correct &= EG(trampoline).common.function_name == NULL && automatic_worker_released;
    automatic_worker_mode = 0;
    /* FrankenPHP rejects a missing or non-callable argument itself, before any request. */
    zval handle_name;
    ZVAL_STRING(&handle_name, "frankenphp_handle_request");
    automatic_worker_log[0] = 0;
    ZVAL_UNDEF(&result);
    call_user_function(NULL, NULL, &handle_name, &result, 0, NULL);
    correct &= EG(exception) != NULL && automatic_worker_log[0] == 0;
    zend_clear_exception();
    zval_ptr_dtor(&handle_name);
    zval_ptr_dtor(&result);
    result = automatic_worker_serve("automatic_test_missing_handler");
    correct &= EG(exception) != NULL && automatic_worker_log[0] == 0;
    zend_clear_exception();
    zval_ptr_dtor(&result);
    /* Called directly, the bridge's callback refuses to run. */
    zval name, direct;
    ZVAL_STRING(&name, "PhpProfiler\\Internal\\frankenphp_request");
    ZVAL_UNDEF(&direct);
    call_user_function(NULL, NULL, &name, &direct, 0, NULL);
    correct &= EG(exception) != NULL && automatic_worker_log[0] == 0;
    zend_clear_exception();
    zval_ptr_dtor(&name);
    zval_ptr_dtor(&direct);
    /* FrankenPHP's getenv hook answers from the worker's environment from 1.13.4 only, as
     * its module reports when the handler is installed. */
    correct &= !profiler_worker_environment;
    correct &= zend_hash_str_add_ptr(&module_registry, ZEND_STRL("frankenphp"),
                                     &automatic_test_frankenphp) != NULL;
    profiler_worker_unregister();
    profiler_worker_register();
    correct &= profiler_worker_environment;
    profiler_worker_unregister();
    correct &= zend_hash_str_del(&module_registry, ZEND_STRL("frankenphp")) == SUCCESS;
    profiler_worker_register();
    correct &= !profiler_worker_environment;
    correct &= !profiler_worker_environment_version(NULL);
    correct &= !profiler_worker_environment_version("dev");
    correct &= !profiler_worker_environment_version("1.13");
    correct &= !profiler_worker_environment_version("1.13.3");
    correct &= !profiler_worker_environment_version("1.12.9");
    correct &= !profiler_worker_environment_version("0.99.99");
    correct &= profiler_worker_environment_version("1.13.4");
    /* FrankenPHP reports its release tag, such as "v1.13.6". */
    correct &= profiler_worker_environment_version("v1.13.4");
    correct &= !profiler_worker_environment_version("v1.13.3");
    correct &= !profiler_worker_environment_version("vv1.13.4");
    correct &= !profiler_worker_environment_version("-1.0.0");
    correct &= !profiler_worker_environment_version("v-1.0.0");
    correct &= !profiler_worker_environment_version(" 1.13.4");
    correct &= !profiler_worker_environment_version("+1.13.4");
    correct &= !profiler_worker_environment_version("1.-13.4");
    correct &= !profiler_worker_environment_version("1.13. 4");
    correct &= profiler_worker_environment_version("1.13.4-dev");
    correct &= profiler_worker_environment_version("1.13.6-dev");
    correct &= profiler_worker_environment_version("1.14.0");
    correct &= profiler_worker_environment_version("2.0.0");
    /* Where it does, a request whose environment and _SERVER both lack FRANKENPHP_WORKER is
     * not a worker's: FrankenPHP refuses the call itself (here, the stand-in calls the
     * application's callback directly) and the request is not ended. */
    zend_is_auto_global_str(ZEND_STRL("_SERVER"));
    zval *server = &PG(http_globals)[TRACK_VARS_SERVER];
    zval saved_server;
    ZVAL_COPY_VALUE(&saved_server, server);
    array_init(server);
    add_assoc_string(server, "FRANKENPHP_WORKER_ID", "1");
    sapi_module.getenv = automatic_worker_getenv;
    automatic_worker_environment = NULL;
    automatic_worker_mode = 0;
    /* An older FrankenPHP cannot tell: the call counts as a worker's. */
    result = automatic_worker_serve("automatic_test_worker_handler");
    correct &= !strcmp(automatic_worker_log, "ebhe");
    profiler_worker_environment = true;
    result = automatic_worker_serve("automatic_test_worker_handler");
    correct &= Z_TYPE(result) == IS_LONG && Z_LVAL(result) == 42;
    correct &= !strcmp(automatic_worker_log, "h") && profiler_worker_handler == NULL;
    correct &= automatic_worker_released;
    /* FrankenPHP's environment marks a worker. */
    automatic_worker_environment = "1";
    result = automatic_worker_serve("automatic_test_worker_handler");
    correct &= Z_TYPE(result) == IS_LONG && Z_LVAL(result) == 42;
    correct &= !strcmp(automatic_worker_log, "ebhe");
    /* So does _SERVER alone. */
    automatic_worker_environment = NULL;
    add_assoc_string(server, "FRANKENPHP_WORKER", "1");
    result = automatic_worker_serve("automatic_test_worker_handler");
    correct &= !strcmp(automatic_worker_log, "ebhe");
    /* A _SERVER that is not an array cannot tell, so the call counts as a worker's. */
    zval_ptr_dtor(server);
    ZVAL_NULL(server);
    result = automatic_worker_serve("automatic_test_worker_handler");
    correct &= !strcmp(automatic_worker_log, "ebhe");
    ZVAL_COPY_VALUE(server, &saved_server);
    sapi_module.getenv = NULL;
    /* Without a getenv hook, the call counts as a worker's too. */
    result = automatic_worker_serve("automatic_test_worker_handler");
    correct &= !strcmp(automatic_worker_log, "ebhe");
    profiler_worker_environment = false;
    profiler_worker_unregister();
    correct &= handle->internal_function.handler == original;
    correct &= !automatic_worker_function_exists("phpprofiler\\internal\\frankenphp_request");
    /* A taken callback name fails module startup and leaves FrankenPHP's function alone. */
    correct &= zend_register_functions(NULL, profiler_worker_functions, NULL, MODULE_PERSISTENT) ==
               SUCCESS;
    profiler_worker_register();
    correct &= handle->internal_function.handler == original;
    profiler_worker_unregister();
    zend_unregister_functions(profiler_worker_functions, -1, NULL);
    /* Without frankenphp_handle_request(), as under its CLI, nothing is installed. */
    zend_unregister_functions(automatic_test_worker_functions, -1, NULL);
    profiler_worker_register();
    correct &= !automatic_worker_function_exists("phpprofiler\\internal\\frankenphp_request");
    profiler_worker_unregister();
    /* Reopen the CLI's own profiler request, which the worker requests above ended. */
    profiler_worker_request_begin();
    return correct;
}
static PHP_FUNCTION(profiler_test_automatic_transports) {
    (void)execute_data;
    /* php_profiler.observer keeps startup's value: a later system-level change updates
     * the INI entry, not the value module startup read. */
    zend_string *observer_name = zend_string_init(ZEND_STRL("php_profiler.observer"), 0);
    AUTOMATIC_ASSERT(profiler_observer_setting() == 1);
    AUTOMATIC_ASSERT(zend_alter_ini_entry_chars(observer_name, "0", 1, PHP_INI_SYSTEM,
                                                PHP_INI_STAGE_ACTIVATE) == SUCCESS);
    AUTOMATIC_ASSERT(profiler_observer_setting() == 1);
    AUTOMATIC_ASSERT(zend_restore_ini_entry(observer_name, PHP_INI_STAGE_DEACTIVATE) == SUCCESS);
    zend_string_release(observer_name);
    zend_module_entry *module = zend_hash_str_find_ptr(&module_registry, ZEND_STRL("php_profiler"));
    AUTOMATIC_ASSERT(module != NULL);
    int previous_type = module->type;
    module->type = MODULE_TEMPORARY;
    profiler_config_unregister(module->module_number);
    size_t ini_length = 0;
    AUTOMATIC_ASSERT(profiler_ini((const unsigned char *)"php_profiler.mode",
                                  sizeof("php_profiler.mode") - 1, &ini_length) == NULL);
    AUTOMATIC_ASSERT(profiler_config_register(module->module_number) == SUCCESS);
    module->type = previous_type;
    char *original_name = sapi_module.name;
    char *(*original_getenv)(const char *, size_t) = sapi_module.getenv;
    sapi_module.name = "cli";
    sapi_module.getenv = automatic_test_getenv;
    automatic_test_value = "secret";
    automatic_fault = 3;
    AUTOMATIC_ASSERT(profiler_auto_header("X-Php-Profiler-Id: contract") == 1);
    AUTOMATIC_ASSERT(profiler_trigger_matches((const unsigned char *)"secret", 6) == -1);
    automatic_fault = 0;
    /* php-cgi's getenv answers HTTP_* from FastCGI parameters. */
    sapi_module.name = "cgi-fcgi";
    sapi_module.getenv = automatic_test_getenv;
    profiler_trigger_snapshot();
    automatic_test_value = "";
    AUTOMATIC_ASSERT(profiler_trigger_matches((const unsigned char *)"", 0) == 0);
    automatic_test_value = "secret";
    AUTOMATIC_ASSERT(profiler_trigger_matches((const unsigned char *)"secret", 0) == 0);
    AUTOMATIC_ASSERT(profiler_trigger_matches((const unsigned char *)"secret", 4097) == 0);
    AUTOMATIC_ASSERT(profiler_trigger_matches((const unsigned char *)"secret", 6) == 1);
    /* SAPIs that read request headers only ignore the process environment. Its value
     * differs from the header, so reading the environment instead cannot match. */
    AUTOMATIC_ASSERT(setenv("HTTP_X_PHP_PROFILER_TRIGGER", "inherited", 1) == 0);
    static const char *const header_sapis[] = {"fpm-fcgi", "apache2handler", "litespeed"};
    static char request_method[] = "GET";
    char *original_method = SG(request_info).request_method;
    SG(request_info).request_method = request_method;
    for (size_t i = 0; i < sizeof(header_sapis) / sizeof(*header_sapis); i++) {
        sapi_module.name = (char *)header_sapis[i];
        profiler_trigger_snapshot();
        AUTOMATIC_ASSERT(profiler_trigger_matches((const unsigned char *)"secret", 6) == 1);
        /* Without a request method (LiteSpeed's command-line mode, `php-fpm -i`) they read
         * the process environment, so like the CLI they serve no request to trigger. */
        SG(request_info).request_method = NULL;
        int logs = automatic_test_logs;
        AUTOMATIC_ASSERT(profiler_trigger_matches((const unsigned char *)"secret", 6) == -1);
        AUTOMATIC_ASSERT(automatic_test_logs == logs);
        SG(request_info).request_method = request_method;
    }
    /* Every other SAPI, known or not, may read the environment: it refuses every request,
     * reporting that once. Names are matched whole, so a prefix, a suffix or an overlong
     * name of a header-only SAPI is distrusted. */
    static const char *const environment_sapis[] = {
        "cgi-fcgi", "phpdbg",
        "embed",    "frankenphp",
        "micro",    "contract-http",
        "fpm",      "fpm-fcgi-x",
        "apache2",  "cli-server-with-a-name-far-longer-than-any-header-sapi-name"};
    for (size_t i = 0; i < sizeof(environment_sapis) / sizeof(*environment_sapis); i++) {
        sapi_module.name = (char *)environment_sapis[i];
        profiler_trigger_snapshot();
        int logs = automatic_test_logs;
        AUTOMATIC_ASSERT(profiler_trigger_matches((const unsigned char *)"secret", 6) == -1);
        AUTOMATIC_ASSERT(automatic_test_logs == logs + 1);
        AUTOMATIC_ASSERT(profiler_trigger_matches((const unsigned char *)"secret", 6) == -1);
        AUTOMATIC_ASSERT(automatic_test_logs == logs + 1);
    }
    /* Only the startup environment counts: later changes are never read. */
    AUTOMATIC_ASSERT(unsetenv("HTTP_X_PHP_PROFILER_TRIGGER") == 0);
    AUTOMATIC_ASSERT(profiler_trigger_matches((const unsigned char *)"secret", 6) == -1);
    sapi_module.name = "cgi-fcgi";
    profiler_trigger_snapshot();
    /* A differing value: reading the environment instead of the request cannot match. */
    AUTOMATIC_ASSERT(setenv("HTTP_X_PHP_PROFILER_TRIGGER", "inherited", 1) == 0);
    AUTOMATIC_ASSERT(profiler_trigger_matches((const unsigned char *)"secret", 6) == 1);
    SG(request_info).request_method = original_method;
    AUTOMATIC_ASSERT(unsetenv("HTTP_X_PHP_PROFILER_TRIGGER") == 0);
    profiler_trigger_snapshot();
    AUTOMATIC_ASSERT(profiler_trigger_matches((const unsigned char *)"secrex", 6) == 0);
    char maximum[4097];
    memset(maximum, 'x', 4096);
    maximum[4096] = 0;
    automatic_test_value = maximum;
    AUTOMATIC_ASSERT(profiler_trigger_matches((const unsigned char *)maximum, 4096) == 1);
    char oversized[4098];
    memset(oversized, 'x', 4097);
    oversized[4097] = 0;
    automatic_test_value = oversized;
    AUTOMATIC_ASSERT(profiler_trigger_matches((const unsigned char *)oversized, 4097) == 0);
    size_t page = (size_t)sysconf(_SC_PAGESIZE);
    unsigned char *guarded =
        mmap(NULL, page * 2, PROT_READ | PROT_WRITE, MAP_PRIVATE | MAP_ANONYMOUS, -1, 0);
    AUTOMATIC_ASSERT(guarded != MAP_FAILED);
    AUTOMATIC_ASSERT(mprotect(guarded + page, page, PROT_NONE) == 0);
    memcpy(guarded + page - 6, "secret", 6);
    automatic_test_value = "secret";
    AUTOMATIC_ASSERT(profiler_trigger_matches(guarded + page - 6, 6) == 1);
    AUTOMATIC_ASSERT(munmap(guarded, page * 2) == 0);
    automatic_test_value = NULL;
    AUTOMATIC_ASSERT(profiler_trigger_matches((const unsigned char *)"secret", 6) == -1);
    automatic_test_value = "short";
    AUTOMATIC_ASSERT(profiler_trigger_matches((const unsigned char *)"secret", 6) == 0);
    automatic_test_value = "a,b";
    AUTOMATIC_ASSERT(profiler_trigger_matches((const unsigned char *)"a,b", 3) == 0);
    sapi_module.getenv = NULL;
    zend_is_auto_global_str(ZEND_STRL("_SERVER"));
    zval saved = PG(http_globals)[TRACK_VARS_SERVER];
    ZVAL_NULL(&PG(http_globals)[TRACK_VARS_SERVER]);
    AUTOMATIC_ASSERT(profiler_trigger_matches((const unsigned char *)"secret", 6) == -1);
    array_init(&PG(http_globals)[TRACK_VARS_SERVER]);
    AUTOMATIC_ASSERT(profiler_trigger_matches((const unsigned char *)"secret", 6) == -1);
    add_assoc_long(&PG(http_globals)[TRACK_VARS_SERVER], "HTTP_X_PHP_PROFILER_TRIGGER", 42);
    AUTOMATIC_ASSERT(profiler_trigger_matches((const unsigned char *)"secret", 6) == -1);
    add_assoc_string(&PG(http_globals)[TRACK_VARS_SERVER], "HTTP_X_PHP_PROFILER_TRIGGER",
                     oversized);
    AUTOMATIC_ASSERT(profiler_trigger_matches((const unsigned char *)"secret", 6) == 0);
    add_assoc_string(&PG(http_globals)[TRACK_VARS_SERVER], "HTTP_X_PHP_PROFILER_TRIGGER", maximum);
    AUTOMATIC_ASSERT(profiler_trigger_matches((const unsigned char *)maximum, 4096) == 1);
    add_assoc_string(&PG(http_globals)[TRACK_VARS_SERVER], "HTTP_X_PHP_PROFILER_TRIGGER", "secret");
    AUTOMATIC_ASSERT(profiler_trigger_matches((const unsigned char *)"secret", 6) == 1);
    /* The built-in server reads _SERVER, which holds request headers only. */
    AUTOMATIC_ASSERT(setenv("HTTP_X_PHP_PROFILER_TRIGGER", "secret", 1) == 0);
    sapi_module.name = "cli-server";
    profiler_trigger_snapshot();
    SG(request_info).request_method = request_method;
    AUTOMATIC_ASSERT(profiler_trigger_matches((const unsigned char *)"secret", 6) == 1);
    SG(request_info).request_method = NULL;
    AUTOMATIC_ASSERT(profiler_trigger_matches((const unsigned char *)"secret", 6) == -1);
    SG(request_info).request_method = original_method;
    /* phpdbg imports the environment into _SERVER. */
    sapi_module.name = "phpdbg";
    profiler_trigger_snapshot();
    /* Without a request method, a distrusted SAPI still refuses and reports. */
    AUTOMATIC_ASSERT(SG(request_info).request_method == NULL);
    int logs = automatic_test_logs;
    AUTOMATIC_ASSERT(profiler_trigger_matches((const unsigned char *)"secret", 6) == -1);
    AUTOMATIC_ASSERT(automatic_test_logs == logs + 1);
    AUTOMATIC_ASSERT(unsetenv("HTTP_X_PHP_PROFILER_TRIGGER") == 0);
    /* FrankenPHP's getenv serves only the process environment: headers come from
     * _SERVER, while php-cgi's getenv still answers them. */
    sapi_module.name = "frankenphp";
    sapi_module.getenv = automatic_test_getenv;
    automatic_test_value = "environment";
    profiler_trigger_snapshot();
    AUTOMATIC_ASSERT(profiler_trigger_matches((const unsigned char *)"secret", 6) == 1);
    sapi_module.name = "cgi-fcgi";
    profiler_trigger_snapshot();
    AUTOMATIC_ASSERT(profiler_trigger_matches((const unsigned char *)"secret", 6) == 0);
    /* FrankenPHP 1.1+ answers from frankenphp_request_headers(), the request's headers
     * alone, so it is trusted: neither its getenv nor _SERVER, which both start from the
     * process environment, is read, even when they hold the secret. */
    AUTOMATIC_ASSERT(zend_register_functions(NULL, automatic_test_header_functions, NULL,
                                             MODULE_PERSISTENT) == SUCCESS);
    AUTOMATIC_ASSERT(setenv("HTTP_X_PHP_PROFILER_TRIGGER", "secret", 1) == 0);
    sapi_module.name = "frankenphp";
    profiler_trigger_snapshot();
    SG(request_info).request_method = request_method;
    logs = automatic_test_logs;
    automatic_test_value = "secret";
    AUTOMATIC_ASSERT(profiler_trigger_matches((const unsigned char *)"secret", 6) == 1);
    AUTOMATIC_ASSERT(automatic_test_headers_released());
    /* Go stores headers under their canonical names only: no other spelling is read. */
    automatic_test_header_name = "x-php-profiler-trigger";
    AUTOMATIC_ASSERT(profiler_trigger_matches((const unsigned char *)"secret", 6) == -1);
    automatic_test_header_name = "X-Php-Profiler-Trigger";
    automatic_test_value = "secrex";
    AUTOMATIC_ASSERT(profiler_trigger_matches((const unsigned char *)"secret", 6) == 0);
    /* Repeated headers arrive joined with ", ". */
    automatic_test_value = "secret, secret";
    AUTOMATIC_ASSERT(profiler_trigger_matches((const unsigned char *)"secret", 6) == 0);
    automatic_test_value = NULL;
    AUTOMATIC_ASSERT(profiler_trigger_matches((const unsigned char *)"secret", 6) == -1);
    automatic_test_value = "secret";
    automatic_test_header_name = "X-Php-Profiler-Triggers";
    AUTOMATIC_ASSERT(profiler_trigger_matches((const unsigned char *)"secret", 6) == -1);
    automatic_test_header_name = "X-Php-Profiler-Trigger";
    automatic_test_headers_kind = 1;
    AUTOMATIC_ASSERT(profiler_trigger_matches((const unsigned char *)"secret", 6) == -1);
    AUTOMATIC_ASSERT(automatic_test_headers_released());
    automatic_test_headers_kind = 2;
    AUTOMATIC_ASSERT(profiler_trigger_matches((const unsigned char *)"secret", 6) == -1);
    automatic_test_headers_kind = 0;
    /* Outside a request, such as a worker script's start, no header is read. */
    SG(request_info).request_method = NULL;
    AUTOMATIC_ASSERT(profiler_trigger_matches((const unsigned char *)"secret", 6) == -1);
    SG(request_info).request_method = request_method;
    AUTOMATIC_ASSERT(automatic_test_logs == logs);
    /* The function trusts FrankenPHP alone: phpdbg still refuses an inherited value. */
    sapi_module.name = "phpdbg";
    profiler_trigger_snapshot();
    AUTOMATIC_ASSERT(profiler_trigger_matches((const unsigned char *)"secret", 6) == -1);
    AUTOMATIC_ASSERT(automatic_test_logs == logs + 1);
    SG(request_info).request_method = original_method;
    AUTOMATIC_ASSERT(unsetenv("HTTP_X_PHP_PROFILER_TRIGGER") == 0);
    zend_unregister_functions(automatic_test_header_functions, -1, NULL);
    zval_ptr_dtor(&automatic_test_last_headers);
    ZVAL_UNDEF(&automatic_test_last_headers);
    sapi_module.getenv = NULL;
    sapi_module.name = "contract-http";
    profiler_trigger_snapshot();
    automatic_fault = 3;
    AUTOMATIC_ASSERT(profiler_auto_header("X-Php-Profiler-Id: contract") == 0);
    automatic_fault = 0;
    zval_ptr_dtor(&PG(http_globals)[TRACK_VARS_SERVER]);
    PG(http_globals)[TRACK_VARS_SERVER] = saved;
    AUTOMATIC_ASSERT(automatic_worker_contract());
    sapi_module.name = original_name;
    sapi_module.getenv = original_getenv;
    RETURN_TRUE;
}
/* Fault 6 of the worker request end transport: a deferral as a failed report write makes. */
void automatic_test_defer_bailout(void) { profiler_defer_bailout(); }
/* A dependency bailout abandons EG(current_execute_data). Check only native
 * status/flags afterwards, then exit this isolated process without resuming
 * Zend. C exit handlers flush both LLVM and gcov counters. */
static PHP_FUNCTION(profiler_test_automatic_bailout) {
    (void)execute_data;
    const char *mode = getenv("PROFILER_TEST_AUTOMATIC_BAILOUT");
    bool correct = false;
    sapi_module.name = "contract-http";
    sapi_module.getenv = NULL;
    profiler_flags.pending_bailout = false;
    if (mode && strcmp(mode, "materialize") == 0) {
        automatic_fault = 4;
        int status = profiler_materialize_server();
        correct = status == 0 && profiler_flags.pending_bailout;
    } else if (mode && strcmp(mode, "trigger") == 0) {
        zend_is_auto_global_str(ZEND_STRL("_SERVER"));
        zval *server = &PG(http_globals)[TRACK_VARS_SERVER];
        if (Z_TYPE_P(server) != IS_ARRAY)
            array_init(server);
        add_assoc_string(server, "HTTP_X_PHP_PROFILER_TRIGGER", "secret");
        automatic_fault = 4;
        int status = profiler_trigger_matches((const unsigned char *)"secret", 6);
        correct = status == -1 && profiler_flags.pending_bailout;
    } else if (mode && strcmp(mode, "log") == 0) {
        automatic_fault = 1;
        profiler_auto_log();
        correct = profiler_flags.pending_bailout;
    } else if (mode && strcmp(mode, "inherited-log") == 0) {
        /* A report whose logging bailed out defers that bailout and is not retried:
         * retrying would abort every request while logging keeps failing. */
        sapi_module.name = "phpdbg";
        correct = setenv("HTTP_X_PHP_PROFILER_TRIGGER", "secret", 1) == 0;
        profiler_trigger_snapshot();
        automatic_fault = 1;
        correct &= profiler_trigger_matches((const unsigned char *)"secret", 6) == -1;
        correct &= profiler_flags.pending_bailout;
        profiler_flags.pending_bailout = false;
        automatic_fault = 0;
        /* The process stays refused: a matching _SERVER value must still not capture. */
        zend_is_auto_global_str(ZEND_STRL("_SERVER"));
        zval *server = &PG(http_globals)[TRACK_VARS_SERVER];
        if (Z_TYPE_P(server) != IS_ARRAY)
            array_init(server);
        add_assoc_string(server, "HTTP_X_PHP_PROFILER_TRIGGER", "secret");
        int logs = automatic_test_logs;
        correct &= profiler_trigger_matches((const unsigned char *)"secret", 6) == -1;
        correct &= automatic_test_logs == logs && !profiler_flags.pending_bailout;
    } else if (mode && strcmp(mode, "headers") == 0) {
        /* FrankenPHP's header function may allocate: its bailout is deferred. */
        static char request_method[] = "GET";
        correct = zend_register_functions(NULL, automatic_test_header_functions, NULL,
                                          MODULE_PERSISTENT) == SUCCESS;
        sapi_module.name = "frankenphp";
        profiler_trigger_snapshot();
        SG(request_info).request_method = request_method;
        automatic_test_value = "secret";
        automatic_fault = 5;
        int status = profiler_trigger_matches((const unsigned char *)"secret", 6);
        correct &= status == -1 && profiler_flags.pending_bailout;
    } else if (mode && strcmp(mode, "worker") == 0) {
        /* A fatal error in the callback ends the worker request, then reaches FrankenPHP. */
        correct = zend_register_functions(NULL, automatic_test_worker_functions, NULL,
                                          MODULE_PERSISTENT) == SUCCESS;
        sapi_module.name = "frankenphp";
        profiler_trigger_snapshot();
        profiler_worker_register();
        automatic_worker_mode = 3;
        volatile bool bailed = false;
        zend_try { automatic_worker_serve("automatic_test_worker_handler"); }
        zend_catch { bailed = true; }
        zend_end_try();
        correct &=
            bailed && !strcmp(automatic_worker_log, "ebhe") && profiler_worker_handler == NULL;
    } else if (mode && strcmp(mode, "worker-start") == 0) {
        /* A bailout before the callback runs still empties the slot. */
        correct = zend_register_functions(NULL, automatic_test_worker_functions, NULL,
                                          MODULE_PERSISTENT) == SUCCESS;
        sapi_module.name = "frankenphp";
        profiler_trigger_snapshot();
        profiler_worker_register();
        automatic_worker_mode = 6;
        volatile bool bailed = false;
        zend_try { automatic_worker_serve("automatic_test_worker_handler"); }
        zend_catch { bailed = true; }
        zend_end_try();
        correct &= bailed && !strcmp(automatic_worker_log, "e") && profiler_worker_handler == NULL;
    } else if (mode && strcmp(mode, "worker-materialize") == 0) {
        /* With FrankenPHP's getenv hook silent, an unreadable _SERVER still counts as a
         * worker's. Reading it deferred a fatal error, which ends the worker script there:
         * no request boundary runs and the callback is not called. */
        correct = zend_register_functions(NULL, automatic_test_worker_functions, NULL,
                                          MODULE_PERSISTENT) == SUCCESS;
        sapi_module.name = "frankenphp";
        profiler_trigger_snapshot();
        profiler_worker_register();
        profiler_worker_environment = true;
        sapi_module.getenv = automatic_worker_getenv;
        automatic_worker_environment = NULL;
        automatic_worker_mode = 0;
        automatic_fault = 4;
        volatile bool bailed = false;
        zend_try { automatic_worker_serve("automatic_test_worker_handler"); }
        zend_catch { bailed = true; }
        zend_end_try();
        automatic_fault = 0;
        correct &= bailed && !strcmp(automatic_worker_log, "") && profiler_worker_handler == NULL;
    } else if (mode && strcmp(mode, "worker-end") == 0) {
        /* A fatal error deferred while the worker script's start ends ends the script there,
         * before the next request's begin could clear it: the callback never runs. */
        correct = zend_register_functions(NULL, automatic_test_worker_functions, NULL,
                                          MODULE_PERSISTENT) == SUCCESS;
        sapi_module.name = "frankenphp";
        profiler_trigger_snapshot();
        profiler_worker_register();
        automatic_worker_mode = 0;
        automatic_fault = 6;
        volatile bool bailed = false;
        zend_try { automatic_worker_serve("automatic_test_worker_handler"); }
        zend_catch { bailed = true; }
        zend_end_try();
        automatic_fault = 0;
        correct &= bailed && !strcmp(automatic_worker_log, "e") && profiler_worker_handler == NULL;
    } else if (mode && strcmp(mode, "header") == 0) {
        automatic_fault = 2;
        int status = profiler_auto_header("X-Php-Profiler-Id: contract");
        correct = status == 0 && profiler_flags.pending_bailout;
    }
    if (correct)
        (void)write(1, "AUTOMATIC_C_GUARD_OK\n", sizeof("AUTOMATIC_C_GUARD_OK\n") - 1);
    else
        (void)write(2, "AUTOMATIC_C_GUARD_FAILED\n", sizeof("AUTOMATIC_C_GUARD_FAILED\n") - 1);
    exit(correct ? 0 : 42);
}
static const zend_function_entry guard_functions[] = {
    ZEND_FE(profiler_test_automatic_bailout,
            arginfo_guard_contract) ZEND_FE(profiler_test_automatic_transports, arginfo_uid_bool)
        ZEND_FE(profiler_test_guard_contract,
                arginfo_guard_contract) ZEND_FE(profiler_test_uid_allocator, arginfo_uid_bool)
            ZEND_FE(profiler_test_uid_threads, arginfo_uid_bool)
                ZEND_FE(profiler_test_uid_cache, arginfo_uid_closure)
                    ZEND_FE(profiler_test_named_cache, arginfo_named_cache)
                        ZEND_FE(profiler_test_closure_storage, arginfo_closure_storage)
                            ZEND_FE(profiler_test_exhaust_closure_ids, arginfo_guard_contract)
                                ZEND_FE(profiler_test_uid_clock_failed, arginfo_uid_bool)
                                    ZEND_FE(profiler_test_fiber_callbacks, arginfo_fiber_callbacks)
                                        ZEND_FE(profiler_test_fiber_observation_arm,
                                                arginfo_observation_arm)
                                            ZEND_FE(profiler_test_fiber_observation_valid,
                                                    arginfo_uid_bool) ZEND_FE_END};
/* Only the current MINIT's own entries may be removed. No retained static
 * flag can accidentally identify registrations from a later engine cycle. */
#ifdef __linux__
extern void profiler_test_boundary_unregister(void) __attribute__((weak));
extern int profiler_test_boundary_registration_status(void) __attribute__((weak));
#endif
void profiler_test_guard_unregister(void) {
#ifdef __linux__
    if (profiler_test_boundary_unregister)
        profiler_test_boundary_unregister();
#endif
    zend_module_entry *owner = EG(current_module);
    if (!owner)
        return;
    for (const zend_function_entry *entry = guard_functions; entry->fname; entry++) {
        zend_function *function =
            zend_hash_str_find_ptr(CG(function_table), entry->fname, strlen(entry->fname));
        if (function && function->type == ZEND_INTERNAL_FUNCTION &&
            function->internal_function.module == owner &&
            function->internal_function.handler == entry->handler) {
            zend_unregister_functions(entry, 1, NULL);
        }
    }
}
int profiler_register(void) {
    int result = profiler_register_observer();
    if (result != SUCCESS)
        return result;
#ifdef __linux__
    if (profiler_test_boundary_registration_status &&
        profiler_test_boundary_registration_status() != SUCCESS) {
        profiler_test_guard_unregister();
        return FAILURE;
    }
#endif
    result = zend_register_functions(NULL, guard_functions, NULL, MODULE_PERSISTENT);
    if (result != SUCCESS)
        profiler_test_guard_unregister();
    return result;
}
