/* Test-only bailout at the actual exception message allocation boundary. */
#include <php.h>
#include <Zend/zend_exceptions.h>
#include <string.h>
#include <unistd.h>
#ifdef __APPLE__
#define ORIGINAL_THROW zend_throw_exception
#else
extern zend_object *__real_zend_throw_exception(zend_class_entry *, const char *, zend_long);
#define ORIGINAL_THROW __real_zend_throw_exception
#endif
static zend_object *exception_throw(zend_class_entry *exception, const char *message,
                                    zend_long code) {
    /* Short calls prime the real fallback cleanup stack before baseline capture. */
    if (message && strlen(message) >= 8 * 1024 * 1024) {
        (void)write(2, "EXCEPTION_ALLOCATION_BAILOUT\n", 29);
        EG(exit_status) = 255;
        zend_bailout();
    }
    return ORIGINAL_THROW(exception, message, code);
}
#ifdef __APPLE__
__attribute__((used)) static struct {
    const void *replacement;
    const void *original;
} interpose __attribute__((section("__DATA,__interpose"))) = {(const void *)exception_throw,
                                                              (const void *)zend_throw_exception};
#else
zend_object *__wrap_zend_throw_exception(zend_class_entry *exception, const char *message,
                                         zend_long code) {
    return exception_throw(exception, message, code);
}
#endif
