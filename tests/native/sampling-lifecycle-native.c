#ifndef _GNU_SOURCE
#define _GNU_SOURCE
#endif
#if defined(__linux__)
#include <features.h>
#endif
#ifdef PROFILER_SAMPLING_TEST_TRANSPORTS
#ifndef _GNU_SOURCE
#define _GNU_SOURCE
#endif
#include <pthread.h>
#include <stdatomic.h>
#include <stdbool.h>
#include <errno.h>
#include <unistd.h>
/* Test-only observation of actual native resource ownership. No Zend fields,
 * handles, timer ticks, or clock values are synthesized by these transports. */
#define SAMPLING_LIFECYCLE_OWNERSHIP_AVAILABLE 1
int __real_pthread_mutex_lock(pthread_mutex_t *);
int __real_pthread_mutex_unlock(pthread_mutex_t *);
int __real_pthread_attr_destroy(pthread_attr_t *);
_Atomic unsigned sampling_lifecycle_faults;
static _Thread_local pthread_mutex_t *sampling_lifecycle_held[16];
static _Thread_local bool sampling_lifecycle_registry_owned;
static _Thread_local const pthread_attr_t *sampling_lifecycle_mask_owners[8];
_Atomic unsigned sampling_lifecycle_wait_zero;
bool sampling_lifecycle_mutex_owned(pthread_mutex_t *mutex) {
    for (unsigned i = 0; i < 16; i++)
        if (sampling_lifecycle_held[i] == mutex)
            return true;
    return false;
}
unsigned sampling_lifecycle_mutex_depth(void) {
    unsigned count = 0;
    for (unsigned i = 0; i < 16; i++)
        if (sampling_lifecycle_held[i])
            count++;
    return count;
}
bool sampling_lifecycle_contract_valid(void) {
    return atomic_load(&sampling_lifecycle_faults) == 0;
}
int sampling_lifecycle_mutex_lock(pthread_mutex_t *mutex) {
    int result = __real_pthread_mutex_lock(mutex);
    if (!result) {
        for (unsigned i = 0; i < 16; i++)
            if (!sampling_lifecycle_held[i]) {
                sampling_lifecycle_held[i] = mutex;
                return result;
            }
        atomic_fetch_add(&sampling_lifecycle_faults, 1);
    }
    return result;
}
int sampling_lifecycle_mutex_unlock(pthread_mutex_t *mutex) {
    for (unsigned i = 0; i < 16; i++)
        if (sampling_lifecycle_held[i] == mutex) {
            int result = __real_pthread_mutex_unlock(mutex);
            if (!result)
                sampling_lifecycle_held[i] = NULL;
            return result;
        }
    /* Do not perform undefined unowned unlock merely to observe a bad mutant. */
    atomic_fetch_add(&sampling_lifecycle_faults, 1);
    return EPERM;
}
int sampling_lifecycle_wait_enter(pthread_mutex_t *mutex) {
    if (sampling_lifecycle_mutex_owned(mutex))
        return 0;
    atomic_fetch_add(&sampling_lifecycle_faults, 1);
    int result = sampling_lifecycle_mutex_lock(mutex);
    return result ? result : EPERM;
}
bool sampling_lifecycle_flag_set(volatile atomic_flag *flag, memory_order order) {
    bool previous = atomic_flag_test_and_set_explicit(flag, order);
    if (!previous) {
        if (sampling_lifecycle_registry_owned)
            atomic_fetch_add(&sampling_lifecycle_faults, 1);
        sampling_lifecycle_registry_owned = true;
    }
    return previous;
}
void sampling_lifecycle_flag_clear(volatile atomic_flag *flag, memory_order order) {
    if (!sampling_lifecycle_registry_owned)
        atomic_fetch_add(&sampling_lifecycle_faults, 1);
    sampling_lifecycle_registry_owned = false;
    atomic_flag_clear_explicit(flag, order);
}
void sampling_lifecycle_mask_acquired(const pthread_attr_t *attributes) {
    for (unsigned i = 0; i < 8; i++)
        if (!sampling_lifecycle_mask_owners[i]) {
            sampling_lifecycle_mask_owners[i] = attributes;
            return;
        }
    atomic_fetch_add(&sampling_lifecycle_faults, 1);
}
int sampling_lifecycle_attr_destroy(pthread_attr_t *attributes) {
    int result = __real_pthread_attr_destroy(attributes);
    if (!result)
        for (unsigned i = 0; i < 8; i++)
            if (sampling_lifecycle_mask_owners[i] == attributes) {
                sampling_lifecycle_mask_owners[i] = NULL;
                break;
            }
    return result;
}
bool sampling_lifecycle_masks_retired(void) {
    for (unsigned i = 0; i < 8; i++)
        if (sampling_lifecycle_mask_owners[i])
            return false;
    return true;
}
void sampling_lifecycle_wait_result(int result) {
    if (result == 0)
        atomic_fetch_add(&sampling_lifecycle_wait_zero, 1);
}

