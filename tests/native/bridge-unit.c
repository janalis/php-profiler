/* Test-only translation unit. Including the real source gives unit assertions
 * access to private C state without exporting it or shipping test switches. */
#include <php.h>
#include <Zend/zend_extensions.h>
#include <Zend/zend_closures.h>
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
int automatic_test_header(sapi_header_op_enum op, void *argument);
bool automatic_test_global(const char *name, size_t length);
int automatic_test_register(const zend_ini_entry_def *entries, int number);
#undef php_log_err
#define php_log_err automatic_test_log
#ifndef __linux__
#define sapi_header_op automatic_test_header
#define zend_is_auto_global_str automatic_test_global
#define zend_register_ini_entries automatic_test_register
#endif
#include "../../crates/php-profiler/src/bridge.c"
#undef php_log_err
#undef sapi_header_op
#undef zend_is_auto_global_str
#undef zend_register_ini_entries
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
            cpu >= test_fiber_observation.cpu_minimum && cpu <= maximum_cpu &&
            memory == actual_memory && memory >= 1024 * 1024;
        test_fiber_observation.checked = true;
        test_fiber_observation.armed = false;
    }
    PROFILER_TEST_REAL_DESTROY(pointer, wall, cpu, memory);
}
static ZEND_FUNCTION(profiler_test_fiber_observation_arm) {
    ZEND_PARSE_PARAMETERS_NONE();
    test_fiber_observation = (profiler_test_fiber_observation){0};
    bool valid =
        test_clock_observation(CLOCK_MONOTONIC, &test_fiber_observation.wall_minimum) &&
        test_clock_observation(CLOCK_THREAD_CPUTIME_ID, &test_fiber_observation.cpu_minimum) &&
        test_fiber_observation.cpu_minimum > 42;
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
static PHP_FUNCTION(profiler_test_automatic_transports) {
    (void)execute_data;
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
    AUTOMATIC_ASSERT(profiler_trigger_matches((const unsigned char *)"secret", 6) == 0);
    automatic_fault = 0;
    sapi_module.name = "contract-http";
    sapi_module.getenv = automatic_test_getenv;
    automatic_test_value = "secret";
    automatic_test_value = "";
    AUTOMATIC_ASSERT(profiler_trigger_matches((const unsigned char *)"", 0) == 0);
    automatic_test_value = "secret";
    AUTOMATIC_ASSERT(profiler_trigger_matches((const unsigned char *)"secret", 0) == 0);
    AUTOMATIC_ASSERT(profiler_trigger_matches((const unsigned char *)"secret", 4097) == 0);
    AUTOMATIC_ASSERT(profiler_trigger_matches((const unsigned char *)"secret", 6) == 1);
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
    AUTOMATIC_ASSERT(profiler_trigger_matches((const unsigned char *)"secret", 6) == 0);
    automatic_test_value = "short";
    AUTOMATIC_ASSERT(profiler_trigger_matches((const unsigned char *)"secret", 6) == 0);
    automatic_test_value = "a,b";
    AUTOMATIC_ASSERT(profiler_trigger_matches((const unsigned char *)"a,b", 3) == 0);
    sapi_module.getenv = NULL;
    zend_is_auto_global_str(ZEND_STRL("_SERVER"));
    zval saved = PG(http_globals)[TRACK_VARS_SERVER];
    ZVAL_NULL(&PG(http_globals)[TRACK_VARS_SERVER]);
    AUTOMATIC_ASSERT(profiler_trigger_matches((const unsigned char *)"secret", 6) == 0);
    array_init(&PG(http_globals)[TRACK_VARS_SERVER]);
    AUTOMATIC_ASSERT(profiler_trigger_matches((const unsigned char *)"secret", 6) == 0);
    add_assoc_long(&PG(http_globals)[TRACK_VARS_SERVER], "HTTP_X_PHP_PROFILER_TRIGGER", 42);
    AUTOMATIC_ASSERT(profiler_trigger_matches((const unsigned char *)"secret", 6) == 0);
    add_assoc_string(&PG(http_globals)[TRACK_VARS_SERVER], "HTTP_X_PHP_PROFILER_TRIGGER",
                     oversized);
    AUTOMATIC_ASSERT(profiler_trigger_matches((const unsigned char *)"secret", 6) == 0);
    add_assoc_string(&PG(http_globals)[TRACK_VARS_SERVER], "HTTP_X_PHP_PROFILER_TRIGGER", maximum);
    AUTOMATIC_ASSERT(profiler_trigger_matches((const unsigned char *)maximum, 4096) == 1);
    add_assoc_string(&PG(http_globals)[TRACK_VARS_SERVER], "HTTP_X_PHP_PROFILER_TRIGGER", "secret");
    AUTOMATIC_ASSERT(profiler_trigger_matches((const unsigned char *)"secret", 6) == 1);
    automatic_fault = 3;
    AUTOMATIC_ASSERT(profiler_auto_header("X-Php-Profiler-Id: contract") == 0);
    automatic_fault = 0;
    zval_ptr_dtor(&PG(http_globals)[TRACK_VARS_SERVER]);
    PG(http_globals)[TRACK_VARS_SERVER] = saved;
    sapi_module.name = original_name;
    sapi_module.getenv = original_getenv;
    RETURN_TRUE;
}
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
        correct = status == 0 && profiler_flags.pending_bailout;
    } else if (mode && strcmp(mode, "log") == 0) {
        automatic_fault = 1;
        profiler_auto_log();
        correct = profiler_flags.pending_bailout;
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
            ZEND_FE(profiler_test_uid_threads, arginfo_uid_bool) ZEND_FE(profiler_test_uid_cache,
                                                                         arginfo_uid_closure)
                ZEND_FE(profiler_test_named_cache, arginfo_named_cache)
                    ZEND_FE(profiler_test_closure_storage, arginfo_closure_storage)
                        ZEND_FE(profiler_test_exhaust_closure_ids, arginfo_guard_contract)
                            ZEND_FE(profiler_test_uid_clock_failed, arginfo_uid_bool)
                                ZEND_FE(profiler_test_fiber_callbacks, arginfo_fiber_callbacks)
                                    ZEND_FE(profiler_test_fiber_observation_arm, arginfo_uid_bool)
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
