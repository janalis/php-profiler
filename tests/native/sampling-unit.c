/* Debug-only provider transport. All normal calls use real libc/PHP resources;
 * faults return documented native errors before acquisition, once per process.
 * No Zend structures or pthread handles are synthesized. */
#ifndef _GNU_SOURCE
#define _GNU_SOURCE
#endif
#include <php.h>
#include <Zend/zend_generators.h>
#include <pthread.h>
#include <signal.h>
#include <stdatomic.h>
#include <errno.h>
#include <time.h>
#include <unistd.h>
#include <sys/wait.h>
#include <stdlib.h>
#include <string.h>
#include "../../crates/php-profiler/src/sampling.h"
static _Atomic int sampling_test_fault;
static _Atomic unsigned sampling_test_consumed;
static _Atomic unsigned sampling_test_joined;
static _Atomic unsigned sampling_test_created;
static _Atomic bool sampling_test_attributes_valid;
static pthread_t sampling_test_php_thread;
#ifdef PROFILER_SAMPLING_TEST_TRANSPORTS
static _Thread_local unsigned sampling_test_worker_clocks;
static _Thread_local struct timespec sampling_test_deadline;
static _Thread_local bool sampling_test_in_service;
static _Thread_local unsigned sampling_test_service_clocks;
static _Thread_local bool sampling_test_retry_wait;
static _Thread_local struct timespec sampling_test_retry_deadline;
static _Thread_local unsigned sampling_test_forced_spurious;
static _Atomic unsigned sampling_test_wait_entries, sampling_test_zero_wakes,
    sampling_test_deadline_checks;
static _Atomic bool sampling_test_same_deadline = true;
static _Atomic bool sampling_test_contention_armed, sampling_test_yield_seen,
    sampling_test_lock_ready;
#endif
static const char *const sampling_test_modes[] = {"none",
                                                  "mutex",
                                                  "condattr",
                                                  "setclock",
                                                  "condition",
                                                  "attr",
                                                  "page",
                                                  "stack",
                                                  "guard",
                                                  "mask",
                                                  "create",
                                                  "join",
                                                  "clock",
                                                  "range",
                                                  "wait",
                                                  "atfork",
                                                  "snapshot-clock",
                                                  "snapshot-range",
                                                  "nsec-negative",
                                                  "nsec-upper",
                                                  "seconds-overflow",
                                                  "deadline-overflow",
                                                  "spurious",
                                                  "wake-clock",
                                                  "wake-range",
                                                  "wake-regression",
                                                  "wake-equal",
                                                  "service-clock",
                                                  "service-range",
                                                  "service-regression",
                                                  "tsrm",
                                                  "identity"};
static int sampling_test_mode(const char *name) {
    if (!name)
        return 0;
    for (unsigned i = 0; i < sizeof(sampling_test_modes) / sizeof(*sampling_test_modes); i++)
        if (!strcmp(name, sampling_test_modes[i]))
            return (int)i;
    return -1;
}
#ifdef PROFILER_SAMPLING_TEST_TRANSPORTS
#define SAMPLING_LIFECYCLE_OWNERSHIP_AVAILABLE 1
extern _Atomic unsigned sampling_lifecycle_faults;
extern _Atomic unsigned sampling_lifecycle_wait_zero;
bool sampling_lifecycle_mutex_owned(pthread_mutex_t *);
unsigned sampling_lifecycle_mutex_depth(void);
bool sampling_lifecycle_contract_valid(void);
int sampling_lifecycle_mutex_lock(pthread_mutex_t *);
int sampling_lifecycle_mutex_unlock(pthread_mutex_t *);
int sampling_lifecycle_wait_enter(pthread_mutex_t *);
bool sampling_lifecycle_flag_set(volatile atomic_flag *, memory_order);
void sampling_lifecycle_flag_clear(volatile atomic_flag *, memory_order);
void sampling_lifecycle_mask_acquired(const pthread_attr_t *);
int sampling_lifecycle_attr_destroy(pthread_attr_t *);
bool sampling_lifecycle_masks_retired(void);
void sampling_lifecycle_wait_result(int);
extern _Atomic bool sampling_lifecycle_page_zero;
extern _Atomic long sampling_lifecycle_minimum_override;
unsigned sampling_lifecycle_live_resources(int);

extern int __real_pthread_mutex_init(pthread_mutex_t *, const pthread_mutexattr_t *);
extern int __real_pthread_cond_init(pthread_cond_t *, const pthread_condattr_t *);
static void sampling_test_join_yield_contract(void);
static bool sampling_test_take(int mode) {
    int expected = mode;
    if (atomic_compare_exchange_strong(&sampling_test_fault, &expected, 0)) {
        atomic_fetch_add(&sampling_test_consumed, 1);
        return true;
    }
    return false;
}
static int sampling_test_mutex_init(pthread_mutex_t *m, const pthread_mutexattr_t *a) {
    return sampling_test_take(1) ? ENOMEM : __real_pthread_mutex_init(m, a);
}
static int sampling_test_condattr_init(pthread_condattr_t *a) {
    return sampling_test_take(2) ? ENOMEM : pthread_condattr_init(a);
}
static int sampling_test_setclock(pthread_condattr_t *a, clockid_t c) {
    return sampling_test_take(3) ? EINVAL : pthread_condattr_setclock(a, c);
}
static int sampling_test_cond_init(pthread_cond_t *c, const pthread_condattr_t *a) {
    return sampling_test_take(4) ? ENOMEM : __real_pthread_cond_init(c, a);
}
static int sampling_test_attr_init(pthread_attr_t *a) {
    return sampling_test_take(5) ? ENOMEM : pthread_attr_init(a);
}
static long sampling_test_sysconf(int name) {
    if (name == _SC_PAGESIZE && atomic_load(&sampling_lifecycle_page_zero))
        return 0;
    if (name == _SC_PAGESIZE && sampling_test_take(6))
        return -1;
    return sysconf(name);
}
static int sampling_test_stack(pthread_attr_t *a, size_t n) {
    return sampling_test_take(7) ? EINVAL : pthread_attr_setstacksize(a, n);
}
static int sampling_test_guard(pthread_attr_t *a, size_t n) {
    return sampling_test_take(8) ? EINVAL : pthread_attr_setguardsize(a, n);
}
#ifdef PROFILER_SAMPLING_TEST_TRANSPORTS
#if __GLIBC_PREREQ(2, 32)
static int sampling_test_mask(pthread_attr_t *a, const sigset_t *s) {
    int status = sampling_test_take(9) ? EINVAL : pthread_attr_setsigmask_np(a, s);
    if (!status)
        sampling_lifecycle_mask_acquired(a);
    return status;
}
#endif
#endif
static int sampling_test_create(pthread_t *t, const pthread_attr_t *a, void *(*f)(void *),
                                void *p) {
    size_t stack = 0, guard = 0;
    sigset_t mask;
    bool valid = pthread_attr_getstacksize(a, &stack) == 0 && stack == 256 * 1024 &&
                 pthread_attr_getguardsize(a, &guard) == 0 &&
                 guard == (size_t)sysconf(_SC_PAGESIZE);
#if __GLIBC_PREREQ(2, 32)
    valid = valid && pthread_attr_getsigmask_np(a, &mask) == 0 &&
            sigismember(&mask, SIGUSR1) == 1 && sigismember(&mask, SIGUSR2) == 1;
#endif
    atomic_store(&sampling_test_attributes_valid, valid);
    if (sampling_test_take(10))
        return EAGAIN;
    int result = pthread_create(t, a, f, p);
    if (!result)
        atomic_fetch_add(&sampling_test_created, 1);
    return result;
}
static int sampling_test_join(pthread_t t, void **p) {
    /* A transient error does NOT consume/join the handle; retry must really join. */
    if (sampling_test_take(11))
        return EINVAL;
    int result = pthread_join(t, p);
    if (!result)
        atomic_fetch_add(&sampling_test_joined, 1);
    return result;
}
/* Private primitive-clock unit inputs; never emitted as measured samples. */
static _Thread_local bool sampling_engine_clock_override;
static _Thread_local struct timespec sampling_engine_clock_value;
static _Thread_local bool sampling_engine_clock_fail;
static _Thread_local int sampling_engine_clock_errno;
#ifdef SAMPLING_LIFECYCLE_OWNERSHIP_AVAILABLE
static _Thread_local pthread_mutex_t *sampling_engine_snapshot_mutex;
static _Thread_local unsigned sampling_engine_snapshot_checks;
static _Thread_local bool sampling_engine_snapshot_owned;
#endif
static _Atomic bool sampling_engine_deadline_override;
static _Atomic unsigned sampling_engine_deadline_seen;
static struct timespec sampling_engine_observed_deadline;
/* Test-only actual interrupt observations. Normal provider calls delegate unchanged. */
void sampling_frames_observe_contract(const profiler_sample_observation *observation);
#ifdef PROFILER_SAMPLING_TEST_TRANSPORTS
enum sampling_frames_mode {
    FRAMES_OFF,
    FRAMES_EQUAL,
    FRAMES_EQUAL_WAKE,
    FRAMES_CLOCK,
    FRAMES_RANGE,
    FRAMES_REGRESSION,
    FRAMES_IDENTITY,
    FRAMES_DEPTH,
    FRAMES_BACKLOG
};
static _Thread_local enum sampling_frames_mode sampling_frames_mode;
static _Thread_local bool sampling_frames_valid = true;
static _Thread_local unsigned sampling_frames_observations, sampling_frames_clock_step;
static _Thread_local struct timespec sampling_frames_snapshot;
/* Equal successive observations are valid for a nondecreasing POSIX clock.
 * The replayed value is the preceding REAL snapshot, confined to this ABI test. */
static void sampling_frames_note_ready(void);
static void sampling_frames_note_drained(void);
static uint64_t sampling_frames_last_drained_wake(void);
static bool sampling_frames_clock_override(clockid_t clock, struct timespec *value, int *result) {
    if (sampling_frames_mode == FRAMES_OFF || !sampling_test_in_service ||
        !pthread_equal(pthread_self(), sampling_test_php_thread))
        return false;
    unsigned step = ++sampling_frames_clock_step;
    if (step == 1)
        sampling_frames_note_drained();
    if (sampling_frames_mode != FRAMES_EQUAL && sampling_frames_mode != FRAMES_EQUAL_WAKE)
        return false;
    if (step == 1) {
        if (sampling_frames_mode == FRAMES_EQUAL_WAKE) {
            uint64_t wake = sampling_frames_last_drained_wake();
            *value = (struct timespec){(time_t)(wake / UINT64_C(1000000000)),
                                       (long)(wake % UINT64_C(1000000000))};
            *result = 0;
        } else
            *result = clock_gettime(clock, value);
        if (!*result)
            sampling_frames_snapshot = *value;
        else
            sampling_frames_valid = false;
        return true;
    }
    if (step == 2) {
        *value = sampling_frames_snapshot;
        *result = 0;
        return true;
    }
    return false;
}
#endif

