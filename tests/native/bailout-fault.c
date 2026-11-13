/* Test-only dependency failures at the real C guard boundaries. */
#include <php.h>
#include <ext/json/php_json.h>
#include <Zend/zend_observer.h>
#include <Zend/zend_extensions.h>
#include <stdint.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>
#include <time.h>
#include <errno.h>
static bool fail_handle_api(const char *name) {
    const char *mode = getenv("PROFILER_TEST_BAILOUT");
    if (mode && strcmp(mode, "handle-api") == 0 && name &&
        strcmp(name, "PHP Profiler closure identity") == 0) {
        (void)write(2, "INJECTED_HANDLE_API_FAILURE\n",
                    sizeof("INJECTED_HANDLE_API_FAILURE\n") - 1);
        return true;
    }
    return false;
}
static int injected;
static zend_observer_fcall_init observed_init;
static zend_observer_fcall_handlers observed_handlers;
ZEND_TLS bool control_window;
static void boundary_begin(zend_execute_data *data) {
    if (observed_handlers.begin)
        observed_handlers.begin(data);
    const char *mode = getenv("PROFILER_TEST_BAILOUT");
    if (mode && strcmp(mode, "control") == 0 && data->func->common.scope &&
        zend_string_equals_literal(data->func->common.scope->name, "PhpProfiler\\Profiler") &&
        zend_string_equals_literal_ci(data->func->common.function_name, "isEnabled"))
        control_window = true;
}
static void boundary_end(zend_execute_data *data, zval *result) {
    if (observed_handlers.end)
        observed_handlers.end(data, result);
    control_window = false;
}
static zend_observer_fcall_handlers boundary_init(zend_execute_data *data) {
    observed_handlers = observed_init(data);
    return (zend_observer_fcall_handlers){boundary_begin, boundary_end};
}
static void inject(const char *mode) {
    const char *selected = getenv("PROFILER_TEST_BAILOUT");
    if (!injected && selected && strcmp(selected, mode) == 0) {
        injected = 1;
        (void)write(2, "INJECTED_NATIVE_BAILOUT\n", 24);
        EG(exit_status) = 255;
        zend_bailout();
    }
}
#ifdef __APPLE__
static int op_array_handle(const char *name) {
    return fail_handle_api(name) ? -1 : zend_get_op_array_extension_handle(name);
}
static void registration(zend_observer_fcall_init init) {
    observed_init = init;
    zend_observer_fcall_register(boundary_init);
}
static int clock_read(clockid_t id, struct timespec *value) {
    if (control_window) {
        errno = EINVAL;
        return -1;
    }
    return clock_gettime(id, value);
}
static zend_result decode(zval *out, const char *text, size_t len, zend_long flags,
                          zend_long depth) {
    inject("decode");
    return php_json_decode_ex(out, text, len, flags, depth);
}
static int allowed(const char *path, bool warn) {
    inject("allowed");
    return php_check_open_basedir_ex(path, warn);
}
static char *resolve(const char *path, char *out) {
    inject("resolve");
    return expand_filepath(path, out);
}
static void *allocate(size_t size) {
    if (size > 4096)
        inject("string");
    return _emalloc(size);
}
#define INTERPOSE(replacement, original) {(const void *)(replacement), (const void *)(original)}
__attribute__((used)) static struct {
    const void *replacement;
    const void *original;
} interpose[] __attribute__((section("__DATA,__interpose"))) = {
    INTERPOSE(decode, php_json_decode_ex),
    INTERPOSE(allowed, php_check_open_basedir_ex),
    INTERPOSE(resolve, expand_filepath),
    INTERPOSE(allocate, _emalloc),
    INTERPOSE(registration, zend_observer_fcall_register),
    INTERPOSE(clock_read, clock_gettime),
    INTERPOSE(op_array_handle, zend_get_op_array_extension_handle)};
#else
extern int __real_zend_get_op_array_extension_handle(const char *);
int __wrap_zend_get_op_array_extension_handle(const char *name) {
    return fail_handle_api(name) ? -1 : __real_zend_get_op_array_extension_handle(name);
}
extern zend_result __real_php_json_decode_ex(zval *, const char *, size_t, zend_long, zend_long);
extern int __real_php_check_open_basedir_ex(const char *, bool);
extern char *__real_expand_filepath(const char *, char *);
extern void *ZEND_FASTCALL __real__emalloc(size_t);
extern int __real_clock_gettime(clockid_t, struct timespec *);
/* Optional private sampler provider router. It delegates ordinary clocks and
 * confines sampler FI to its instrumented monotonic getter domain. Keeping
 * this strong wrapper preserves the independent bailout control-window fault. */
