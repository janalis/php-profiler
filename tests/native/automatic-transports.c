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
#define real_header __real_sapi_header_op
#define real_global __real_zend_is_auto_global_str
#define real_register __real_zend_register_ini_entries
#else
#define real_header sapi_header_op
#define real_global zend_is_auto_global_str
#define real_register zend_register_ini_entries
#endif

void automatic_test_log(const char *message) {
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
    return real_register(entries, number);
}