static int sampling_test_clock(clockid_t c, struct timespec *t) {
    int intercepted;
    if (sampling_frames_clock_override(c, t, &intercepted))
        return intercepted;
    bool owner = pthread_equal(pthread_self(), sampling_test_php_thread);
#ifdef SAMPLING_LIFECYCLE_OWNERSHIP_AVAILABLE
    if (owner && sampling_engine_snapshot_mutex) {
        sampling_engine_snapshot_checks++;
        if (!sampling_lifecycle_mutex_owned(sampling_engine_snapshot_mutex))
            sampling_engine_snapshot_owned = false;
    }
#endif
    if (sampling_engine_clock_override) {
        errno = sampling_engine_clock_errno;
        if (sampling_engine_clock_fail)
            return -1;
        *t = sampling_engine_clock_value;
        return 0;
    }
    if (!owner && atomic_load(&sampling_engine_deadline_override)) {
        uint64_t boundary = UINT64_MAX - PROFILER_SAMPLE_PERIOD_NS;
        *t = (struct timespec){(time_t)(boundary / 1000000000), (long)(boundary % 1000000000)};
        return 0;
    }

    if (!owner) {
        sampling_test_worker_clocks++;
        if (sampling_test_worker_clocks == 2) {
            if (sampling_test_take(23)) {
                errno = EIO;
                return -1;
            }
            if (sampling_test_take(24)) {
                errno = EDOM;
                *t = (struct timespec){0, -1};
                return 0;
            }
            if (sampling_test_take(25)) {
                *t = (struct timespec){0, 0};
                return 0;
            }
            if (sampling_test_take(26)) {
                *t = sampling_test_deadline;
                return 0;
            }
        }
    } else if (sampling_test_in_service && ++sampling_test_service_clocks == 2) {
        if (sampling_test_take(27)) {
            errno = EIO;
            return -1;
        }
        if (sampling_test_take(28)) {
            *t = (struct timespec){0, -1};
            return 0;
        }
        if (sampling_test_take(29)) {
            *t = (struct timespec){0, 0};
            return 0;
        }
    }
    if ((!owner && sampling_test_take(12)) || (owner && sampling_test_take(16))) {
        errno = EIO;
        return -1;
    }
    if ((!owner && sampling_test_take(13)) || (owner && sampling_test_take(17))) {
        errno = EDOM;
        *t = (struct timespec){-1, 0};
        return 0;
    }
    if (!owner && sampling_test_take(18)) {
        *t = (struct timespec){0, -1};
        return 0;
    }
    if (!owner && sampling_test_take(19)) {
        *t = (struct timespec){0, 1000000000};
        return 0;
    }
    if (!owner && sampling_test_take(20)) {
        *t = (struct timespec){INT64_MAX, 0};
        return 0;
    }
    if (!owner && sampling_test_take(21)) {
        uint64_t near_maximum = UINT64_MAX - PROFILER_SAMPLE_PERIOD_NS + 1;
        *t = (struct timespec){(time_t)(near_maximum / 1000000000),
                               (long)(near_maximum % 1000000000)};
        return 0;
    }
    return clock_gettime(c, t);
}
static int sampling_test_wait(pthread_cond_t *c, pthread_mutex_t *m, const struct timespec *t) {
#ifdef SAMPLING_LIFECYCLE_OWNERSHIP_AVAILABLE
    int ownership_error = sampling_lifecycle_wait_enter(m);
    if (ownership_error)
        return ownership_error;
#endif
    if (atomic_load(&sampling_engine_deadline_override)) {
        sampling_engine_observed_deadline = *t;
        atomic_fetch_add(&sampling_engine_deadline_seen, 1);
        return EINVAL;
    }

    if (sampling_test_retry_wait) {
        atomic_fetch_add(&sampling_test_deadline_checks, 1);
        if (sampling_test_retry_deadline.tv_sec != t->tv_sec ||
            sampling_test_retry_deadline.tv_nsec != t->tv_nsec)
            atomic_store(&sampling_test_same_deadline, false);
    }
    sampling_test_deadline = *t;
    atomic_fetch_add(&sampling_test_wait_entries, 1);
    if (sampling_test_take(22))
        sampling_test_forced_spurious = 2;
    int result;
    if (sampling_test_forced_spurious) {
        sampling_test_forced_spurious--;
        result = 0;
    } else if (sampling_test_take(14))
        result = EINVAL;
    else {
        result = pthread_cond_timedwait(c, m, t);
#ifdef SAMPLING_LIFECYCLE_OWNERSHIP_AVAILABLE
        sampling_lifecycle_wait_result(result);
#endif
    }
    sampling_test_retry_wait = result == 0;
    if (!result) {
        sampling_test_retry_deadline = *t;
        atomic_fetch_add(&sampling_test_zero_wakes, 1);
    }
    return result;
}
static _Atomic unsigned sampling_frames_atfork_calls;
static int sampling_test_atfork(void (*a)(void), void (*b)(void), void (*c)(void)) {
    if (sampling_test_take(15))
        return ENOMEM;
    int result = pthread_atfork(a, b, c);
    if (!result)
        atomic_fetch_add(&sampling_frames_atfork_calls, 1);
    return result;
}
extern uint64_t profiler_sampling_closure_identity(zend_function *function);
uint64_t sampling_test_closure_identity(zend_function *function) {
    if (sampling_test_take(31))
        return 0;
    return profiler_sampling_closure_identity(function);
}
static int sampling_test_yield(void) {
    sampling_test_join_yield_contract();
    if (atomic_load(&sampling_test_contention_armed))
        atomic_store(&sampling_test_yield_seen, true);
    return sched_yield();
}
#ifdef ZTS
extern ts_rsrc_id __real_ts_allocate_id(ts_rsrc_id *, size_t, ts_allocate_ctor, ts_allocate_dtor);
static ts_rsrc_id sampling_test_allocate_id(ts_rsrc_id *id, size_t size, ts_allocate_ctor ctor,
                                            ts_allocate_dtor dtor) {
    if (sampling_test_take(30)) {
        *id = 0;
        return 0;
    }
    return __real_ts_allocate_id(id, size, ctor, dtor);
}
#endif
#endif
#ifdef PROFILER_SAMPLING_TEST_TRANSPORTS
#undef atomic_flag_test_and_set_explicit
#define atomic_flag_test_and_set_explicit sampling_lifecycle_flag_set
#undef atomic_flag_clear_explicit
#define atomic_flag_clear_explicit sampling_lifecycle_flag_clear

#endif
#define profiler_sampling_register sampling_test_register_real
#include "../../crates/php-profiler/src/sampling.c"
#undef profiler_sampling_register
#undef pthread_mutex_init
#undef pthread_condattr_init
#undef pthread_condattr_setclock
#undef pthread_cond_init
#undef pthread_attr_init
#undef sysconf
#undef pthread_attr_setstacksize
#undef pthread_attr_setguardsize
#undef pthread_attr_setsigmask_np
#undef pthread_create
#undef pthread_join
#undef clock_gettime
#undef pthread_cond_timedwait
#undef pthread_atfork
#undef sched_yield
#undef ts_allocate_id
#undef profiler_sampling_closure_identity
#ifdef PROFILER_SAMPLING_PLATFORM
static _Thread_local size_t sampling_frames_tail_before, sampling_frames_drain_count;
static _Thread_local bool sampling_frames_queue_noted;
/* These hooks run only while the REAL control mutex is held. */
static void sampling_frames_note_ready(void) {
    if (sampling_frames_mode == FRAMES_OFF)
        return;
    sampling_frames_tail_before = current_control()->tail;
    sampling_frames_queue_noted = true;
}
static void sampling_frames_note_drained(void) {
    if (!sampling_frames_queue_noted) {
        sampling_frames_valid = false;
        return;
    }
    size_t tail = current_control()->tail;
    sampling_frames_drain_count = tail >= sampling_frames_tail_before
                                      ? tail - sampling_frames_tail_before
                                      : PROFILER_SAMPLE_TICKS - sampling_frames_tail_before + tail;
}
static uint64_t sampling_frames_last_drained_wake(void) {
    sample_control *control = current_control();
    size_t previous = control->tail ? control->tail - 1 : PROFILER_SAMPLE_TICKS - 1;
    return control->ticks[previous].wake_ns;
}
#endif
int sampling_frames_inspect_contract(const profiler_sample_observation *observation) {
#ifdef PROFILER_SAMPLING_PLATFORM
    if (sampling_frames_mode != FRAMES_OFF && sampling_test_in_service) {
        sampling_frames_observations++;
        if (!observation || observation->tick_count != sampling_frames_drain_count) {
            sampling_frames_valid = false;
            /* Do not submit uninitialized cells from a corrupt producer count.
             * All normal observations are delegated byte-for-byte unchanged. */
            return 0;
        }
#ifdef SAMPLING_LIFECYCLE_OWNERSHIP_AVAILABLE
        if (sampling_lifecycle_mutex_depth() != 0 || !sampling_lifecycle_contract_valid())
            sampling_frames_valid = false;
#endif
        int expected = 0, system = 0;
        if (sampling_frames_mode == FRAMES_CLOCK) {
            expected = 3;
            system = EIO;
        }
        if (sampling_frames_mode == FRAMES_RANGE || sampling_frames_mode == FRAMES_REGRESSION)
            expected = 4;
        if (sampling_frames_mode == FRAMES_IDENTITY)
            expected = 9;
        /* A known synchronous fault occurred BEFORE this descriptor was made.
         * Reading the real protected backend state is clock-free and submits no
         * synthetic frame, wake, or observation. The original sink always runs. */
        sample_control *control = current_control();
        pthread_mutex_lock(&control->mutex);
        int actual = control->error, actual_system = control->system_error;
        pthread_mutex_unlock(&control->mutex);
        if (!observation || actual != expected || actual_system != system ||
            observation->status.error != actual ||
            observation->status.system_error != actual_system)
            sampling_frames_valid = false;
        if ((sampling_frames_mode == FRAMES_EQUAL || sampling_frames_mode == FRAMES_EQUAL_WAKE) &&
            observation &&
            (sampling_frames_clock_step < 2 ||
             observation->service_ns != observation->status.snapshot_ns))
            sampling_frames_valid = false;
        if (sampling_frames_mode == FRAMES_EQUAL_WAKE &&
            (!observation->tick_count ||
             observation->service_ns != observation->ticks[observation->tick_count - 1].wake_ns))
            sampling_frames_valid = false;
    }
#endif
    return 1;
}
static ZEND_FUNCTION(profiler_test_sampling_frame_contract) {
    char *mode;
    size_t length;
    ZEND_PARSE_PARAMETERS_START(1, 1) Z_PARAM_STRING(mode, length) ZEND_PARSE_PARAMETERS_END();
    array_init(return_value);
#ifdef PROFILER_SAMPLING_PLATFORM
    int expected = 0, system = 0;
    if (!strcmp(mode, "registration")) {
        unsigned before = atomic_load(&sampling_frames_atfork_calls);
        profiler_sampling_request_shutdown();
        profiler_sampling_module_shutdown();
        int first = sampling_test_register_real();
        profiler_sampling_request_init();
        profiler_sampling_request_shutdown();
        profiler_sampling_module_shutdown();
        int second = sampling_test_register_real();
        profiler_sampling_request_init();
        add_assoc_bool(return_value, "valid",
                       first == 1 && second == 1 && atfork_registered && before == 1 &&
                           atomic_load(&sampling_frames_atfork_calls) == before);
    } else if (!strcmp(mode, "verify")) {
        add_assoc_bool(return_value, "valid",
                       sampling_frames_valid && sampling_frames_observations > 0);
        add_assoc_long(return_value, "observations", sampling_frames_observations);
        sampling_frames_mode = FRAMES_OFF;
    } else {
        sampling_frames_mode = FRAMES_OFF;
        if (!strcmp(mode, "equal"))
            sampling_frames_mode = FRAMES_EQUAL;
        if (!strcmp(mode, "equal-wake"))
            sampling_frames_mode = FRAMES_EQUAL_WAKE;
        if (!strcmp(mode, "service-clock")) {
            sampling_frames_mode = FRAMES_CLOCK;
            expected = 3;
            system = EIO;
        }
        if (!strcmp(mode, "service-range")) {
            sampling_frames_mode = FRAMES_RANGE;
            expected = 4;
        }
        if (!strcmp(mode, "service-regression")) {
            sampling_frames_mode = FRAMES_REGRESSION;
            expected = 4;
        }
        if (!strcmp(mode, "identity")) {
            sampling_frames_mode = FRAMES_IDENTITY;
            expected = 9;
        }
        if (!strcmp(mode, "depth"))
            sampling_frames_mode = FRAMES_DEPTH;
        if (!strcmp(mode, "backlog"))
            sampling_frames_mode = FRAMES_BACKLOG;
        sampling_frames_valid = true;
        sampling_frames_observations = 0;
        sampling_frames_clock_step = 0;
        sampling_frames_queue_noted = false;
        add_assoc_bool(return_value, "valid", sampling_frames_mode != FRAMES_OFF);
    }
    add_assoc_long(return_value, "expected_error", expected);
    add_assoc_long(return_value, "expected_system_error", system);
    add_assoc_bool(return_value, "supported", true);
#else
    add_assoc_bool(return_value, "supported", false);
    add_assoc_bool(return_value, "valid", true);
#endif
}
/* Retain a full ring of ACTUAL independent timer wakes while the PHP thread is
 * inside C; then service its REAL live Zend frame before stopping the worker. */