extern int sampling_test_route_clock(clockid_t, struct timespec *) __attribute__((weak));
int __wrap_clock_gettime(clockid_t id, struct timespec *value) {
    if (control_window) {
        errno = EINVAL;
        return -1;
    }
    if (sampling_test_route_clock)
        return sampling_test_route_clock(id, value);
    return __real_clock_gettime(id, value);
}
zend_result __wrap_php_json_decode_ex(zval *out, const char *text, size_t len, zend_long flags,
                                      zend_long depth) {
    inject("decode");
    return __real_php_json_decode_ex(out, text, len, flags, depth);
}
int __wrap_php_check_open_basedir_ex(const char *path, bool warn) {
    inject("allowed");
    return __real_php_check_open_basedir_ex(path, warn);
}
char *__wrap_expand_filepath(const char *path, char *out) {
    inject("resolve");
    const char *mode = getenv("PROFILER_TEST_BAILOUT");
    if (mode && strcmp(mode, "failure") == 0)
        return NULL;
    return __real_expand_filepath(path, out);
}
void *ZEND_FASTCALL __wrap__emalloc(size_t size) {
    if (size > 4096)
        inject("string");
    return __real__emalloc(size);
}
extern int __real_profiler_resolve_path(const char *, unsigned char *, size_t);
int __wrap_profiler_resolve_path(const char *path, unsigned char *out, size_t size) {
    const char *mode = getenv("PROFILER_TEST_BAILOUT");
    if (mode && strcmp(mode, "unterminated") == 0) {
        /* Deliberately violate this dependency's output contract in the test DSO.
         * The Rust boundary must reject it without reading beyond capacity. */
        memset(out, 'x', size);
        return 1;
    }
    size_t capacity = mode && strcmp(mode, "small") == 0 ? 1 : size;
    if (mode && strcmp(mode, "exact") == 0)
        capacity = strlen(path);
    return __real_profiler_resolve_path(path, out, capacity);
}
/* Unit-only public wrappers around otherwise private C boundary contracts. */
extern void profiler_string(const unsigned char *, size_t, zval *);
extern int profiler_json_decode(const unsigned char *, size_t, zval *);
extern const unsigned char *profiler_ini(const unsigned char *, size_t, size_t *);
static ZEND_FUNCTION(profiler_test_empty_string) {
    profiler_string((const unsigned char *)"", 0, return_value);
}
static ZEND_FUNCTION(profiler_test_big_integer) {
    const unsigned char text[] = "{\"value\":18446744073709551615}";
    if (profiler_json_decode(text, sizeof(text) - 1, return_value) != SUCCESS)
        RETURN_FALSE;
}
static ZEND_FUNCTION(profiler_test_ini_boundaries) {
    size_t length = 99;
    HashTable *saved = EG(ini_directives);
    EG(ini_directives) = NULL;
    const unsigned char *value = profiler_ini((const unsigned char *)"memory_limit", 12, &length);
    EG(ini_directives) = saved;
    if (value != NULL || length != 0)
        RETURN_FALSE;
    value = profiler_ini((const unsigned char *)"profiler_fixture_missing", 24, &length);
    if (value != NULL || length != 0)
        RETURN_FALSE;
    zend_ini_entry *entry = zend_hash_str_find_ptr(saved, "memory_limit", 12);
    zend_string *original = entry->value;
    entry->value = NULL;
    value = profiler_ini((const unsigned char *)"memory_limit", 12, &length);
    entry->value = original;
    if (value != NULL || length != 0)
        RETURN_FALSE;
    RETURN_TRUE;
}
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_boundary, 0, 0, IS_MIXED, 0)
ZEND_END_ARG_INFO()
static const zend_function_entry boundary_functions[] = {
    ZEND_FE(profiler_test_empty_string, arginfo_boundary)
        ZEND_FE(profiler_test_big_integer, arginfo_boundary)
            ZEND_FE(profiler_test_ini_boundaries, arginfo_boundary) ZEND_FE_END};
void profiler_test_boundary_unregister(void) {
    zend_module_entry *owner = EG(current_module);
    if (!owner)
        return;
    for (const zend_function_entry *entry = boundary_functions; entry->fname; entry++) {
        zend_function *function =
            zend_hash_str_find_ptr(CG(function_table), entry->fname, strlen(entry->fname));
        if (function && function->type == ZEND_INTERNAL_FUNCTION &&
            function->internal_function.module == owner &&
            function->internal_function.handler == entry->handler) {
            zend_unregister_functions(entry, 1, NULL);
        }
    }
}
static zend_result boundary_registration_result = FAILURE;
int profiler_test_boundary_registration_status(void) { return boundary_registration_result; }
extern void __real_zend_observer_fcall_register(zend_observer_fcall_init);
void __wrap_zend_observer_fcall_register(zend_observer_fcall_init init) {
    observed_init = init;
    /* Every real MINIT registration call overwrites this result. */
    boundary_registration_result =
        zend_register_functions(NULL, boundary_functions, NULL, MODULE_PERSISTENT);
    /* The native void API must still publish the real observer even when a
     * test helper collides and no private status consumer is compiled. */
    __real_zend_observer_fcall_register(boundary_registration_result == SUCCESS ? boundary_init
                                                                                : init);
}
#endif
