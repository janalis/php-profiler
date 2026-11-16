#include <php.h>
/* Engine-owned INI lifecycle probes, only in the private contract DSO. */
int profiler_test_config_number(void) {
    zend_module_entry *module = zend_hash_str_find_ptr(&module_registry, ZEND_STRL("php_profiler"));
    return module ? module->module_number : -1;
}
int profiler_test_config_set(const char *name, const char *value) {
    volatile int result = FAILURE;
    zend_try {
        zend_string *key = zend_string_init(name, strlen(name), 0);
        result = zend_alter_ini_entry_chars(key, value, strlen(value), PHP_INI_SYSTEM,
                                            ZEND_INI_STAGE_ACTIVATE);
        zend_string_release(key);
    }
    zend_catch { result = FAILURE; }
    zend_end_try();
    return result;
}
int profiler_test_config_type(int type) {
    zend_module_entry *module = zend_hash_str_find_ptr(&module_registry, ZEND_STRL("php_profiler"));
    if (!module)
        return -1;
    int previous = module->type;
    module->type = type;
    return previous;
}