static ZEND_FUNCTION(profiler_test_sampling_backlog_service) {
    ZEND_PARSE_PARAMETERS_NONE();
#ifdef PROFILER_SAMPLING_PLATFORM
    sample_control *control = current_control();
    pthread_mutex_lock(&control->mutex);
    uint64_t initial = control->wakes;
    pthread_mutex_unlock(&control->mutex);
    for (unsigned attempt = 0; attempt < 2000; attempt++) {
        pthread_mutex_lock(&control->mutex);
        bool ready =
            control->wakes >= initial && control->wakes - initial >= 66 && control->dropped > 0;
        bool failed = control->error != 0 || !control->worker_live;
        if (ready && !failed)
            sampling_frames_note_ready();
        pthread_mutex_unlock(&control->mutex);
        if (failed)
            RETURN_FALSE;
        if (ready) {
            sampling_test_in_service = true;
            sampling_test_service_clocks = 0;
            sample_interrupt(EG(current_execute_data));
            sampling_test_in_service = false;
            RETURN_TRUE;
        }
        usleep(5000);
    }
#endif
    RETURN_FALSE;
}
// clang-format off: preserve Zend argument declaration macro layout.
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(sampling_frames_info, 0, 1, IS_ARRAY, 0)
ZEND_ARG_TYPE_INFO(0, mode, IS_STRING, 0)
ZEND_END_ARG_INFO()
// clang-format on

#ifdef PROFILER_SAMPLING_PLATFORM
static bool sampling_lifecycle_empty_registry(void) {
    bool valid = true;
    lock_slots();
    for (unsigned i = 0; i < SAMPLE_SLOTS; i++)
        if (slots[i])
            valid = false;
    unlock_slots();
    return valid;
}
static bool sampling_lifecycle_zero_state(void) {
    profiler_sample_status status;
    profiler_sampling_status(&status);
    int kind = 99, native = 99;
    profiler_sampling_cleanup_error(&kind, &native);
    return status.wakes == 0 && status.dropped_ticks == 0 && status.pending_ticks == 0 &&
           status.termination == 0 && status.error == 0 && status.system_error == 0 &&
           status.running == 0 && !current_control()->worker_live && kind == 0 && native == 0;
}
static bool sampling_lifecycle_fork_contract(void) {
    sample_control extra;
    control_construct(&extra);
    if (!extra.condition_ready)
        return false;
    bool valid = sampling_lifecycle_empty_registry() && profiler_sampling_start() == 1;
    pthread_attr_t attributes;
    int status = pthread_attr_init(&attributes);
    if (!status)
        status = pthread_attr_setstacksize(&attributes, SAMPLE_STACK);
    long page = sysconf(_SC_PAGESIZE);
    if (!status)
        status = pthread_attr_setguardsize(&attributes, (size_t)page);
    sigset_t all;
    sigfillset(&all);
    if (!status)
        status = pthread_attr_setsigmask_np(&attributes, &all);
    extra.interrupt = &EG(vm_interrupt);
    extra.guard_bytes = (size_t)page;
    atomic_store(&extra.running, true);
    atomic_store(&extra.interested, true);
    if (!status)
        status = pthread_create(&extra.worker, &attributes, sample_worker, &extra);
    pthread_attr_destroy(&attributes);
    extra.worker_live = status == 0;
    if (status) {
        profiler_sampling_stop();
        control_destroy(&extra);
        return false;
    }
    lock_slots();
    slots[SAMPLE_SLOTS - 1] = &extra;
    unlock_slots();
    unsigned joined = atomic_load(&sampling_test_joined);
    pid_t child = fork();
    if (child == 0) {
        bool child_valid = true;
        for (unsigned i = 0; i < SAMPLE_SLOTS; i++)
            if (slots[i])
                child_valid = false;
        if (extra.termination != 2 || current_control()->termination != 2)
            child_valid = false;
        _exit(child_valid ? 0 : 73);
    }
    int child_status = 0;
    if (child < 0 || waitpid(child, &child_status, 0) != child)
        valid = false;
    else
        valid = valid && WIFEXITED(child_status) && WEXITSTATUS(child_status) == 0;
    valid = valid && atomic_load(&sampling_test_joined) == joined + 2 && extra.termination == 2 &&
            current_control()->termination == 2 && !extra.worker_live &&
            !current_control()->worker_live;
    /* Even a mutant failing prepare must retire the genuine extra worker
     * before this initialized stack object's lifetime ends. */
    stop_control(&extra, 1);
    control_destroy(&extra);
    profiler_sampling_stop();
    valid = valid && sampling_lifecycle_empty_registry() && sampling_lifecycle_contract_valid();
    profiler_sampling_request_init();
    return valid;
}
#endif
static ZEND_FUNCTION(profiler_test_sampling_lifecycle_contract) {
    char *mode;
    size_t length;
    ZEND_PARSE_PARAMETERS_START(1, 1) Z_PARAM_STRING(mode, length) ZEND_PARSE_PARAMETERS_END();
#ifdef PROFILER_SAMPLING_PLATFORM
    bool valid = sampling_lifecycle_contract_valid();
    profiler_sampling_reset();
    if (!strcmp(mode, "ctor-mutex")) {
        profiler_sample_status status;
        profiler_sampling_status(&status);
        valid = valid && status.error == 1 && status.system_error == ENOMEM &&
                current_control()->error == 1;
    } else if (!strcmp(mode, "normal") || !strcmp(mode, "join") || !strcmp(mode, "signal")) {
        unsigned zero = atomic_load(&sampling_lifecycle_wait_zero),
                 entered = atomic_load(&sampling_test_wait_entries);
        valid = valid && profiler_sampling_start() == 1;
        profiler_sample_status status;
        profiler_sampling_status(&status);
        valid = valid && status.running == 1 && status.termination == 0 &&
                status.worker_guard_bytes == (uint64_t)sysconf(_SC_PAGESIZE);
        for (unsigned i = 0; i < 2000 && atomic_load(&sampling_test_wait_entries) == entered; i++)
            usleep(1000);
        valid = valid && atomic_load(&sampling_test_wait_entries) > entered;
        if (!strcmp(mode, "join"))
            atomic_store(&sampling_test_fault, 11);
        profiler_sampling_stop();
        profiler_sampling_status(&status);
        int kind = -1, native = -1;
        profiler_sampling_cleanup_error(&kind, &native);
        if (!strcmp(mode, "join"))
            valid = valid && status.error == 7 && status.system_error == EINVAL &&
                    status.termination == 3 && kind == 7 && native == EINVAL;
        else
            valid = valid && status.error == 0 && status.system_error == 0 &&
                    status.termination == 1 && kind == 0 && native == 0;
        valid = valid && !current_control()->worker_live && sampling_lifecycle_empty_registry() &&
                sampling_lifecycle_masks_retired();
        if (!strcmp(mode, "signal"))
            valid = valid && atomic_load(&sampling_lifecycle_wait_zero) > zero;
        profiler_sampling_reset();
        valid = valid && sampling_lifecycle_zero_state();
    } else if (!strcmp(mode, "create") || !strcmp(mode, "attr")) {
        atomic_store(&sampling_test_fault, !strcmp(mode, "create") ? 10 : 5);
        valid = valid && profiler_sampling_start() == 0;
        profiler_sample_status status;
        profiler_sampling_status(&status);
        valid = valid && status.error == (!strcmp(mode, "create") ? 6 : 1) &&
                status.system_error == (!strcmp(mode, "create") ? EAGAIN : ENOMEM) &&
                status.termination == 3;
        valid = valid && sampling_lifecycle_masks_retired() && sampling_lifecycle_empty_registry();
        profiler_sampling_reset();
        valid = valid && sampling_lifecycle_zero_state();
    } else if (!strcmp(mode, "queued-reset")) {
        valid = valid && profiler_sampling_start() == 1;
        profiler_sample_status status = {0};
        for (unsigned i = 0; i < 2000; i++) {
            profiler_sampling_status(&status);
            if (status.wakes > 0)
                break;
            usleep(1000);
        }
        valid = valid && status.wakes > 0;
        profiler_sampling_stop();
        profiler_sampling_reset();
        profiler_sample_tick ticks[PROFILER_SAMPLE_TICKS];
        size_t count = profiler_sampling_drain(ticks, &status);
        valid = valid && count == 0 && sampling_lifecycle_zero_state() &&
                sampling_lifecycle_empty_registry();
    } else if (!strcmp(mode, "request-shutdown")) {
        valid = valid && profiler_sampling_start() == 1;
        unsigned before = atomic_load(&sampling_test_joined);
        profiler_sampling_request_shutdown();
        valid = valid && atomic_load(&sampling_test_joined) == before + 1 &&
                !current_control()->worker_live && sampling_lifecycle_empty_registry();
        profiler_sampling_request_init();
        valid = valid && sampling_lifecycle_zero_state();
    } else if (!strcmp(mode, "stack-equal") || !strcmp(mode, "page-zero")) {
        bool stack_equal = !strcmp(mode, "stack-equal");
        if (stack_equal)
            atomic_store(&sampling_lifecycle_minimum_override, SAMPLE_STACK);
        else
            atomic_store(&sampling_lifecycle_page_zero, true);
        int started = profiler_sampling_start();
        profiler_sample_status status;
        profiler_sampling_status(&status);
        if (stack_equal)
            valid = valid && started == 1 && status.error == 0;
        else
            valid = valid && started == 0 && status.error == 1 && status.system_error == EINVAL;
        profiler_sampling_stop();
        atomic_store(&sampling_lifecycle_minimum_override, 0);
        atomic_store(&sampling_lifecycle_page_zero, false);
        profiler_sampling_reset();
        valid = valid && sampling_lifecycle_zero_state();
    } else if (!strcmp(mode, "fork-registry")) {
        valid = valid && sampling_lifecycle_fork_contract();
    } else if (!strcmp(mode, "free-first")) {
        sample_control controls[SAMPLE_SLOTS];
        for (unsigned i = 0; i < SAMPLE_SLOTS; i++)
            control_construct(&controls[i]);
        lock_slots();
        for (unsigned i = 1; i < SAMPLE_SLOTS; i++)
            slots[i] = &controls[i];
        unlock_slots();
        valid = valid && profiler_sampling_start() == 1;
        profiler_sampling_stop();
        lock_slots();
        if (slots[0])
            valid = false;
        for (unsigned i = 1; i < SAMPLE_SLOTS; i++)
            slots[i] = NULL;
        unlock_slots();
        for (unsigned i = 0; i < SAMPLE_SLOTS; i++)
            control_destroy(&controls[i]);
        profiler_sampling_reset();
        valid = valid && sampling_lifecycle_zero_state();
    } else if (!strcmp(mode, "module-shutdown")) {
        valid = valid && profiler_sampling_start() == 1;
        unsigned before = atomic_load(&sampling_test_joined),
                 mutexes = sampling_lifecycle_live_resources(0),
                 conditions = sampling_lifecycle_live_resources(1);
        profiler_sampling_module_shutdown();
        valid = valid && sampling_lifecycle_live_resources(0) + 1 == mutexes &&
                sampling_lifecycle_live_resources(1) + 1 == conditions;
        valid = valid && atomic_load(&sampling_test_joined) == before + 1 &&
                sampling_lifecycle_empty_registry();
#ifndef ZTS
        /* Static NTS storage stays valid after a missed teardown. Record the
         * failed join obligation first, then retire it before reinitializing
         * the same storage to avoid a test-created race with a bad mutant. */
        if (atomic_load(&sampling_test_joined) != before + 1 &&
            sampling_lifecycle_live_resources(0) == mutexes &&
            sampling_lifecycle_live_resources(1) == conditions)
            stop_control(&nts_control, 1);
#endif
        int registration = sampling_test_register_real();
        valid = valid && registration == 1;
        if (registration == 1)
            profiler_sampling_request_init();
        valid = valid && sampling_lifecycle_zero_state();
    } else
        valid = false;
    valid = valid && sampling_lifecycle_contract_valid() && sampling_lifecycle_masks_retired();
    RETURN_BOOL(valid);
#else
    RETURN_FALSE;
#endif
}

