#ifndef _GNU_SOURCE
#define _GNU_SOURCE
#endif
#include "sampling.h"
#if defined(__linux__)
#include <features.h>
#endif
#if defined(__linux__) && defined(__GLIBC__)
#if __GLIBC_PREREQ(2, 32)
#define PROFILER_SAMPLING_PLATFORM 1
#endif
#endif
#ifdef PROFILER_SAMPLING_PLATFORM
#include <php.h>
#include <Zend/zend_atomic.h>
#include <Zend/zend_generators.h>
#include <pthread.h>
#include <signal.h>
#include <stdatomic.h>
#include <stdbool.h>
#include <errno.h>
#include <limits.h>
#include <sched.h>
#include <time.h>
#include <unistd.h>
#include <string.h>
#define SAMPLE_SLOTS 64
#define SAMPLE_STACK (256 * 1024)
_Static_assert(ATOMIC_BOOL_LOCK_FREE == 2, "sampler retirement must be lock-free");
extern uint64_t profiler_sampling_closure_identity(zend_function *function);
typedef struct sample_control {
    pthread_mutex_t mutex;
    pthread_cond_t condition;
    pthread_t worker;
    bool mutex_ready, condition_ready, worker_live, request_active;
    _Atomic bool running, interested;
    zend_atomic_bool *interrupt;
    size_t guard_bytes;
    unsigned head, tail;
    profiler_sample_tick ticks[PROFILER_SAMPLE_TICKS];
    uint64_t wakes, dropped;
    int termination, error, system_error, join_error;
} sample_control;
static sample_control *slots[SAMPLE_SLOTS];
static atomic_flag slots_lock = ATOMIC_FLAG_INIT;
static bool registered;
/* Rust TLS may keep this DSO mapped across PHP module cycles. Glibc removes
 * these handlers only at actual DSO unload; never register them twice. */
