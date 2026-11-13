/* Diagnostic-only interposition; not linked into the production extension. */
#include <stdint.h>
#include <string.h>
#include <unistd.h>
extern _Noreturn void _zend_bailout(const char *, uint32_t);
static _Noreturn void observed_bailout(const char *file, uint32_t line) {
    (void)write(2, "PROBE_BAILOUT ", 14);
    (void)write(2, file, strlen(file));
    (void)write(2, "\n", 1);
    _zend_bailout(file, line);
}
#ifdef __APPLE__
__attribute__((used)) static struct {
    const void *replacement;
    const void *original;
} interpose __attribute__((section("__DATA,__interpose"))) = {(const void *)observed_bailout,
                                                              (const void *)_zend_bailout};
#endif