#ifdef PROFILER_SAMPLING_PLATFORM
/* Instrumented entry/exit observes actual function identity; never call-site
 * addresses or Zend state. Clang/Mull retain these identities in all clones. */
enum sampling_test_domain {
    SAMPLE_CLOCK,
    SAMPLE_CONSTRUCT,
    SAMPLE_START,
    SAMPLE_STOP,
    SAMPLE_WORKER,
    SAMPLE_LOCK,
    SAMPLE_REGISTER,
    SAMPLE_DOMAIN_COUNT
};
static _Thread_local unsigned sampling_test_domains[SAMPLE_DOMAIN_COUNT];
static _Thread_local unsigned sampling_test_routing;
#define SAMPLE_NOINSTR __attribute__((no_instrument_function))
static SAMPLE_NOINSTR int sampling_test_domain_kind(void *function) {
    if (function == (void *)monotonic_ns)
        return SAMPLE_CLOCK;
    if (function == (void *)control_construct)
        return SAMPLE_CONSTRUCT;
    if (function == (void *)profiler_sampling_start)
        return SAMPLE_START;
    if (function == (void *)stop_locked)
        return SAMPLE_STOP;
    if (function == (void *)sample_worker)
        return SAMPLE_WORKER;
    if (function == (void *)lock_slots)
        return SAMPLE_LOCK;
    if (function == (void *)sampling_test_register_real)
        return SAMPLE_REGISTER;
    return -1;
}
SAMPLE_NOINSTR void __cyg_profile_func_enter(void *function, void *caller) {
    (void)caller;
    int role = sampling_test_domain_kind(function);
    if (role >= 0)
        sampling_test_domains[role]++;
}
SAMPLE_NOINSTR void __cyg_profile_func_exit(void *function, void *caller) {
    (void)caller;
    int role = sampling_test_domain_kind(function);
    if (role >= 0)
        sampling_test_domains[role]--;
}
#define SAMPLE_ROUTE(result, role, call, real)                                                     \
    do {                                                                                           \
        if (sampling_test_domains[role] && !sampling_test_routing) {                               \
            sampling_test_routing++;                                                               \
            result value = (call);                                                                 \
            sampling_test_routing--;                                                               \
            return value;                                                                          \
        }                                                                                          \
        return (real);                                                                             \
    } while (0)
extern int __real_clock_gettime(clockid_t, struct timespec *);
int sampling_test_route_clock(clockid_t clock, struct timespec *time) {
    SAMPLE_ROUTE(int, SAMPLE_CLOCK, sampling_test_clock(clock, time),
                 __real_clock_gettime(clock, time));
}
__attribute__((weak)) int __wrap_clock_gettime(clockid_t clock, struct timespec *time) {
    return sampling_test_route_clock(clock, time);
}
int sampling_test_route_mutex_init(pthread_mutex_t *m, const pthread_mutexattr_t *a) {
    SAMPLE_ROUTE(int, SAMPLE_CONSTRUCT, sampling_test_mutex_init(m, a),
                 __real_pthread_mutex_init(m, a));
}
int sampling_test_route_cond_init(pthread_cond_t *c, const pthread_condattr_t *a) {
    SAMPLE_ROUTE(int, SAMPLE_CONSTRUCT, sampling_test_cond_init(c, a),
                 __real_pthread_cond_init(c, a));
}
extern int __real_pthread_condattr_init(pthread_condattr_t *);
int __wrap_pthread_condattr_init(pthread_condattr_t *a) {
    SAMPLE_ROUTE(int, SAMPLE_CONSTRUCT, sampling_test_condattr_init(a),
                 __real_pthread_condattr_init(a));
}
extern int __real_pthread_condattr_setclock(pthread_condattr_t *, clockid_t);
int __wrap_pthread_condattr_setclock(pthread_condattr_t *a, clockid_t clock) {
    SAMPLE_ROUTE(int, SAMPLE_CONSTRUCT, sampling_test_setclock(a, clock),
                 __real_pthread_condattr_setclock(a, clock));
}
extern int __real_pthread_attr_init(pthread_attr_t *);
int __wrap_pthread_attr_init(pthread_attr_t *a) {
    SAMPLE_ROUTE(int, SAMPLE_START, sampling_test_attr_init(a), __real_pthread_attr_init(a));
}
extern long __real_sysconf(int);
long __wrap_sysconf(int name) {
    SAMPLE_ROUTE(long, SAMPLE_START, sampling_test_sysconf(name), __real_sysconf(name));
}
extern int __real_pthread_attr_setstacksize(pthread_attr_t *, size_t);
int __wrap_pthread_attr_setstacksize(pthread_attr_t *a, size_t size) {
    SAMPLE_ROUTE(int, SAMPLE_START, sampling_test_stack(a, size),
                 __real_pthread_attr_setstacksize(a, size));
}
extern int __real_pthread_attr_setguardsize(pthread_attr_t *, size_t);
int __wrap_pthread_attr_setguardsize(pthread_attr_t *a, size_t size) {
    SAMPLE_ROUTE(int, SAMPLE_START, sampling_test_guard(a, size),
                 __real_pthread_attr_setguardsize(a, size));
}
extern int __real_pthread_attr_setsigmask_np(pthread_attr_t *, const sigset_t *);
int __wrap_pthread_attr_setsigmask_np(pthread_attr_t *a, const sigset_t *mask) {
    SAMPLE_ROUTE(int, SAMPLE_START, sampling_test_mask(a, mask),
                 __real_pthread_attr_setsigmask_np(a, mask));
}
extern int __real_pthread_create(pthread_t *, const pthread_attr_t *, void *(*)(void *), void *);
int __wrap_pthread_create(pthread_t *thread, const pthread_attr_t *a, void *(*entry)(void *),
                          void *data) {
    SAMPLE_ROUTE(int, SAMPLE_START, sampling_test_create(thread, a, entry, data),
                 __real_pthread_create(thread, a, entry, data));
}
extern int __real_pthread_join(pthread_t, void **);
int __wrap_pthread_join(pthread_t thread, void **result) {
    SAMPLE_ROUTE(int, SAMPLE_STOP, sampling_test_join(thread, result),
                 __real_pthread_join(thread, result));
}
extern int __real_pthread_cond_timedwait(pthread_cond_t *, pthread_mutex_t *,
                                         const struct timespec *);
