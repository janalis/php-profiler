/* Test-only ZTS embedding harness. Lifecycle follows the public embed/TSRM APIs:
 * https://github.com/php/php-src/blob/PHP-8.5/sapi/embed/php_embed.c
 * https://github.com/php/php-src/blob/PHP-8.5/TSRM/TSRM.c
 * php_embed_init owns process startup and an initial request; worker threads own
 * TSRM resources and repeated requests; the main thread owns final shutdown. */
#include <sapi/embed/php_embed.h>
#include <Zend/zend_exceptions.h>
#include <Zend/zend_closures.h>
#include <pthread.h>
#include <stdatomic.h>
#include <stdio.h>
#include <string.h>
#include <stdlib.h>

#ifndef ZTS
#error "This integration test requires a ZTS PHP embed library"
#endif
#define THREAD_COUNT 4
#define REQUEST_COUNT 20
static pthread_mutex_t barrier_mutex = PTHREAD_MUTEX_INITIALIZER;
static pthread_cond_t barrier_condition = PTHREAD_COND_INITIALIZER;
static unsigned waiting = 0;
static unsigned generation = 0;
static atomic_int failed = 0;
static const char *output_directory;
static const char *fixture_file;
static const char *receipt_directory;
static void rendezvous(void) {
    pthread_mutex_lock(&barrier_mutex);
    unsigned current = generation;
    if (++waiting == THREAD_COUNT) {
        waiting = 0;
        ++generation;
        pthread_cond_broadcast(&barrier_condition);
    } else {
        while (current == generation)
            pthread_cond_wait(&barrier_condition, &barrier_mutex);
    }
    pthread_mutex_unlock(&barrier_mutex);
}
/* Test-only SAPI function observes genuine closure storage without exposing it
 * through the production extension or writing op-array/SHM data. */
static PHP_FUNCTION(profiler_embed_closure_storage) {
    zval *closure;
    ZEND_PARSE_PARAMETERS_START(1, 1)
    Z_PARAM_OBJECT_OF_CLASS(closure, zend_ce_closure)
    ZEND_PARSE_PARAMETERS_END();
    const zend_function *function = zend_get_closure_method_def(Z_OBJ_P(closure));
    if (function->type != ZEND_USER_FUNCTION) {
        RETURN_FALSE;
    }
    char opcodes[32], cache[32];
    snprintf(opcodes, sizeof(opcodes), "%p", (void *)function->op_array.opcodes);
    snprintf(cache, sizeof(cache), "%p", RUN_TIME_CACHE(&function->op_array));
    array_init(return_value);
    add_assoc_string(return_value, "opcodes", opcodes);
    add_assoc_string(return_value, "runtime_cache", cache);
}
ZEND_BEGIN_ARG_INFO_EX(arginfo_closure_storage, 0, 0, 1)
ZEND_ARG_OBJ_INFO(0, closure, Closure, 0)
ZEND_END_ARG_INFO()
static const zend_function_entry harness_functions[] = {
    ZEND_FE(profiler_embed_closure_storage, arginfo_closure_storage) ZEND_FE_END};
