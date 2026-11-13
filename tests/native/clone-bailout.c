/* Test-only fault at the real clone error allocation boundary. No production hook. */
#include <php.h>
#include <string.h>
#include <unistd.h>
static void clone_error(zend_class_entry *exception, const char *format, ...) {
    (void)exception;
    const char prefix[] = "Trying to clone an uncloneable object of class ";
    if (strncmp(format, prefix, sizeof(prefix) - 1) != 0) {
        (void)write(2, "UNEXPECTED_THROW_ERROR\n", 23);
        _exit(98);
    }
    (void)write(2, "CLONE_ALLOCATION_BAILOUT\n", 25);
    EG(exit_status) = 255;
    zend_bailout();
}
#ifdef __APPLE__
__attribute__((used)) static struct {
    const void *replacement;
    const void *original;
} interpose __attribute__((section("__DATA,__interpose"))) = {(const void *)clone_error,
                                                              (const void *)zend_throw_error};
#else
void __wrap_zend_throw_error(zend_class_entry *exception, const char *format, ...) {
    clone_error(exception, format);
}
#endif