int __wrap_pthread_cond_timedwait(pthread_cond_t *c, pthread_mutex_t *m,
                                  const struct timespec *deadline) {
    SAMPLE_ROUTE(int, SAMPLE_WORKER, sampling_test_wait(c, m, deadline),
                 __real_pthread_cond_timedwait(c, m, deadline));
}
extern int __real_pthread_atfork(void (*)(void), void (*)(void), void (*)(void));
int __wrap_pthread_atfork(void (*prepare)(void), void (*parent)(void), void (*child)(void)) {
    SAMPLE_ROUTE(int, SAMPLE_REGISTER, sampling_test_atfork(prepare, parent, child),
                 __real_pthread_atfork(prepare, parent, child));
}
extern int __real_sched_yield(void);
int __wrap_sched_yield(void) {
    if ((sampling_test_domains[SAMPLE_LOCK] || sampling_test_domains[SAMPLE_STOP]) &&
        !sampling_test_routing) {
        sampling_test_routing++;
        int result = sampling_test_yield();
        sampling_test_routing--;
        return result;
    }
    return __real_sched_yield();
}
#ifdef ZTS
extern ts_rsrc_id __real_ts_allocate_id(ts_rsrc_id *, size_t, ts_allocate_ctor, ts_allocate_dtor);
/* Native TSRM allocation synchronously invokes the real control constructor.
 * Do not hold the unrelated-provider recursion guard across that callback. */
ts_rsrc_id __wrap_ts_allocate_id(ts_rsrc_id *id, size_t size, ts_allocate_ctor ctor,
                                 ts_allocate_dtor dtor) {
    if (sampling_test_domains[SAMPLE_REGISTER] && !sampling_test_routing)
        return sampling_test_allocate_id(id, size, ctor, dtor);
    return __real_ts_allocate_id(id, size, ctor, dtor);
}
#endif
extern uint64_t __real_profiler_sampling_closure_identity(zend_function *);
uint64_t __wrap_profiler_sampling_closure_identity(zend_function *function) {
    if (!sampling_test_routing) {
        sampling_test_routing++;
        uint64_t result = sampling_test_closure_identity(function);
        sampling_test_routing--;
        return result;
    }
    return __real_profiler_sampling_closure_identity(function);
}
static void sampling_test_join_yield_contract(void) {
    /* Only this initialized PHP owner may consult its actual control. Sampler
     * workers and unrelated pthreads must not enter TSRM or inspect PHP state. */
    if (!pthread_equal(pthread_self(), sampling_test_php_thread) || !registered)
        return;
    sample_control *control = current_control();
    if (!control || !control->mutex_ready)
        return;
    pthread_mutex_lock(&control->mutex);
    int error = control->join_error;
    pthread_mutex_unlock(&control->mutex);
    if (error != 0 && error != ESRCH && error != EINVAL && error != EDEADLK) {
        static const char diagnostic[] = "INVALID_NATIVE_JOIN_RESULT\n";
        (void)write(2, diagnostic, sizeof(diagnostic) - 1);
        _exit(74);
    }
}
#else
__attribute__((no_instrument_function)) void __cyg_profile_func_enter(void *function,
                                                                      void *caller) {
    (void)function;
    (void)caller;
}
__attribute__((no_instrument_function)) void __cyg_profile_func_exit(void *function, void *caller) {
    (void)function;
    (void)caller;
}
#endif

static ZEND_FUNCTION(profiler_test_sampling_fault) {
    char *name;
    size_t length;
    ZEND_PARSE_PARAMETERS_START(1, 1) Z_PARAM_STRING(name, length) ZEND_PARSE_PARAMETERS_END();
    int mode = sampling_test_mode(name);
    if (mode < 0 || strlen(name) != length) {
        zend_value_error("unknown sampling test fault");
        RETURN_THROWS();
    }
    atomic_store(&sampling_test_fault, mode);
    atomic_store(&sampling_test_consumed, 0);
    RETURN_NULL();
}
static ZEND_FUNCTION(profiler_test_sampling_start) {
    ZEND_PARSE_PARAMETERS_NONE();
    RETURN_LONG(profiler_sampling_start());
}
static ZEND_FUNCTION(profiler_test_sampling_stop) {
    ZEND_PARSE_PARAMETERS_NONE();
    profiler_sampling_stop();
    RETURN_NULL();
}
static ZEND_FUNCTION(profiler_test_sampling_status) {
    ZEND_PARSE_PARAMETERS_NONE();
    struct timespec before, after;
    bool reference = clock_gettime(CLOCK_MONOTONIC, &before) == 0;
    profiler_sample_status s;
    profiler_sampling_status(&s);
    reference = clock_gettime(CLOCK_MONOTONIC, &after) == 0 && reference;
    uint64_t lower = 0, upper = 0;
    if (reference && before.tv_sec >= 0 && after.tv_sec >= 0 && before.tv_nsec >= 0 &&
        before.tv_nsec < 1000000000 && after.tv_nsec >= 0 && after.tv_nsec < 1000000000 &&
        (uint64_t)before.tv_sec <= (UINT64_MAX - (uint64_t)before.tv_nsec) / 1000000000 &&
        (uint64_t)after.tv_sec <= (UINT64_MAX - (uint64_t)after.tv_nsec) / 1000000000) {
        lower = (uint64_t)before.tv_sec * 1000000000 + (uint64_t)before.tv_nsec;
        upper = (uint64_t)after.tv_sec * 1000000000 + (uint64_t)after.tv_nsec;
    } else
        reference = false;
    array_init(return_value);
    add_assoc_bool(return_value, "snapshot_in_reference_range",
                   reference && s.snapshot_ns >= lower && s.snapshot_ns <= upper);
    add_assoc_bool(return_value, "supported", profiler_sampling_supported());
#ifdef PROFILER_SAMPLING_PLATFORM
    add_assoc_bool(return_value, "expected_supported", true);
#else
    add_assoc_bool(return_value, "expected_supported", false);
#endif
#ifdef ZTS
    add_assoc_bool(return_value, "expected_zts", true);
#else
    add_assoc_bool(return_value, "expected_zts", false);
#endif
    add_assoc_long(return_value, "consumed", atomic_load(&sampling_test_consumed));
    add_assoc_long(return_value, "running", s.running);
    add_assoc_long(return_value, "error", s.error);
    add_assoc_long(return_value, "expected_eio", EIO);
    add_assoc_long(return_value, "expected_enomem", ENOMEM);
    add_assoc_long(return_value, "system_error", s.system_error);
    add_assoc_long(return_value, "termination", s.termination);
    int cleanup_kind = 0, cleanup_system = 0;
    profiler_sampling_cleanup_error(&cleanup_kind, &cleanup_system);
    add_assoc_long(return_value, "cleanup_error", cleanup_kind);
    add_assoc_long(return_value, "cleanup_system_error", cleanup_system);
    add_assoc_long(return_value, "snapshot_ns", s.snapshot_ns);
    add_assoc_long(return_value, "wakes", s.wakes);
    add_assoc_long(return_value, "pending", s.pending_ticks);
    add_assoc_long(return_value, "dropped", s.dropped_ticks);
    add_assoc_long(return_value, "joined", atomic_load(&sampling_test_joined));
    add_assoc_long(return_value, "created", atomic_load(&sampling_test_created));
#ifdef PROFILER_SAMPLING_TEST_TRANSPORTS
    add_assoc_long(return_value, "zero_wakes", atomic_load(&sampling_test_zero_wakes));
    add_assoc_long(return_value, "deadline_checks", atomic_load(&sampling_test_deadline_checks));
    add_assoc_bool(return_value, "same_deadline", atomic_load(&sampling_test_same_deadline));
#endif
    add_assoc_bool(return_value, "attributes_valid", atomic_load(&sampling_test_attributes_valid));
#ifdef PROFILER_SAMPLING_PLATFORM
    add_assoc_bool(return_value, "live_handle", current_control()->worker_live);
#else
    add_assoc_bool(return_value, "live_handle", false);
#endif
    sigset_t caller_mask;
    int mask_status = pthread_sigmask(SIG_SETMASK, NULL, &caller_mask);
    add_assoc_long(return_value, "caller_usr1",
                   mask_status ? -1 : sigismember(&caller_mask, SIGUSR1));
    add_assoc_long(return_value, "caller_usr2",
                   mask_status ? -1 : sigismember(&caller_mask, SIGUSR2));
}
static ZEND_FUNCTION(profiler_test_sampling_capacity) {
    ZEND_PARSE_PARAMETERS_NONE();
#ifdef PROFILER_SAMPLING_PLATFORM
    sample_control controls[SAMPLE_SLOTS];
    for (unsigned i = 0; i < SAMPLE_SLOTS; i++)
        control_construct(&controls[i]);
    bool valid = true;
    for (unsigned i = 0; i < SAMPLE_SLOTS; i++)
        if (!controls[i].condition_ready)
            valid = false;
    if (valid) {
        lock_slots();
        for (unsigned i = 0; i < SAMPLE_SLOTS; i++) {
            if (slots[i])
                valid = false;
            else if (i + 1 < SAMPLE_SLOTS)
                slots[i] = &controls[i];
        }
        unlock_slots();
        // The very last slot is genuinely available: admission must scan past
        // every occupied predecessor, then stop/join its actual new worker.
        if (valid && profiler_sampling_start() != 1)
            valid = false;
        profiler_sampling_stop();
        lock_slots();
        if (slots[SAMPLE_SLOTS - 1] != NULL)
            valid = false;
        unlock_slots();
        lock_slots();
        slots[SAMPLE_SLOTS - 1] = &controls[SAMPLE_SLOTS - 1];
        unlock_slots();
        if (valid && profiler_sampling_start() != 0)
            valid = false;
        profiler_sample_status status;
        profiler_sampling_status(&status);
        if (status.error != 2 || status.running)
            valid = false;
        lock_slots();
        for (unsigned i = 0; i < SAMPLE_SLOTS; i++)
            if (slots[i] == &controls[i])
                slots[i] = NULL;
        unlock_slots();
    }
    for (unsigned i = 0; i < SAMPLE_SLOTS; i++)
        control_destroy(&controls[i]);
#ifdef PROFILER_SAMPLING_TEST_TRANSPORTS
    if (!sampling_lifecycle_contract_valid())
        valid = false;
#endif
    RETURN_BOOL(valid);
#else
    RETURN_FALSE;
#endif
}
static ZEND_FUNCTION(profiler_test_sampling_counter_limit) {
    ZEND_PARSE_PARAMETERS_NONE();
#ifdef PROFILER_SAMPLING_PLATFORM
    sample_control *control = current_control();
    pthread_mutex_lock(&control->mutex);
    control->wakes = UINT64_MAX;
    pthread_mutex_unlock(&control->mutex);
    RETURN_NULL();
#else
    RETURN_NULL();
#endif
}
static ZEND_FUNCTION(profiler_test_sampling_dropped_limit) {
    ZEND_PARSE_PARAMETERS_NONE();
#ifdef PROFILER_SAMPLING_PLATFORM
    sample_control *control = current_control();
    pthread_mutex_lock(&control->mutex);
    control->dropped = UINT64_MAX;
    pthread_mutex_unlock(&control->mutex);
#endif
    RETURN_NULL();
}
/* Keep the PHP VM inside one genuine native call until the controller is
 * joined: with no owning Rust session the next safepoint correctly stops an
 * orphan controller. This envelope tests the C resource contract itself. */
