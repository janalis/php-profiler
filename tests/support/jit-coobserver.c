/* Test-only independent observer. Load at startup; not a profiler dependency. */
#include <php.h>
#include <Zend/zend_observer.h>
#include <string.h>
#ifdef ZTS
ZEND_TSRMLS_CACHE_DEFINE()
#endif
ZEND_TLS uint64_t other_begin_counts[5];
ZEND_TLS uint64_t other_end_counts[5];
static int receiver_index(zend_execute_data *data) {
    zend_function *function = data->func;
    if (!function || !ZEND_USER_CODE(function->type) || !function->common.scope ||
        !function->common.function_name ||
        !zend_string_equals_literal(function->common.function_name, "visit"))
        return -1;
    zend_string *name = function->common.scope->name;
    if (ZSTR_LEN(name) != 8 || memcmp(ZSTR_VAL(name), "JitMega", 7) != 0)
        return -1;
    int index = (unsigned char)ZSTR_VAL(name)[7] - 'A';
    return index >= 0 && index < 5 ? index : -1;
}
static void other_begin(zend_execute_data *data) {
    int index = receiver_index(data);
    if (index >= 0)
        other_begin_counts[index]++;
}
static void other_end(zend_execute_data *data, zval *result) {
    (void)result;
    int index = receiver_index(data);
    if (index >= 0)
        other_end_counts[index]++;
}
static zend_observer_fcall_handlers other_init(zend_execute_data *data) {
    return receiver_index(data) >= 0 ? (zend_observer_fcall_handlers){other_begin, other_end}
                                     : (zend_observer_fcall_handlers){NULL, NULL};
}
static PHP_FUNCTION(profiler_test_other_observer_counts) {
    ZEND_PARSE_PARAMETERS_NONE();
    zval begin, end;
    array_init(&begin);
    array_init(&end);
    array_init(return_value);
    for (int index = 0; index < 5; index++) {
        add_next_index_long(&begin, (zend_long)other_begin_counts[index]);
        add_next_index_long(&end, (zend_long)other_end_counts[index]);
    }
    add_assoc_zval(return_value, "begin", &begin);
    add_assoc_zval(return_value, "end", &end);
}
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_other_counts, 0, 0, IS_ARRAY, 0)
ZEND_END_ARG_INFO()
static const zend_function_entry other_functions[] = {
    PHP_FE(profiler_test_other_observer_counts, arginfo_other_counts) PHP_FE_END};
static PHP_MINIT_FUNCTION(other_observer) {
    if (type != MODULE_PERSISTENT)
        return FAILURE;
    zend_observer_fcall_register(other_init);
    return SUCCESS;
}
zend_module_entry profiler_test_other_observer_module_entry = {STANDARD_MODULE_HEADER,
                                                               "profiler_test_other_observer",
                                                               other_functions,
                                                               PHP_MINIT(other_observer),
                                                               NULL,
                                                               NULL,
                                                               NULL,
                                                               NULL,
                                                               "0.1.0",
                                                               STANDARD_MODULE_PROPERTIES};
ZEND_GET_MODULE(profiler_test_other_observer)
