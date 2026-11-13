/* Test-only custom embedding SAPI identities exercise bounded engine metadata. */
#include <sapi/embed/php_embed.h>
#include <Zend/zend_exceptions.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
static const char code[] = "$s=PhpProfiler\\Profiler::start();usleep(1);$s->stop();"
                           "$m=$s->getReport()['runtime_metadata'];"
                           "if($expected_size<=128){if($m['sapi']!==str_repeat('s',$expected_size))"
                           "throw new RuntimeException('bounded engine identity changed');}"
                           "else{if($m['sapi']!=='<omitted: value exceeds 128 "
                           "bytes>'||$m['execution_metadata_complete']!=='false')throw new "
                           "RuntimeException('oversized engine identity retained');}"
                           "$s->reset();";
static const char binary_code[] = "$s=PhpProfiler\\Profiler::start();usleep(1);$s->stop();"
                                  "if($s->getReport()['runtime_metadata']['sapi']!=='{bytes:80}')"
                                  "throw new RuntimeException('engine identity bytes replaced');"
                                  "$s->reset();";
int main(int argc, char **argv) {
    if (argc != 2)
        return 2;
    php_embed_module.php_ini_path_override = argv[1];
    php_embed_module.php_ini_ignore_cwd = 1;
    if (php_embed_init(1, argv) == FAILURE)
        return 2;
    char *original = sapi_module.name;
    const size_t sizes[] = {0, 128, 129, 8192};
    volatile int failed = 0;
    for (size_t index = 0; index < sizeof(sizes) / sizeof(sizes[0]); ++index) {
        size_t size = sizes[index];
        char *name = malloc(size + 1);
        if (!name)
            return 2;
        memset(name, 's', size);
        name[size] = 0;
        sapi_module.name = name;
        zend_first_try {
            zval expected;
            ZVAL_LONG(&expected, (zend_long)size);
            zend_hash_str_update(&EG(symbol_table), "expected_size", sizeof("expected_size") - 1,
                                 &expected);
            if (zend_eval_string(code, NULL, "engine identity bounds test") == FAILURE ||
                EG(exception)) {
                failed = 1;
                if (EG(exception))
                    zend_exception_error(EG(exception), E_ERROR);
            }
        }
        zend_catch { failed = 1; }
        zend_end_try();
        sapi_module.name = original;
        free(name);
        if (failed)
            break;
    }
    char invalid_name[] = {(char)0x80, 0};
    if (!failed) {
        sapi_module.name = invalid_name;
        zend_first_try {
            if (zend_eval_string(binary_code, NULL, "engine identity byte test") == FAILURE ||
                EG(exception)) {
                failed = 1;
                if (EG(exception))
                    zend_exception_error(EG(exception), E_ERROR);
            }
        }
        zend_catch { failed = 1; }
        zend_end_try();
        sapi_module.name = original;
    }
    php_embed_shutdown();
    if (failed)
        return 1;
    puts("engine metadata 0/128/129/8192-byte bounds and lossless bytes passed");
    return 0;
}