static ZEND_FUNCTION(profiler_test_sampling_run) {
    char *mode;
    size_t length;
    ZEND_PARSE_PARAMETERS_START(1, 1) Z_PARAM_STRING(mode, length) ZEND_PARSE_PARAMETERS_END();
    int started = profiler_sampling_start();
    int duplicate = profiler_sampling_start();
    bool ready = true;
#ifdef PROFILER_SAMPLING_PLATFORM
    if (!strcmp(mode, "backlog") || !strcmp(mode, "dropped-counter")) {
        ready = false;
        sample_control *control = current_control();
        for (unsigned attempt = 0; attempt < 1000; attempt++) {
            pthread_mutex_lock(&control->mutex);
            ready = control->wakes >= 66 && control->dropped > 0;
            bool failed = control->error != 0;
            pthread_mutex_unlock(&control->mutex);
            if (ready || failed)
                break;
            usleep(5000);
        }
    } else {
        if (!strcmp(mode, "real-spurious")) {
            sample_control *control = current_control();
            unsigned seen = 0;
            for (unsigned wake = 0; wake < 2; wake++) {
                unsigned attempt = 0;
                while (atomic_load(&sampling_test_wait_entries) <= seen && attempt++ < 1000)
                    usleep(1000);
                if (atomic_load(&sampling_test_wait_entries) <= seen) {
                    ready = false;
                    break;
                }
                seen = atomic_load(&sampling_test_wait_entries);
                pthread_mutex_lock(&control->mutex);
                pthread_cond_signal(&control->condition);
                pthread_mutex_unlock(&control->mutex);
            }
        }
        usleep(50000);
    }
#endif
    if (!strcmp(mode, "prior-clock-join"))
        atomic_store(&sampling_test_fault, 11);
    profiler_sampling_stop();
    array_init(return_value);
    add_assoc_long(return_value, "started", started);
    add_assoc_long(return_value, "duplicate", duplicate);
    add_assoc_bool(return_value, "backlog_ready", ready);
}
static ZEND_FUNCTION(profiler_test_sampling_fork) {
    ZEND_PARSE_PARAMETERS_NONE();
#ifdef PROFILER_SAMPLING_PLATFORM
    int requested_fault = atomic_load(&sampling_test_fault);
    if (profiler_sampling_start() != 1)
        RETURN_FALSE;
    /* libc fork executes the real registered atfork callbacks. No PHP callback
     * or allocation occurs between fork preparation and these scalar checks. */
    pid_t child = fork();
    if (child == 0) {
        /* Validate genuine preparation before touching an inherited mutex. A
         * missing atfork handler must fail an assertion, not strand the child
         * trying to join a thread that does not exist in this process. */
        sample_control *control = current_control();
        if (control->worker_live || atomic_load(&control->running) || control->termination != 2)
            _exit(37);
        for (unsigned i = 0; i < SAMPLE_SLOTS; i++)
            if (slots[i])
                _exit(38);
        profiler_sample_status snapshot;
        profiler_sampling_status(&snapshot);
        bool preserved =
            snapshot.termination == 2 && snapshot.error == (requested_fault == 16   ? 3
                                                            : requested_fault == 17 ? 4
                                                                                    : 0);
        profiler_sampling_reset();
        int restarted = profiler_sampling_start();
        if (restarted == 1)
            profiler_sampling_stop();
        _exit(preserved && restarted == 3 ? 0 : 39);
    }
    if (child < 0) {
        profiler_sampling_stop();
        RETURN_FALSE;
    }
    int status = 0;
    pid_t waited = waitpid(child, &status, 0);
    profiler_sample_status snapshot;
    profiler_sampling_status(&snapshot);
    bool preserved = snapshot.termination == 2 && snapshot.error == (requested_fault == 16   ? 3
                                                                     : requested_fault == 17 ? 4
                                                                                             : 0);
    profiler_sampling_reset();
    int restarted = profiler_sampling_start();
    bool valid = waited == child && WIFEXITED(status) && WEXITSTATUS(status) == 0 && preserved &&
                 restarted == 3;
    profiler_sampling_stop();
    RETURN_BOOL(valid);
#else
    RETURN_FALSE;
#endif
}
static ZEND_FUNCTION(profiler_test_sampling_request_restart) {
    ZEND_PARSE_PARAMETERS_NONE();
#ifdef PROFILER_SAMPLING_PLATFORM
    if (profiler_sampling_start() != 1)
        RETURN_FALSE;
    profiler_sampling_request_init();
    profiler_sample_status status;
    profiler_sampling_status(&status);
    bool valid = !current_control()->worker_live && status.running == 0 && status.error == 0 &&
                 status.termination == 0 && atomic_load(&sampling_test_joined) == 1;
    valid = valid && profiler_sampling_start() == 1;
    profiler_sampling_stop();
    valid = valid && atomic_load(&sampling_test_joined) == 2;
    profiler_sampling_request_shutdown();
    int outside = profiler_sampling_start();
    if (outside != 0) {
        valid = false;
        profiler_sampling_stop();
    }
    profiler_sampling_request_init();
    valid = valid && profiler_sampling_start() == 1;
    profiler_sampling_stop();
    RETURN_BOOL(valid && atomic_load(&sampling_test_joined) == 3);
#else
    RETURN_FALSE;
#endif
}
/* Deterministic ABI probe, separate from ordinary statistical workloads. Only
 * real producer ticks and the currently executing Zend frame are serviced. */
static ZEND_FUNCTION(profiler_test_sampling_service) {
    ZEND_PARSE_PARAMETERS_NONE();
#ifdef PROFILER_SAMPLING_PLATFORM
    sample_control *control = current_control();
    pthread_mutex_lock(&control->mutex);
    uint64_t initial_wakes = control->wakes;
    pthread_mutex_unlock(&control->mutex);
    for (unsigned attempt = 0; attempt < 1000; attempt++) {
        pthread_mutex_lock(&control->mutex);
        bool ready = control->head != control->tail && control->wakes > initial_wakes;
        bool failed = control->error != 0 || !control->worker_live;
        if (ready && !failed)
            sampling_frames_note_ready();
        pthread_mutex_unlock(&control->mutex);
        if (failed)
            RETURN_FALSE;
        if (ready) {
            sampling_test_in_service = true;
            sampling_test_service_clocks = 0;
            sample_interrupt(EG(current_execute_data));
            sampling_test_in_service = false;
            RETURN_TRUE;
        }
        usleep(5000);
    }
    RETURN_FALSE;
#else
    RETURN_FALSE;
#endif
}
static ZEND_FUNCTION(profiler_test_sampling_fault_consumed) {
    ZEND_PARSE_PARAMETERS_NONE();
    RETURN_LONG(atomic_load(&sampling_test_consumed));
}
#ifdef PROFILER_SAMPLING_PLATFORM
static void *sampling_test_lock_holder(void *unused) {
    (void)unused;
    lock_slots();
    atomic_store(&sampling_test_lock_ready, true);
    for (unsigned i = 0; i < 1000 && !atomic_load(&sampling_test_yield_seen); i++)
        usleep(1000);
    unlock_slots();
    return NULL;
}
#endif
static ZEND_FUNCTION(profiler_test_sampling_lock_contention) {
    ZEND_PARSE_PARAMETERS_NONE();
#ifdef PROFILER_SAMPLING_PLATFORM
    atomic_store(&sampling_test_yield_seen, false);
    atomic_store(&sampling_test_lock_ready, false);
    atomic_store(&sampling_test_contention_armed, true);
    pthread_t holder;
    if (pthread_create(&holder, NULL, sampling_test_lock_holder, NULL) != 0)
        RETURN_FALSE;
    for (unsigned i = 0; i < 1000 && !atomic_load(&sampling_test_lock_ready); i++)
        usleep(1000);
    lock_slots();
    unlock_slots();
    int joined = pthread_join(holder, NULL);
    atomic_store(&sampling_test_contention_armed, false);
    RETURN_BOOL(joined == 0 && atomic_load(&sampling_test_yield_seen));
#else
    RETURN_FALSE;
#endif
}
static ZEND_FUNCTION(profiler_test_sampling_empty_drain) {
    ZEND_PARSE_PARAMETERS_NONE();
    profiler_sample_tick ticks[PROFILER_SAMPLE_TICKS];
    profiler_sample_status status;
    size_t count = profiler_sampling_drain(ticks, &status);
    RETURN_BOOL(count == 0);
}
static ZEND_FUNCTION(profiler_test_sampling_register_again) {
    ZEND_PARSE_PARAMETERS_NONE();
#ifdef PROFILER_SAMPLING_PLATFORM
    void (*old_interrupt)(zend_execute_data *) = zend_interrupt_function;
    void (*old_previous)(zend_execute_data *) = previous_interrupt;
    bool valid = sampling_test_register_real() == 1 && sampling_test_register_real() == 1 &&
                 zend_interrupt_function == old_interrupt && previous_interrupt == old_previous &&
                 current_control()->request_active;
    RETURN_BOOL(valid);
#else
    RETURN_BOOL(sampling_test_register_real() == 1);
#endif
}
#ifdef PROFILER_SAMPLING_PLATFORM
static unsigned sampling_test_late_calls;
static void sampling_test_previous_handler(zend_execute_data *frame) {
    if (frame == EG(current_execute_data))
        sampling_test_late_calls++;
}
#endif
static ZEND_FUNCTION(profiler_test_sampling_lifecycle) {
    ZEND_PARSE_PARAMETERS_NONE();
#ifdef PROFILER_SAMPLING_PLATFORM
    void (*saved)(zend_execute_data *) = zend_interrupt_function;
    void (*original_previous)(zend_execute_data *) = previous_interrupt;
    profiler_sampling_module_shutdown();
    bool valid = !registered && zend_interrupt_function == original_previous;
    // Both stale-hook cases use the genuine currently executing Zend frame.
    previous_interrupt = NULL;
    saved(EG(current_execute_data));
    previous_interrupt = sampling_test_previous_handler;
    sampling_test_late_calls = 0;
    saved(EG(current_execute_data));
    valid = valid && sampling_test_late_calls == 1;
    previous_interrupt = original_previous;
    profiler_sampling_module_shutdown(); // Actual idempotent repeated shutdown.
    valid = valid && sampling_test_register_real() == 1;
    profiler_sampling_request_init();
    // A later genuine interrupt owner must not be overwritten on our shutdown.
    zend_interrupt_function = sampling_test_previous_handler;
    profiler_sampling_module_shutdown();
    valid = valid && zend_interrupt_function == sampling_test_previous_handler;
    zend_interrupt_function = original_previous;
    valid = valid && sampling_test_register_real() == 1;
    profiler_sampling_request_init();
    RETURN_BOOL(valid && registered && current_control()->request_active &&
                zend_interrupt_function == saved && previous_interrupt == original_previous);
#else
    RETURN_TRUE;
#endif
}
/* Pure decoder boundary: the frame belongs to a genuine VM-created Generator.
 * No fields are written and no observation/profile is submitted. */
