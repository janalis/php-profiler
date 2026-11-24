/* Private dependency transports live in a separate translation unit so the
 * production call sites retain their ordinary Mull mutation inventory. */
#include <php.h>
#include <main/SAPI.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>
int automatic_test_fault(void);
#ifdef __linux__
int __real_sapi_header_op(sapi_header_op_enum op, void *argument);
bool __real_zend_is_auto_global_str(const char *name, size_t length);
zend_result __real_zend_register_ini_entries(const zend_ini_entry_def *entries, int number);
#define automatic_test_header __wrap_sapi_header_op
#define automatic_test_global __wrap_zend_is_auto_global_str
#define automatic_test_register __wrap_zend_register_ini_entries
void __real_profiler_worker_request_begin(void);
void __real_profiler_worker_request_end(void);
#define automatic_test_worker_begin __wrap_profiler_worker_request_begin
#define automatic_test_worker_end __wrap_profiler_worker_request_end
#define real_worker_begin __real_profiler_worker_request_begin
#define real_worker_end __real_profiler_worker_request_end
#define real_header __real_sapi_header_op
#define real_global __real_zend_is_auto_global_str
#define real_register __real_zend_register_ini_entries
#else
#define real_header sapi_header_op
#define real_global zend_is_auto_global_str
#define real_register zend_register_ini_entries
void profiler_worker_request_begin(void);
void profiler_worker_request_end(void);
#define real_worker_begin profiler_worker_request_begin
#define real_worker_end profiler_worker_request_end
#endif

int automatic_test_logs;
/* FrankenPHP worker request boundaries, in call order, recorded before the adapter runs them. */
char automatic_worker_log[32];
void automatic_worker_note(char event) {
    size_t length = strlen(automatic_worker_log);
    if (length + 1 < sizeof(automatic_worker_log)) {
        automatic_worker_log[length] = event;
        automatic_worker_log[length + 1] = 0;
    }
}
void automatic_test_worker_begin(void) {
    automatic_worker_note('b');
    real_worker_begin();
}
void automatic_test_defer_bailout(void);
void automatic_test_worker_end(void) {
    automatic_worker_note('e');
    real_worker_end();
    /* Fault 6: ending the request defers a fatal error, as a failed report write would. */
    if (automatic_test_fault() == 6)
        automatic_test_defer_bailout();
}

void automatic_test_log(const char *message) {
    automatic_test_logs++;
    if (automatic_test_fault() == 1)
        zend_bailout();
    php_log_err(message);
}
int automatic_test_header(sapi_header_op_enum op, void *argument) {
    if (automatic_test_fault() == 2)
        zend_bailout();
    if (automatic_test_fault() == 3)
        return FAILURE;
    return real_header(op, argument);
}
bool automatic_test_global(const char *name, size_t length) {
    if (automatic_test_fault() == 4)
        zend_bailout();
    return real_global(name, length);
}
int automatic_test_register(const zend_ini_entry_def *entries, int number) {
    const char *mode = getenv("PROFILER_TEST_BAILOUT");
    if (mode && !strcmp(mode, "ini-register")) {
        (void)write(2, "INJECTED_INI_REGISTER_FAILURE\n",
                    sizeof("INJECTED_INI_REGISTER_FAILURE\n") - 1);
        return FAILURE;
    }
    /* Fails only the separate php_profiler.observer table, after the capture settings
     * registered, so startup's second registration failure is reached on its own. */
    if (mode && !strcmp(mode, "observer-ini-register") && entries && entries[0].name &&
        !strcmp(entries[0].name, "php_profiler.observer")) {
        (void)write(2, "INJECTED_OBSERVER_INI_REGISTER_FAILURE\n",
                    sizeof("INJECTED_OBSERVER_INI_REGISTER_FAILURE\n") - 1);
        return FAILURE;
    }
    return real_register(entries, number);
}