static int (*original_startup)(sapi_module_struct *module);
static int harness_startup(sapi_module_struct *module) {
    /* php_embed_init installs its defaults before this public SAPI callback.
     * Preserve those functions, adding only the test-only storage observer. */
    static zend_function_entry functions[32];
    size_t count = 0;
    if (module->additional_functions) {
        while (module->additional_functions[count].fname) {
            if (count >= 30)
                return FAILURE;
            functions[count] = module->additional_functions[count];
            ++count;
        }
    }
    functions[count] = harness_functions[0];
    functions[count + 1] = (zend_function_entry)ZEND_FE_END;
    module->additional_functions = functions;
    return original_startup(module);
}
static const char request_code[] = "require $profiler_test_fixture;";
static void *worker(void *argument) {
    intptr_t index = (intptr_t)argument;
    /* Allocates this thread's registered globals and runs their constructors. */
    ts_resource(0);
    ZEND_TSRMLS_CACHE_UPDATE();
    for (int request = 0; request < REQUEST_COUNT; ++request) {
        volatile int active = 0;
        zend_first_try {
            if (php_request_startup() == SUCCESS)
                active = 1;
            else
                atomic_store(&failed, 1);
        }
        zend_catch { atomic_store(&failed, 1); }
        zend_end_try();
        rendezvous();
        if (active && !atomic_load(&failed)) {
            zend_try {
                SG(headers_sent) = 1;
                SG(request_info).no_headers = 1;
                zval directory, token, fixture, receipt;
                char receipt_path[4096];
                snprintf(receipt_path, sizeof(receipt_path), "%s/%ld-%d.json", receipt_directory,
                         (long)index, request);
                ZVAL_STRING(&directory, output_directory);
                ZVAL_LONG(&token, (zend_long)(index * 1000 + request));
                ZVAL_STRING(&fixture, fixture_file);
                ZVAL_STRING(&receipt, receipt_path);
                zend_hash_str_update(&EG(symbol_table), "profiler_test_fixture",
                                     sizeof("profiler_test_fixture") - 1, &fixture);
                zend_hash_str_update(&EG(symbol_table), "profiler_test_receipt",
                                     sizeof("profiler_test_receipt") - 1, &receipt);
                zend_hash_str_update(&EG(symbol_table), "profiler_test_output",
                                     sizeof("profiler_test_output") - 1, &directory);
                zend_hash_str_update(&EG(symbol_table), "profiler_test_token",
                                     sizeof("profiler_test_token") - 1, &token);
                if (zend_eval_string(request_code, NULL, "native ZTS profiler integration") ==
                        FAILURE ||
                    EG(exception)) {
                    atomic_store(&failed, 1);
                    fprintf(stderr, "ZTS request failed in thread %ld iteration %d\n", (long)index,
                            request);
                    if (EG(exception))
                        zend_exception_error(EG(exception), E_ERROR);
                }
            }
            zend_catch { atomic_store(&failed, 1); }
            zend_end_try();
        }
        if (active) {
            zend_try { php_request_shutdown(NULL); }
            zend_catch { atomic_store(&failed, 1); }
            zend_end_try();
        }
    }
    ts_free_thread();
    return NULL;
}
int main(int argc, char **argv) {
    if (argc != 5) {
        fprintf(stderr,
                "usage: zts_threads php.ini report-directory fixture-file receipt-directory\n");
        return 2;
    }
    output_directory = argv[2];
    fixture_file = argv[3];
    receipt_directory = argv[4];
    original_startup = php_embed_module.startup;
    php_embed_module.startup = harness_startup;
    php_embed_module.php_ini_path_override = argv[1];
    php_embed_module.php_ini_ignore_cwd = 1;
    if (php_embed_init(1, argv) == FAILURE) {
        fprintf(stderr, "PHP embed startup failed\n");
        return 2;
    }
    /* Warm the same real source into shared OPcache when the embed build
     * supports it. No SAPI-name spoofing or synthetic Zend function is used. */
    zval fixture;
    ZVAL_STRING(&fixture, fixture_file);
    zend_hash_str_update(&EG(symbol_table), "profiler_test_fixture",
                         sizeof("profiler_test_fixture") - 1, &fixture);
    if (zend_eval_string(
            "if(function_exists('opcache_get_status')&&is_array(opcache_get_status(false))){if(!"
            "opcache_compile_file($profiler_test_fixture)||!opcache_compile_file(dirname($profiler_"
            "test_fixture).'/closure-worker-functions.php'))throw new RuntimeException('shared "
            "OPcache warmup failed');}",
            NULL, "ZTS shared fixture warmup") == FAILURE ||
        EG(exception)) {
        fprintf(stderr, "shared fixture warmup failed\n");
        return 2;
    }
    /* Finish the request created by php_embed_init before worker requests. */
    php_request_shutdown(NULL);
    pthread_t threads[THREAD_COUNT];
    for (intptr_t index = 0; index < THREAD_COUNT; ++index) {
        if (pthread_create(&threads[index], NULL, worker, (void *)index)) {
            fprintf(stderr, "cannot create ZTS test thread\n");
            _Exit(2);
        }
    }
    for (unsigned index = 0; index < THREAD_COUNT; ++index)
        pthread_join(threads[index], NULL);
    /* php_embed_shutdown expects an active main-thread request. */
    if (php_request_startup() == FAILURE) {
        fprintf(stderr, "main request restart failed\n");
        return 2;
    }
    php_embed_shutdown();
    pthread_cond_destroy(&barrier_condition);
    pthread_mutex_destroy(&barrier_mutex);
    if (atomic_load(&failed))
        return 1;
    printf("ZTS same-process isolation: %d threads x %d requests passed\n", THREAD_COUNT,
           REQUEST_COUNT);
    return 0;
}