static ZEND_FUNCTION(profiler_test_sampling_generator_marker) {
    zval *value;
    ZEND_PARSE_PARAMETERS_START(1, 1)
    Z_PARAM_OBJECT_OF_CLASS(value, zend_ce_generator)
    ZEND_PARSE_PARAMETERS_END();
    array_init(return_value);
#ifdef PROFILER_SAMPLING_PLATFORM
    const zend_generator *generator = (const zend_generator *)Z_OBJ_P(value);
    zend_function *functions[PROFILER_SAMPLE_FRAMES];
    int truncated = 0;
    size_t count = stack_functions(&generator->execute_fake, functions, &truncated);
    add_assoc_long(return_value, "count", count);
    add_assoc_bool(return_value, "truncated", truncated);
    add_assoc_bool(return_value, "closed", generator->execute_data == NULL);
#endif
}
/* Exercise an engine-initialized but never activated marker. All storage and
 * initialization come from Zend's real Generator create_object handler. */
static ZEND_FUNCTION(profiler_test_sampling_blank_generator) {
    ZEND_PARSE_PARAMETERS_NONE();
#ifdef PROFILER_SAMPLING_PLATFORM
    zval object;
    if (object_init_ex(&object, zend_ce_generator) != SUCCESS)
        RETURN_FALSE;
    const zend_generator *generator = (const zend_generator *)Z_OBJ(object);
    zend_function *functions[PROFILER_SAMPLE_FRAMES];
    int truncated = 0;
    size_t count = stack_functions(&generator->execute_fake, functions, &truncated);
    bool valid = count == 0 && truncated == 1;
    zval_ptr_dtor(&object);
    RETURN_BOOL(valid);
#else
    RETURN_TRUE;
#endif
}
/* Private primitive-clock and genuine-ring contracts. Controlled timespec inputs
 * are unit data, never reported as observed performance samples. */
static ZEND_FUNCTION(profiler_test_sampling_engine_probe) {
    char *mode;
    size_t length;
    ZEND_PARSE_PARAMETERS_START(1, 1) Z_PARAM_STRING(mode, length) ZEND_PARSE_PARAMETERS_END();
#ifdef PROFILER_SAMPLING_PLATFORM
    bool valid = true;
    if (!strcmp(mode, "conversion")) {
        struct {
            time_t seconds;
            long fraction;
            int error;
            uint64_t value;
        } cases[] = {{0, 0, 0, 0},
                     {0, 1, 0, 1},
                     {1, 0, 0, UINT64_C(1000000000)},
                     {18446744073LL, 709551615, 0, UINT64_MAX},
                     {18446744073LL, 709551616, 4, 0},
                     {-1, 0, 4, 0},
                     {1, -1, 4, 0},
                     {1, 1000000000, 4, 0}};
        sampling_engine_clock_override = true;
        sampling_engine_clock_fail = false;
        for (unsigned i = 0; i < sizeof(cases) / sizeof(*cases); i++) {
            sampling_engine_clock_value = (struct timespec){cases[i].seconds, cases[i].fraction};
            sampling_engine_clock_errno = EDOM;
            uint64_t observed = 0;
            int error = monotonic_ns(&observed);
            if (error != cases[i].error || (!error && observed != cases[i].value))
                valid = false;
        }
        sampling_engine_clock_override = false;
    } else if (!strcmp(mode, "snapshot-error")) {
        profiler_sampling_reset();
        sampling_engine_clock_override = true;
        sampling_engine_clock_fail = true;
        sampling_engine_clock_errno = EIO;
        profiler_sample_status status;
        profiler_sampling_status(&status);
        sampling_engine_clock_override = false;
        sampling_engine_clock_fail = false;
        valid = status.snapshot_ns == 0 && status.error == 3 && status.system_error == EIO &&
                status.termination == 3 && status.running == 0;
        profiler_sampling_reset();
    } else if (!strcmp(mode, "deadline-exact")) {
        profiler_sampling_reset();
        atomic_store(&sampling_engine_deadline_seen, 0);
        atomic_store(&sampling_engine_deadline_override, true);
        int started = profiler_sampling_start();
        sample_control *control = current_control();
        for (unsigned i = 0; i < 5000 && atomic_load(&control->running); i++)
            usleep(1000);
        profiler_sampling_stop();
        atomic_store(&sampling_engine_deadline_override, false);
        profiler_sample_status status;
        profiler_sampling_status(&status);
        uint64_t observed =
            (uint64_t)sampling_engine_observed_deadline.tv_sec * UINT64_C(1000000000) +
            (uint64_t)sampling_engine_observed_deadline.tv_nsec;
        valid = started == 1 && atomic_load(&sampling_engine_deadline_seen) == 1 &&
                observed == UINT64_MAX && status.error == 5 && status.system_error == EINVAL &&
                !status.running && !control->worker_live;
        profiler_sampling_reset();
    } else if (!strcmp(mode, "ring-wrap")) {
        profiler_sampling_reset();
        profiler_sample_status initial;
        profiler_sampling_status(&initial);
        valid = initial.pending_ticks == 0 && initial.wakes == 0 && initial.dropped_ticks == 0;
        if (profiler_sampling_start() != 1)
            valid = false;
        profiler_sample_status full = {0};
        for (unsigned i = 0; i < 5000; i++) {
            profiler_sampling_status(&full);
            if (full.error || full.dropped_ticks > 0)
                break;
            usleep(1000);
        }
        profiler_sampling_stop();
        profiler_sampling_status(&full);
        valid = valid && full.error == 0 && full.dropped_ticks > 0 &&
                full.wakes - full.dropped_ticks == PROFILER_SAMPLE_TICKS - 1 &&
                full.pending_ticks == PROFILER_SAMPLE_TICKS - 1;
        profiler_sample_tick ticks[PROFILER_SAMPLE_TICKS];
        profiler_sample_status drained;
        size_t count = profiler_sampling_drain(ticks, &drained);
        valid = valid && count == PROFILER_SAMPLE_TICKS - 1 && drained.pending_ticks == 0;
        uint64_t last = 0;
        for (size_t i = 0; i < count && i < PROFILER_SAMPLE_TICKS; i++) {
            if (ticks[i].due_ns > ticks[i].wake_ns || (i && ticks[i].wake_ns < last))
                valid = false;
            last = ticks[i].wake_ns;
        }
        uint64_t old_wakes = full.wakes, old_drops = full.dropped_ticks;
        if (profiler_sampling_start() != 1)
            valid = false;
        profiler_sample_status wrapped = {0};
        for (unsigned i = 0; i < 5000; i++) {
            profiler_sampling_status(&wrapped);
            if (wrapped.error || wrapped.wakes > old_wakes)
                break;
            usleep(1000);
        }
        profiler_sampling_stop();
        profiler_sampling_status(&wrapped);
        uint64_t accepted = wrapped.wakes - old_wakes - (wrapped.dropped_ticks - old_drops);
        count = profiler_sampling_drain(ticks, &drained);
        valid = valid && accepted > 0 && accepted < PROFILER_SAMPLE_TICKS && count == accepted &&
                drained.pending_ticks == 0;
        for (size_t i = 0; i < count && i < PROFILER_SAMPLE_TICKS; i++) {
            if (ticks[i].due_ns > ticks[i].wake_ns || ticks[i].wake_ns < last)
                valid = false;
            last = ticks[i].wake_ns;
        }
        profiler_sampling_reset();
    } else if (!strcmp(mode, "status-mutex")) {
#ifdef SAMPLING_LIFECYCLE_OWNERSHIP_AVAILABLE
        profiler_sampling_reset();
        sampling_engine_snapshot_mutex = &current_control()->mutex;
        sampling_engine_snapshot_checks = 0;
        sampling_engine_snapshot_owned = true;
        profiler_sample_status status;
        profiler_sampling_status(&status);
        sampling_engine_snapshot_mutex = NULL;
        valid = sampling_engine_snapshot_checks == 1 && sampling_engine_snapshot_owned &&
                status.error == 0 && sampling_lifecycle_mutex_depth() == 0 &&
                sampling_lifecycle_contract_valid();
#else
        valid = false; /* The combined integrator must supply the genuine observer. */
#endif
    } else if (!strcmp(mode, "worker-mutex")) {
#ifdef SAMPLING_LIFECYCLE_OWNERSHIP_AVAILABLE
        profiler_sampling_reset();
        unsigned before = atomic_load(&sampling_test_wait_entries);
        int started = profiler_sampling_start();
        for (unsigned i = 0; i < 5000 && atomic_load(&sampling_test_wait_entries) == before &&
                             atomic_load(&current_control()->running);
             i++)
            usleep(1000);
        profiler_sampling_stop();
        profiler_sample_status status;
        profiler_sampling_status(&status);
        valid = started == 1 && atomic_load(&sampling_test_wait_entries) > before &&
                status.error == 0 && !status.running && !current_control()->worker_live &&
                sampling_lifecycle_mutex_depth() == 0 && sampling_lifecycle_contract_valid();
        profiler_sampling_reset();
#else
        valid = false;
#endif
    } else {
        zend_value_error("unknown engine boundary probe");
        RETURN_THROWS();
    }
    RETURN_BOOL(valid);
#else
    RETURN_TRUE;
#endif
}

/* Target-only additions for ZTS survivors189/190. Integrate into the private
 * sampling-unit TU after production sampling.c inclusion, then register both
 * using the existing sampling_test_bool arginfo. No main edits yet. */