int __wrap_pthread_mutex_lock(pthread_mutex_t *mutex) {
    return sampling_lifecycle_mutex_lock(mutex);
}
int __wrap_pthread_mutex_unlock(pthread_mutex_t *mutex) {
    return sampling_lifecycle_mutex_unlock(mutex);
}
int __wrap_pthread_attr_destroy(pthread_attr_t *attributes) {
    return sampling_lifecycle_attr_destroy(attributes);
}

_Atomic bool sampling_lifecycle_page_zero;
_Atomic long sampling_lifecycle_minimum_override;
long __real___sysconf(int);
long __wrap___sysconf(int name) {
    long minimum = atomic_load(&sampling_lifecycle_minimum_override);
    if (name == _SC_THREAD_STACK_MIN && minimum > 0)
        return minimum;
    return __real___sysconf(name);
}

static _Atomic unsigned sampling_lifecycle_mutex_created, sampling_lifecycle_mutex_destroyed,
    sampling_lifecycle_cond_created, sampling_lifecycle_cond_destroyed;
int __real_pthread_mutex_init(pthread_mutex_t *, const pthread_mutexattr_t *);
int sampling_test_route_mutex_init(pthread_mutex_t *, const pthread_mutexattr_t *);
int __real_pthread_mutex_destroy(pthread_mutex_t *);
int __real_pthread_cond_init(pthread_cond_t *, const pthread_condattr_t *);
int sampling_test_route_cond_init(pthread_cond_t *, const pthread_condattr_t *);
int __real_pthread_cond_destroy(pthread_cond_t *);
int __wrap_pthread_mutex_init(pthread_mutex_t *m, const pthread_mutexattr_t *a) {
    int r = sampling_test_route_mutex_init(m, a);
    if (!r)
        atomic_fetch_add(&sampling_lifecycle_mutex_created, 1);
    return r;
}
int __wrap_pthread_mutex_destroy(pthread_mutex_t *m) {
    int r = __real_pthread_mutex_destroy(m);
    if (!r)
        atomic_fetch_add(&sampling_lifecycle_mutex_destroyed, 1);
    return r;
}
int __wrap_pthread_cond_init(pthread_cond_t *c, const pthread_condattr_t *a) {
    int r = sampling_test_route_cond_init(c, a);
    if (!r)
        atomic_fetch_add(&sampling_lifecycle_cond_created, 1);
    return r;
}
int __wrap_pthread_cond_destroy(pthread_cond_t *c) {
    int r = __real_pthread_cond_destroy(c);
    if (!r)
        atomic_fetch_add(&sampling_lifecycle_cond_destroyed, 1);
    return r;
}
unsigned sampling_lifecycle_live_resources(int conditions) {
    return conditions ? atomic_load(&sampling_lifecycle_cond_created) -
                            atomic_load(&sampling_lifecycle_cond_destroyed)
                      : atomic_load(&sampling_lifecycle_mutex_created) -
                            atomic_load(&sampling_lifecycle_mutex_destroyed);
}

#else
typedef int sampling_lifecycle_unused_platform;
#endif