static bool atfork_registered;
static void (*previous_interrupt)(zend_execute_data *);
#ifdef ZTS
static ts_rsrc_id control_id;
#else
static sample_control nts_control;
#endif
static void lock_slots(void) {
    while (atomic_flag_test_and_set_explicit(&slots_lock, memory_order_acquire))
        sched_yield();
}
static void unlock_slots(void) { atomic_flag_clear_explicit(&slots_lock, memory_order_release); }
static void failure(sample_control *control, int kind, int system_error) {
    if (!control->error) {
        control->error = kind;
        control->system_error = system_error;
    }
    if (control->termination != 2)
        control->termination = 3;
}
static int monotonic_ns(uint64_t *out) {
    struct timespec time;
    if (clock_gettime(CLOCK_MONOTONIC, &time) != 0)
        return 3;
    if (time.tv_sec < 0 || time.tv_nsec < 0 || time.tv_nsec >= 1000000000)
        return 4;
    uint64_t seconds = (uint64_t)time.tv_sec, fraction = (uint64_t)time.tv_nsec;
    if (seconds > (UINT64_MAX - fraction) / UINT64_C(1000000000))
        return 4;
    *out = seconds * UINT64_C(1000000000) + fraction;
    return 0;
}
static void *sample_worker(void *argument) {
    sample_control *control = argument;
    pthread_mutex_lock(&control->mutex);
    while (atomic_load_explicit(&control->running, memory_order_acquire)) {
        uint64_t now;
        int error = monotonic_ns(&now);
        if (error) {
            failure(control, error, error == 3 ? errno : 0);
            break;
        }
        if (now > UINT64_MAX - PROFILER_SAMPLE_PERIOD_NS) {
            failure(control, 4, 0);
            break;
        }
        uint64_t due = now + PROFILER_SAMPLE_PERIOD_NS;
        struct timespec deadline = {(time_t)(due / 1000000000), (long)(due % 1000000000)};
        int status;
        /* A spurious signal does not postpone the independent deadline or
         * constitute a timer tick. Keep retrying this SAME absolute deadline. */
        do {
            status = pthread_cond_timedwait(&control->condition, &control->mutex, &deadline);
        } while (status == 0 && atomic_load_explicit(&control->running, memory_order_acquire));
        if (!atomic_load_explicit(&control->running, memory_order_acquire))
            break;
        if (status != ETIMEDOUT) {
            failure(control, 5, status);
            break;
        }
        uint64_t wake;
        error = monotonic_ns(&wake);
        if (error) {
            failure(control, error, error == 3 ? errno : 0);
            break;
        }
        if (wake < due) {
            failure(control, 4, 0);
            break;
        }
        if (control->wakes == UINT64_MAX) {
            failure(control, 10, 0);
            break;
        }
        control->wakes++;
        unsigned next = (control->head + 1) % PROFILER_SAMPLE_TICKS;
        if (next == control->tail) {
            if (control->dropped == UINT64_MAX) {
                failure(control, 10, 0);
                break;
            }
            control->dropped++;
        } else {
            control->ticks[control->head] = (profiler_sample_tick){due, wake};
            control->head = next;
        }
        zend_atomic_bool_store_ex(control->interrupt, true);
    }
    if (control->error)
        zend_atomic_bool_store_ex(control->interrupt, true);
    atomic_store_explicit(&control->running, false, memory_order_release);
    pthread_mutex_unlock(&control->mutex);
    /* The stopper requires an actual successful join before reclaiming
     * control, executor memory, or this module's executable text. */
    return NULL;
}
/* Registry lock held; worker never enters TSRM, PHP/Rust, or this registry. */
static void stop_locked(sample_control *control, int reason) {
    if (control->worker_live) {
        pthread_mutex_lock(&control->mutex);
        atomic_store_explicit(&control->running, false, memory_order_release);
        pthread_cond_signal(&control->condition);
        pthread_mutex_unlock(&control->mutex);
        int status;
        /* A valid joinable handle has one joiner, serialized by slots_lock.
         * Never allow executor/control/DSO retirement without an ACTUAL join.
         * A transient native failure is disclosed; permanent OS/protocol
         * corruption cannot promise bounded shutdown progress. */
        while ((status = pthread_join(control->worker, NULL)) != 0) {
            pthread_mutex_lock(&control->mutex);
            if (!control->join_error)
                control->join_error = status;
            failure(control, 7, status);
            pthread_mutex_unlock(&control->mutex);
            sched_yield();
        }
        control->worker_live = false;
    }
    atomic_store_explicit(&control->interested, false, memory_order_release);
    pthread_mutex_lock(&control->mutex);
    if (reason == 2)
        control->termination = 2;
    else if (control->termination == 0 && reason)
        control->termination = reason;
    pthread_mutex_unlock(&control->mutex);
}
static void stop_control(sample_control *control, int reason) {
    if (!control->mutex_ready)
        return;
    lock_slots();
    stop_locked(control, reason);
    for (unsigned i = 0; i < SAMPLE_SLOTS; i++)
        if (slots[i] == control)
            slots[i] = NULL;
    unlock_slots();
}
static void fork_prepare(void) {
    lock_slots();
    for (unsigned i = 0; i < SAMPLE_SLOTS; i++)
        if (slots[i])
            stop_locked(slots[i], 2);
}
static void fork_parent(void) { unlock_slots(); }
static void fork_child(void) {
    /* Prepare joined all workers before fork. No allocator, PHP, Rust, or
     * pthread operation here. */
    for (unsigned i = 0; i < SAMPLE_SLOTS; i++)
        slots[i] = NULL;
    atomic_flag_clear_explicit(&slots_lock, memory_order_release);
}
static void control_construct(void *storage) {
    sample_control *control = storage;
    memset(control, 0, sizeof(*control));
    atomic_init(&control->running, false);
    atomic_init(&control->interested, false);
    int status = pthread_mutex_init(&control->mutex, NULL);
    if (status) {
        failure(control, 1, status);
        return;
    }
    control->mutex_ready = true;
    pthread_condattr_t attributes;
    status = pthread_condattr_init(&attributes);
    if (status) {
        failure(control, 1, status);
        return;
    }
    status = pthread_condattr_setclock(&attributes, CLOCK_MONOTONIC);
    if (!status)
        status = pthread_cond_init(&control->condition, &attributes);
    pthread_condattr_destroy(&attributes);
    if (status)
        failure(control, 1, status);
    else
        control->condition_ready = true;
}
static void control_destroy(void *storage) {
    sample_control *control = storage;
    stop_control(control, 1);
    if (control->condition_ready)
        pthread_cond_destroy(&control->condition);
    if (control->mutex_ready)
        pthread_mutex_destroy(&control->mutex);
}
static sample_control *current_control(void) {
#ifdef ZTS
    return ts_resource_ex(control_id, NULL);
#else
    return &nts_control;
#endif
}
static void snapshot_locked(sample_control *control, profiler_sample_status *out) {
    uint64_t snapshot = 0;
    int error = monotonic_ns(&snapshot);
    if (error)
        failure(control, error, error == 3 ? errno : 0);
    *out = (profiler_sample_status){control->wakes,
                                    control->dropped,
                                    (control->head + PROFILER_SAMPLE_TICKS - control->tail) %
                                        PROFILER_SAMPLE_TICKS,
                                    snapshot,
                                    SAMPLE_STACK,
                                    control->guard_bytes,
                                    sizeof(*control),
                                    atomic_load_explicit(&control->running, memory_order_acquire),
                                    control->termination,
                                    control->error,
                                    control->system_error};
}
void profiler_sampling_status(profiler_sample_status *out) {
    sample_control *control = current_control();
    if (!control->mutex_ready) {
        *out = (profiler_sample_status){0};
        out->error = 1;
        out->system_error = control->system_error;
        return;
    }
    pthread_mutex_lock(&control->mutex);
    snapshot_locked(control, out);
    pthread_mutex_unlock(&control->mutex);
}
size_t profiler_sampling_drain(profiler_sample_tick out[PROFILER_SAMPLE_TICKS],
                               profiler_sample_status *status) {
    sample_control *control = current_control();
    size_t count = 0;
    if (!control->mutex_ready) {
        profiler_sampling_status(status);
        return 0;
    }
    pthread_mutex_lock(&control->mutex);
    while (control->tail != control->head) {
        out[count++] = control->ticks[control->tail];
        control->tail = (control->tail + 1) % PROFILER_SAMPLE_TICKS;
    }
    snapshot_locked(control, status);
    pthread_mutex_unlock(&control->mutex);
    return count;
}
/* Physical frames include generator delegation placeholders. Read the SDK's
 * generator parent links without calling the mutating, unbounded backtrace
 * expander. Every physical frame/ancestor consumes the SAME 64-step budget. */
