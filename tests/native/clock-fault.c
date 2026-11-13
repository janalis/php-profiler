/* Subprocess-only fault injection: never linked into the shipped extension. */
#define _GNU_SOURCE
#include <time.h>
#include <errno.h>
#include <stdlib.h>
#include <dlfcn.h>
#include <string.h>
#include <stdint.h>
static int failing_clock_gettime(clockid_t id, struct timespec *value) {
    const char *mode = getenv("PROFILER_TEST_CLOCK");
    if (mode && ((mode[0] == 'c' && id == CLOCK_THREAD_CPUTIME_ID) ||
                 (mode[0] == 'w' && id == CLOCK_MONOTONIC))) {
        value->tv_sec = 0;
        value->tv_nsec = 0;
        if (strstr(mode, "zero")) {
            return 0;
        }
        if (strstr(mode, "add_overflow")) {
            value->tv_sec = (time_t)(UINT64_MAX / 1000000000ULL);
            value->tv_nsec = (long)(UINT64_MAX % 1000000000ULL + 1);
            return 0;
        }
        if (strstr(mode, "boundary")) {
            value->tv_sec = (time_t)(UINT64_MAX / 1000000000ULL);
            value->tv_nsec = (long)(UINT64_MAX % 1000000000ULL);
            return 0;
        }
        if (strstr(mode, "overflow")) {
            value->tv_sec = (time_t)(UINT64_MAX / 1000000000ULL + 1);
            return 0;
        }
        if (strstr(mode, "negative_nsec")) {
            value->tv_nsec = -1;
            return 0;
        }
        if (strstr(mode, "negative")) {
            value->tv_sec = -1;
            return 0;
        }
        if (strstr(mode, "nsec")) {
            value->tv_nsec = 1000000000L;
            return 0;
        }
        errno = EINVAL;
        return -1;
    }
#ifdef __APPLE__
    /* dyld does not apply an image's interpositions to its own symbol binds. */
    return clock_gettime(id, value);
#else
    int (*original)(clockid_t, struct timespec *) = dlsym(RTLD_NEXT, "clock_gettime");
    if (!original) {
        errno = ENOSYS;
        return -1;
    }
    return original(id, value);
#endif
}
#ifdef __APPLE__
__attribute__((used)) static struct {
    const void *replacement;
    const void *original;
} interpose __attribute__((section("__DATA,__interpose"))) = {(const void *)failing_clock_gettime,
                                                              (const void *)clock_gettime};
#else
/* PHP uses RTLD_DEEPBIND on Linux, which otherwise bypasses LD_PRELOAD.
 * Change resolution only for this test's profiler DSO; the production loader
 * and all other extensions retain their original flags. */
void *dlopen(const char *name, int flags) {
    void *(*original)(const char *, int) = dlsym(RTLD_NEXT, "dlopen");
    if (!original)
        return NULL;
    if (name && strstr(name, "libphp_profiler."))
        flags &= ~RTLD_DEEPBIND;
    return original(name, flags);
}
int clock_gettime(clockid_t id, struct timespec *value) { return failing_clock_gettime(id, value); }
#endif
