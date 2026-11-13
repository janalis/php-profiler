#define _GNU_SOURCE
#include <dlfcn.h>
#include <errno.h>
#include <pthread.h>
#include <signal.h>
#include <stdatomic.h>
#include <stdbool.h>
#include <stdint.h>
#include <string.h>
#include <unistd.h>
static pthread_mutex_t mutex = PTHREAD_MUTEX_INITIALIZER;
static pthread_t handles[64];
static bool used[64];
static unsigned created, joined, live, peak, invalid;
static int (*real_create)(pthread_t *, const pthread_attr_t *, void *(*)(void *), void *);
static int (*real_join)(pthread_t, void **);
__attribute__((constructor)) static void initialize(void) {
    real_create = dlsym(RTLD_NEXT, "pthread_create");
    real_join = dlsym(RTLD_NEXT, "pthread_join");
}
static bool profiler_entry(void *function) {
    Dl_info info;
    return dladdr(function, &info) && info.dli_fname &&
           strstr(info.dli_fname, "libphp_profiler.so");
}
int pthread_create(pthread_t *thread, const pthread_attr_t *attr, void *(*entry)(void *),
                   void *arg) {
    if (!real_create)
        return ENOSYS;
    bool worker = profiler_entry((void *)entry);
    size_t stack = 0, guard = 0;
    sigset_t mask;
    bool valid = !worker || (attr && pthread_attr_getstacksize(attr, &stack) == 0 &&
                             stack == 256 * 1024 && pthread_attr_getguardsize(attr, &guard) == 0 &&
                             guard == (size_t)sysconf(_SC_PAGESIZE) &&
                             pthread_attr_getsigmask_np(attr, &mask) == 0);
    if (worker && valid) {
        for (int number = 1; number < NSIG; ++number) {
            /* The kernel cannot block KILL/STOP; glibc owns the signals below
             * public SIGRTMIN and deliberately removes them from this attribute. */
            if (number == SIGKILL || number == SIGSTOP ||
                (number >= __SIGRTMIN && number < SIGRTMIN))
                continue;
            if (sigismember(&mask, number) != 1)
                valid = false;
        }
    }
    int status = real_create(thread, attr, entry, arg);
    if (worker && status == 0) {
        pthread_mutex_lock(&mutex);
        unsigned i;
        for (i = 0; i < 64 && used[i]; i++)
            ;
        if (i == 64)
            invalid++;
        else {
            used[i] = true;
            handles[i] = *thread;
            created++;
            live++;
            if (live > peak)
                peak = live;
        }
        if (!valid)
            invalid++;
        pthread_mutex_unlock(&mutex);
    }
    return status;
}
int pthread_join(pthread_t thread, void **result) {
    if (!real_join)
        return ENOSYS;
    int status = real_join(thread, result);
    if (status == 0) {
        pthread_mutex_lock(&mutex);
        for (unsigned i = 0; i < 64; i++)
            if (used[i] && pthread_equal(handles[i], thread)) {
                used[i] = false;
                joined++;
                live--;
                break;
            }
        pthread_mutex_unlock(&mutex);
    }
    return status;
}
void sampler_worker_counts(unsigned out[5]) {
    pthread_mutex_lock(&mutex);
    out[0] = created;
    out[1] = joined;
    out[2] = live;
    out[3] = peak;
    out[4] = invalid;
    pthread_mutex_unlock(&mutex);
}