static ZEND_FUNCTION(profiler_test_sampling_engine_unready_drain) {
    ZEND_PARSE_PARAMETERS_NONE();
#ifdef PROFILER_SAMPLING_PLATFORM
    sample_control *control = current_control();
    if (control->mutex_ready || control->system_error != ENOMEM)
        RETURN_FALSE;
    profiler_sample_status status;
    memset(&status, 0xa5, sizeof(status));
    profiler_sample_tick ticks[PROFILER_SAMPLE_TICKS];
    size_t count = profiler_sampling_drain(ticks, &status);
    bool valid = count == 0 && status.error == 1 && status.system_error == ENOMEM &&
                 status.wakes == 0 && status.dropped_ticks == 0 && status.pending_ticks == 0 &&
                 status.snapshot_ns == 0 && status.worker_stack_bytes == 0 &&
                 status.worker_guard_bytes == 0 && status.control_bytes == 0 &&
                 status.running == 0 && status.termination == 0;
    RETURN_BOOL(valid);
#else
    RETURN_TRUE;
#endif
}
static ZEND_FUNCTION(profiler_test_sampling_engine_drain_mutex) {
    ZEND_PARSE_PARAMETERS_NONE();
#if defined(PROFILER_SAMPLING_PLATFORM) && defined(SAMPLING_LIFECYCLE_OWNERSHIP_AVAILABLE)
    profiler_sampling_reset();
    sampling_engine_snapshot_mutex = &current_control()->mutex;
    sampling_engine_snapshot_checks = 0;
    sampling_engine_snapshot_owned = true;
    profiler_sample_status status;
    profiler_sample_tick ticks[PROFILER_SAMPLE_TICKS];
    size_t count = profiler_sampling_drain(ticks, &status);
    sampling_engine_snapshot_mutex = NULL;
    bool valid = count == 0 && status.error == 0 && status.pending_ticks == 0 &&
                 sampling_engine_snapshot_checks == 1 && sampling_engine_snapshot_owned &&
                 sampling_lifecycle_mutex_depth() == 0 && sampling_lifecycle_contract_valid();
    RETURN_BOOL(valid);
#else
    RETURN_FALSE; /* Missing mandatory combined observer must not become a pass. */
#endif
}

/* A genuinely initialized controller with a real worker that exits through a
 * documented clock failure still owns a joinable native handle. Its destructor
 * must join before retirement, even though no worker is waiting anymore. */
static ZEND_FUNCTION(profiler_test_sampling_engine_destroy) {
    ZEND_PARSE_PARAMETERS_NONE();
#if defined(PROFILER_SAMPLING_PLATFORM) && defined(SAMPLING_LIFECYCLE_OWNERSHIP_AVAILABLE)
    unsigned mutex_before = sampling_lifecycle_live_resources(0),
             cond_before = sampling_lifecycle_live_resources(1);
    sample_control extra;
    control_construct(&extra);
    if (!extra.condition_ready) {
        control_destroy(&extra);
        RETURN_FALSE;
    }
    pthread_attr_t attributes;
    int status = pthread_attr_init(&attributes);
    if (status) {
        control_destroy(&extra);
        RETURN_FALSE;
    }
    status = sampling_test_stack(&attributes, SAMPLE_STACK);
    long page = sysconf(_SC_PAGESIZE);
    if (!status)
        status = sampling_test_guard(&attributes, (size_t)page);
    sigset_t all;
    sigfillset(&all);
    if (!status)
        status = sampling_test_mask(&attributes, &all);
    extra.interrupt = &EG(vm_interrupt);
    extra.guard_bytes = (size_t)page;
    atomic_store(&extra.running, true);
    atomic_store(&extra.interested, true);
    atomic_store(&sampling_test_fault, 12);
    if (!status)
        status = sampling_test_create(&extra.worker, &attributes, sample_worker, &extra);
    pthread_attr_destroy(&attributes);
    extra.worker_live = status == 0;
    if (status) {
        atomic_store(&extra.running, false);
        control_destroy(&extra);
        RETURN_FALSE;
    }
    lock_slots();
    bool empty = slots[SAMPLE_SLOTS - 1] == NULL;
    if (empty)
        slots[SAMPLE_SLOTS - 1] = &extra;
    unlock_slots();
    for (unsigned i = 0; i < 5000 && atomic_load(&extra.running); i++)
        usleep(1000);
    /* Worker publishes running=false before its final unlock. Acquire/release
     * this real mutex to prove that final control access has completed, so a
     * missing-join mutant cannot force undefined destruction of a live wait. */
    pthread_mutex_lock(&extra.mutex);
    int kind = extra.error, native = extra.system_error;
    pthread_mutex_unlock(&extra.mutex);
    unsigned joined = atomic_load(&sampling_test_joined);
    control_destroy(&extra);
    bool did_join = atomic_load(&sampling_test_joined) == joined + 1;
    bool valid = empty && kind == 3 && native == EIO && did_join &&
                 sampling_lifecycle_live_resources(0) == mutex_before &&
                 sampling_lifecycle_live_resources(1) == cond_before;
    /* Retire a violating mutant's remaining REAL resources before this stack
     * object's lifetime ends. The failed oracle above is never overwritten. */
    if (!did_join) {
        sampling_test_join(extra.worker, NULL);
        extra.worker_live = false;
        atomic_store(&extra.interested, false);
        lock_slots();
        for (unsigned i = 0; i < SAMPLE_SLOTS; i++)
            if (slots[i] == &extra)
                slots[i] = NULL;
        unlock_slots();
    }
    if (sampling_lifecycle_live_resources(1) > cond_before)
        pthread_cond_destroy(&extra.condition);
    if (sampling_lifecycle_live_resources(0) > mutex_before)
        pthread_mutex_destroy(&extra.mutex);
    valid = valid && sampling_lifecycle_contract_valid() && sampling_lifecycle_mutex_depth() == 0;
    RETURN_BOOL(valid);
#else
    RETURN_FALSE;
#endif
}

// clang-format off: preserve Zend argument declaration macro layout.
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(sampling_test_void, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()
// clang-format on
// clang-format off: preserve Zend argument declaration macro layout.
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(sampling_test_fault_info, 0, 1, IS_VOID, 0)
ZEND_ARG_TYPE_INFO(0, name, IS_STRING, 0) ZEND_END_ARG_INFO()
    // clang-format on
    // clang-format off: preserve Zend argument declaration macro layout.
    ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(sampling_test_int, 0, 0, IS_LONG, 0) ZEND_END_ARG_INFO()
        ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(sampling_test_array, 0, 0, IS_ARRAY, 0)
            ZEND_END_ARG_INFO()
    // clang-format on
    // clang-format off: preserve Zend argument declaration macro layout.
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(sampling_test_run_info, 0, 1, IS_ARRAY, 0)
                ZEND_ARG_TYPE_INFO(0, mode, IS_STRING, 0) ZEND_END_ARG_INFO()
    // clang-format on

    // clang-format off: preserve Zend argument declaration macro layout.
    ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(sampling_test_generator_info, 0, 1, IS_ARRAY, 0)
        ZEND_ARG_OBJ_INFO(0, generator, Generator, 0) ZEND_END_ARG_INFO()
    // clang-format on
    // clang-format off: preserve Zend argument declaration macro layout.
            ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(sampling_test_bool, 0, 0, _IS_BOOL, 0)
                ZEND_END_ARG_INFO()
    // clang-format on
    // clang-format off: preserve Zend argument declaration macro layout.
                    ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(sampling_engine_probe_info, 0, 1,
                                                            _IS_BOOL, 0)
                        ZEND_ARG_TYPE_INFO(0, mode, IS_STRING, 0) ZEND_END_ARG_INFO()
// clang-format on static const
    zend_function_entry sampling_test_functions[] = {
        ZEND_FE(profiler_test_sampling_frame_contract, sampling_frames_info) ZEND_FE(
            profiler_test_sampling_backlog_service,
            sampling_test_bool) ZEND_FE(profiler_test_sampling_lifecycle_contract,
                                        sampling_engine_probe_info)
            ZEND_FE(profiler_test_sampling_engine_unready_drain, sampling_test_bool) ZEND_FE(
                profiler_test_sampling_engine_drain_mutex,
                sampling_test_bool) ZEND_FE(profiler_test_sampling_engine_destroy,
                                            sampling_test_bool)
                ZEND_FE(profiler_test_sampling_engine_probe, sampling_engine_probe_info) ZEND_FE(
                    profiler_test_sampling_fault,
                    sampling_test_fault_info) ZEND_FE(profiler_test_sampling_start,
                                                      sampling_test_int)
                    ZEND_FE(profiler_test_sampling_stop, sampling_test_void) ZEND_FE(
                        profiler_test_sampling_status,
                        sampling_test_array) ZEND_FE(profiler_test_sampling_capacity,
                                                     sampling_test_bool)
                        ZEND_FE(profiler_test_sampling_counter_limit, sampling_test_void) ZEND_FE(
                            profiler_test_sampling_dropped_limit,
                            sampling_test_void) ZEND_FE(profiler_test_sampling_run,
                                                        sampling_test_run_info)
                            ZEND_FE(profiler_test_sampling_fork, sampling_test_bool) ZEND_FE(
                                profiler_test_sampling_service,
                                sampling_test_bool) ZEND_FE(profiler_test_sampling_request_restart,
                                                            sampling_test_bool)
                                ZEND_FE(profiler_test_sampling_fault_consumed, sampling_test_int)
                                    ZEND_FE(profiler_test_sampling_lock_contention,
                                            sampling_test_bool)
                                        ZEND_FE(profiler_test_sampling_empty_drain,
                                                sampling_test_bool)
                                            ZEND_FE(profiler_test_sampling_register_again,
                                                    sampling_test_bool)
                                                ZEND_FE(profiler_test_sampling_lifecycle,
                                                        sampling_test_bool)
                                                    ZEND_FE(profiler_test_sampling_generator_marker,
                                                            sampling_test_generator_info)
                                                        ZEND_FE(
                                                            profiler_test_sampling_blank_generator,
                                                            sampling_test_bool) ZEND_FE_END};
#ifdef PROFILER_GUARD_UNIT_PRESENT
extern void profiler_test_guard_unregister(void);
#elif defined(__linux__)
extern void profiler_test_boundary_unregister(void) __attribute__((weak));
#endif
#ifdef __linux__
extern int profiler_test_boundary_registration_status(void) __attribute__((weak));
#endif
static void sampling_test_rollback_helpers(void) {
#ifdef PROFILER_GUARD_UNIT_PRESENT
    profiler_test_guard_unregister();
#elif defined(__linux__)
    if (profiler_test_boundary_unregister)
        profiler_test_boundary_unregister();
#endif
}
int profiler_sampling_register(void) {
    sampling_test_php_thread = pthread_self();
    atomic_store(&sampling_test_fault, sampling_test_mode(getenv("PROFILER_SAMPLING_FAULT")));
#ifdef __linux__
    if (profiler_test_boundary_registration_status &&
        profiler_test_boundary_registration_status() != SUCCESS) {
        sampling_test_rollback_helpers();
        return 0;
    }
#endif
    int result = sampling_test_register_real();
    if (!result) {
        sampling_test_rollback_helpers();
        return 0;
    }
    if (zend_register_functions(NULL, sampling_test_functions, NULL, MODULE_PERSISTENT) !=
        SUCCESS) {
        sampling_test_rollback_helpers();
        profiler_sampling_module_shutdown();
        return 0;
    }
    return result;
}
