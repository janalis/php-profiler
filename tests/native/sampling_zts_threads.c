#ifndef _GNU_SOURCE
#define _GNU_SOURCE
#endif
#include <Zend/zend_exceptions.h>
#include <dlfcn.h>
#include <pthread.h>
#include <sapi/embed/php_embed.h>
#include <stdatomic.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <sys/wait.h>
#include <unistd.h>
#ifndef ZTS
#error ZTS required
#endif
static atomic_int failed;
static const char *fixture, *receipts;
static void (*counts)(unsigned out[5]);
static pthread_mutex_t lock = PTHREAD_MUTEX_INITIALIZER;
static pthread_cond_t condition = PTHREAD_COND_INITIALIZER;
static unsigned waiting, generation;
static void barrier(void) {
    pthread_mutex_lock(&lock);
    unsigned g = generation;
    if (++waiting == 4) {
        waiting = 0;
        generation++;
        pthread_cond_broadcast(&condition);
    } else
        while (generation == g)
            pthread_cond_wait(&condition, &lock);
    pthread_mutex_unlock(&lock);
}
static PHP_FUNCTION(embed_sample_barrier) {
    ZEND_PARSE_PARAMETERS_NONE();
    barrier();
}
ZEND_BEGIN_ARG_INFO_EX(no_args, 0, 0, 0)
ZEND_END_ARG_INFO()
static const zend_function_entry native[] = {PHP_FE(embed_sample_barrier, no_args) PHP_FE_END};
static int (*original_startup)(sapi_module_struct *);
static int startup(sapi_module_struct *module) {
    static zend_function_entry functions[32];
    size_t i = 0;
    if (module->additional_functions)
        while (module->additional_functions[i].fname) {
            if (i >= 30)
                return FAILURE;
            functions[i] = module->additional_functions[i];
            i++;
        }
    functions[i] = native[0];
    functions[i + 1] = (zend_function_entry)ZEND_FE_END;
    module->additional_functions = functions;
    return original_startup(module);
}
static void evaluate(const char *code) {
    zend_first_try {
        if (zend_eval_string(code, NULL, "Cargo namespace pthread sampler") != SUCCESS ||
            EG(exception)) {
            atomic_store(&failed, 1);
            if (EG(exception))
                zend_exception_error(EG(exception), E_ERROR);
        }
    }
    zend_catch { atomic_store(&failed, 1); }
    zend_end_try();
}
static int request_start(void) {
    if (php_request_startup() != SUCCESS) {
        atomic_store(&failed, 1);
        return 0;
    }
    SG(headers_sent) = 1;
    SG(request_info).no_headers = 1;
    return 1;
}
static void *worker(void *arg) {
    intptr_t index = (intptr_t)arg;
    ts_resource(0);
    ZEND_TSRMLS_CACHE_UPDATE();
    for (int n = 0; n < 5; n++) {
        if (!request_start())
            return NULL;
        char path[4096];
        snprintf(path, sizeof(path), "%s/%ld-%d.json", receipts, (long)index, n);
        zval f, r;
        ZVAL_STRING(&f, fixture);
        ZVAL_STRING(&r, path);
        zend_hash_str_update(&EG(symbol_table), "embed_fixture", 13, &f);
        zend_hash_str_update(&EG(symbol_table), "embed_receipt", 13, &r);
        barrier();
        evaluate("require $embed_fixture;");
        php_request_shutdown(NULL);
    }
    ts_free_thread();
    return NULL;
}
static void *lifecycle(void *unused) {
    (void)unused;
    ts_resource(0);
    ZEND_TSRMLS_CACHE_UPDATE();
    if (!request_start())
        return NULL;
    evaluate("$unfinished=PhpProfiler\\Profiler::start(null,['sampling'=>true]);if($"
             "unfinished->getId()!==1)throw new RuntimeException('unfinishedid');");
    php_request_shutdown(NULL);
    if (!request_start())
        return NULL;
    evaluate("$s=PhpProfiler\\Profiler::start(null,['sampling'=>true]);if($s->"
             "getId()!==1)throw new "
             "RuntimeException('recoveryid');usleep(30000);$s->stop();if($s->"
             "getReport()['status']!=='complete')throw new "
             "RuntimeException('RSHUTDOWN recovery');");
    php_request_shutdown(NULL);
    ts_free_thread();
    return NULL;
}
static void *fallback(void *unused) {
    (void)unused;
    ts_resource(0);
    ZEND_TSRMLS_CACHE_UPDATE();
    if (!request_start())
        return NULL;
    evaluate("$s=PhpProfiler\\Profiler::start(null,['sampling'=>true]);if($s->getId()!"
             "==1)throw new RuntimeException('fallbackid');"); /* Genuine abandoned
                                                                  request,
                                                                  subprocess-only
                                                                  lifecycle fault. */
    ts_free_thread();
    return NULL;
}
static int verify(unsigned expected) {
    unsigned v[5];
    counts(v);
    printf("workers created=%u actual_joins=%u live=%u peak=%u invalid_attrs=%u\n", v[0], v[1],
           v[2], v[3], v[4]);
    return v[0] == expected && v[1] == expected && v[2] == 0 && v[3] == 4 && v[4] == 0;
}
static int owner_main(int argc, char **argv) {
    if (argc != 5)
        return 2;
    fixture = argv[3];
    receipts = argv[4];
    counts = dlsym(RTLD_DEFAULT, "sampler_worker_counts");
    if (!counts)
        return 3;
    original_startup = php_embed_module.startup;
    php_embed_module.startup = startup;
    php_embed_module.php_ini_path_override = argv[1];
    php_embed_module.php_ini_ignore_cwd = 1;
    if (php_embed_init(1, argv) != SUCCESS)
        return 4;
    php_request_shutdown(NULL);
    pthread_t threads[4];
    for (intptr_t i = 0; i < 4; i++)
        if (pthread_create(&threads[i], NULL, worker, (void *)i))
            return 5;
    for (int i = 0; i < 4; i++)
        pthread_join(threads[i], NULL);
    if (!verify(20))
        return 6;
    pthread_t t;
    if (pthread_create(&t, NULL, lifecycle, NULL))
        return 7;
    pthread_join(t, NULL);
    if (!verify(22))
        return 8;
    if (pthread_create(&t, NULL, fallback, NULL))
        return 9;
    pthread_join(t, NULL);
    if (!verify(23))
        return 10;
    if (!request_start())
        return 11;
    php_embed_shutdown();
    if (atomic_load(&failed))
        return 15;
    return 0;
}
static int owner_argc;
static char **owner_argv;
static void *owner(void *unused) {
    (void)unused;
    return (void *)(intptr_t)owner_main(owner_argc, owner_argv);
}
int main(int argc, char **argv) {
    if (argc != 5)
        return 2;
    owner_argc = argc;
    owner_argv = argv;
    pthread_t thread;
    if (pthread_create(&thread, NULL, owner, NULL))
        return 16;
    void *result;
    if (pthread_join(thread, &result))
        return 17;
    if (result)
        return (int)(intptr_t)result;
    /* Normal thread return ran Rust TLS destructors. Release an ordinary
     * inspection handle so glibc can reevaluate its pending unload. */
    void *module = dlopen(argv[2], RTLD_NOW | RTLD_NOLOAD);
    if (module)
        dlclose(module);
    module = dlopen(argv[2], RTLD_NOW | RTLD_NOLOAD);
    if (module) {
        dlclose(module);
        fprintf(stderr, "Cargo module retained after owner TLS retirement\n");
        return 12;
    }
    for (int n = 0; n < 3; n++) {
        pid_t child = fork();
        if (child < 0)
            return 13;
        if (!child)
            _exit(0);
        int status;
        if (waitpid(child, &status, 0) != child || !WIFEXITED(status) || WEXITSTATUS(status))
            return 14;
    }
    puts("PASS Cargo namespace sampled pthread requests, RSHUTDOWN+TSRM "
         "fallback, normal TLS retirement + actual DSO unload/postshutdown fork");
    return 0;
}