static size_t stack_functions(const zend_execute_data *frame,
                              zend_function *functions[PROFILER_SAMPLE_FRAMES], int *truncated) {
    const zend_execute_data *ancestors[PROFILER_SAMPLE_FRAMES];
    size_t depth = 0, walked = 0;
    *truncated = 0;
    while (frame && walked < PROFILER_SAMPLE_FRAMES) {
        walked++;
        if (frame->func) {
            functions[depth++] = frame->func;
            frame = frame->prev_execute_data;
            continue;
        }
        if (Z_TYPE(frame->This) != IS_OBJECT || Z_OBJCE(frame->This) != zend_ce_generator) {
            *truncated = 1;
            return depth;
        }
        const zend_execute_data *caller = frame->prev_execute_data;
        const zend_generator *generator = (const zend_generator *)Z_OBJ(frame->This);
        size_t count = 0;
        while (true) {
            if (walked == PROFILER_SAMPLE_FRAMES) {
                *truncated = 1;
                return depth;
            }
            walked++;
            if (!generator->execute_data || !generator->execute_data->func) {
                *truncated = 1;
                return depth;
            }
            if (!generator->node.parent)
                break;
            ancestors[count++] = generator->execute_data;
            generator = generator->node.parent;
        }
        /* A valid placeholder links delegators to the currently running root,
         * which was already captured. Closed/unknown markers cannot justify
         * an invented adjacency to the saved physical caller. */
        if (!count) {
            *truncated = 1;
            return depth;
        }
        /* Discovery is outer->inner, but sampled stacks are leaf->root. Emit
         * NOTHING from this chain until its near end was reached, so bounded
         * truncation never joins a leaf directly to a far-away ancestor. */
        while (count)
            functions[depth++] = ancestors[--count]->func;
        frame = caller;
    }
    *truncated = frame != NULL;
    return depth;
}
static zend_never_inline void sample_interrupt_active(zend_execute_data *execute_data,
                                                      sample_control *control) {
    {
        profiler_sample_tick ticks[PROFILER_SAMPLE_TICKS];
        profiler_sample_frame frames[PROFILER_SAMPLE_FRAMES];
        profiler_sample_status status;
        size_t count = profiler_sampling_drain(ticks, &status);
        if (count || status.error) {
            uint64_t service = 0;
            int clock_error = monotonic_ns(&service);
            if (!clock_error &&
                (service < status.snapshot_ns || (count && service < ticks[count - 1].wake_ns)))
                clock_error = 4;
            if (clock_error) {
                pthread_mutex_lock(&control->mutex);
                failure(control, clock_error, clock_error == 3 ? errno : 0);
                snapshot_locked(control, &status);
                pthread_mutex_unlock(&control->mutex);
            }
            zend_function *functions[PROFILER_SAMPLE_FRAMES];
            int truncated;
            size_t depth = stack_functions(execute_data, functions, &truncated);
            for (size_t index = 0; index < depth; index++) {
                zend_function *function = functions[index];
                profiler_sample_frame *item = &frames[index];
                memset(item, 0, sizeof(*item));
                if (function->common.function_name) {
                    item->name = ZSTR_VAL(function->common.function_name);
                    item->name_length = ZSTR_LEN(function->common.function_name);
                }
                if (function->common.scope && function->common.scope->name) {
                    item->scope = ZSTR_VAL(function->common.scope->name);
                    item->scope_length = ZSTR_LEN(function->common.scope->name);
                }
                item->internal = function->type == ZEND_INTERNAL_FUNCTION;
                if (function->type == ZEND_USER_FUNCTION &&
                    (function->common.fn_flags & ZEND_ACC_CLOSURE) &&
                    !(function->common.fn_flags & ZEND_ACC_FAKE_CLOSURE)) {
                    item->closure_identity = profiler_sampling_closure_identity(function);
                    if (!item->closure_identity) {
                        pthread_mutex_lock(&control->mutex);
                        failure(control, 9, 0);
                        snapshot_locked(control, &status);
                        pthread_mutex_unlock(&control->mutex);
                        /* An unknown closure lifetime cannot be replaced by a
                         * raw name or connected across a missing stack frame. */
                        depth = index;
                        truncated = 1;
                        break;
                    }
                }
            }
            profiler_sample_observation observation = {frames,  depth,  ticks,    count,
                                                       service, status, truncated};
            profiler_sampling_observe(&observation);
        }
    }
}
static void sample_interrupt(zend_execute_data *execute_data) {
    /* Another interrupt observer can retain this chain link after module
     * shutdown; no TSRM lookup is valid after our resource ID was freed. */
    if (!registered) {
        if (previous_interrupt)
            previous_interrupt(execute_data);
        return;
    }
    sample_control *control = current_control();
    /* Unrelated VM interrupts must not probe clocks, consume retained ticks,
     * or make a disabled sampler unavailable. All true publications precede
     * worker creation; false is published only after actual worker join. */
    if (atomic_load_explicit(&control->interested, memory_order_acquire) && control->request_active)
        sample_interrupt_active(execute_data, control);
    /* No Rust-owned object survives this point; previous handler may execute
     * PHP, throw, exit, fork, or longjmp via a fatal bailout. */
    if (previous_interrupt)
        previous_interrupt(execute_data);
}
uint64_t profiler_sampling_registry_bytes(void) { return sizeof(slots); }
int profiler_sampling_supported(void) { return 1; }
int profiler_sampling_register(void) {
    if (registered)
        return 1;
    if (!atfork_registered) {
        if (pthread_atfork(fork_prepare, fork_parent, fork_child) != 0)
            return 0;
        atfork_registered = true;
    }
#ifdef ZTS
    ts_allocate_id(&control_id, sizeof(sample_control), control_construct, control_destroy);
    if (!control_id)
        return 0;
#else
    control_construct(&nts_control);
#endif
    previous_interrupt = zend_interrupt_function;
    zend_interrupt_function = sample_interrupt;
    registered = true;
    return 1;
}
void profiler_sampling_module_shutdown(void) {
    if (!registered)
        return;
    registered = false; /* Disable retained chain links before resource cleanup. */
    if (zend_interrupt_function == sample_interrupt)
        zend_interrupt_function = previous_interrupt;
#ifdef ZTS
    ts_free_id(control_id);
#else
    control_destroy(&nts_control);
#endif
}
static void reset_control(sample_control *control, bool new_request) {
    /* Also covers a skipped prior RSHUTDOWN: retire the old executor pointer
     * before clearing counters or admitting any new request/session. */
    stop_control(control, 1);
    if (!control->condition_ready)
        return; /* Never fabricate ctor recovery. */
    pthread_mutex_lock(&control->mutex);
    if (new_request || control->termination != 2) {
        control->termination = 0;
        control->join_error = 0;
    }
    control->error = 0;
    control->system_error = 0;
    control->wakes = 0;
    control->dropped = 0;
    control->head = control->tail = 0;
    pthread_mutex_unlock(&control->mutex);
}
void profiler_sampling_reset(void) { reset_control(current_control(), false); }
void profiler_sampling_request_init(void) {
    sample_control *control = current_control();
    reset_control(control, true);
    control->request_active = true;
}
void profiler_sampling_request_shutdown(void) {
    sample_control *control = current_control();
    stop_control(control, 1);
    control->request_active = false;
}
void profiler_sampling_stop(void) { stop_control(current_control(), 1); }
void profiler_sampling_cleanup_error(int *kind, int *system_error) {
    sample_control *control = current_control();
    *kind = 0;
    *system_error = 0;
    if (!control->mutex_ready)
        return;
    pthread_mutex_lock(&control->mutex);
    if (control->join_error) {
        *kind = 7;
        *system_error = control->join_error;
    }
    pthread_mutex_unlock(&control->mutex);
}
int profiler_sampling_start(void) {
    sample_control *control = current_control();
    if (!control->request_active || !control->condition_ready)
        return 0;
    lock_slots();
    pthread_mutex_lock(&control->mutex);
    if (control->termination == 2) {
        pthread_mutex_unlock(&control->mutex);
        unlock_slots();
        return 3;
    }
    if (control->error) {
        pthread_mutex_unlock(&control->mutex);
        unlock_slots();
        return 0;
    }
    if (control->worker_live) {
        pthread_mutex_unlock(&control->mutex);
        unlock_slots();
        return 2;
    }
    pthread_mutex_unlock(&control->mutex);
    unsigned index;
    for (index = 0; index < SAMPLE_SLOTS && slots[index]; index++)
        ;
    if (index == SAMPLE_SLOTS) {
        pthread_mutex_lock(&control->mutex);
        failure(control, 2, 0);
        pthread_mutex_unlock(&control->mutex);
        unlock_slots();
        return 0;
    }
    pthread_attr_t attributes;
    int status = pthread_attr_init(&attributes);
    if (status) {
        pthread_mutex_lock(&control->mutex);
        failure(control, 1, status);
        pthread_mutex_unlock(&control->mutex);
        unlock_slots();
        return 0;
    }
    long page = sysconf(_SC_PAGESIZE);
    status = page <= 0 || SAMPLE_STACK < PTHREAD_STACK_MIN
                 ? EINVAL
                 : pthread_attr_setstacksize(&attributes, SAMPLE_STACK);
    if (!status)
        status = pthread_attr_setguardsize(&attributes, (size_t)page);
    int failure_kind = 1;
    sigset_t all;
    sigfillset(&all);
    /* glibc>=2.32 assigns the WORKER mask atomically at thread creation.
     * The PHP thread's signal mask is never changed, including on failure. */
    if (!status) {
        status = pthread_attr_setsigmask_np(&attributes, &all);
        failure_kind = 8;
    }
    if (!status) {
        failure_kind = 6;
        control->guard_bytes = (size_t)page;
        control->interrupt = &EG(vm_interrupt);
        control->termination = 0;
        slots[index] = control;
        atomic_store_explicit(&control->interested, true, memory_order_release);
        atomic_store_explicit(&control->running, true, memory_order_release);
        status = pthread_create(&control->worker, &attributes, sample_worker, control);
        control->worker_live = status == 0;
    }
    pthread_attr_destroy(&attributes);
    if (status) {
        slots[index] = NULL;
        atomic_store_explicit(&control->interested, false, memory_order_release);
        atomic_store(&control->running, false);
        pthread_mutex_lock(&control->mutex);
        failure(control, failure_kind, status);
        pthread_mutex_unlock(&control->mutex);
    }
    unlock_slots();
    return status ? 0 : 1;
}
#else
uint64_t profiler_sampling_registry_bytes(void) { return 0; }
int profiler_sampling_supported(void) { return 0; }
int profiler_sampling_register(void) { return 1; }
void profiler_sampling_module_shutdown(void) {}
void profiler_sampling_request_init(void) {}
void profiler_sampling_reset(void) {}
void profiler_sampling_request_shutdown(void) {}
int profiler_sampling_start(void) { return 0; }
void profiler_sampling_stop(void) {}
void profiler_sampling_cleanup_error(int *kind, int *system_error) {
    *kind = 0;
    *system_error = 0;
}
void profiler_sampling_status(profiler_sample_status *out) { *out = (profiler_sample_status){0}; }
size_t profiler_sampling_drain(profiler_sample_tick out[PROFILER_SAMPLE_TICKS],
                               profiler_sample_status *status) {
    (void)out;
    profiler_sampling_status(status);
    return 0;
}
#endif
