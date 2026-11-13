#ifndef PHP_PROFILER_SAMPLING_H
#define PHP_PROFILER_SAMPLING_H
#include <stddef.h>
#include <stdint.h>
#define PROFILER_SAMPLE_FRAMES 64
#define PROFILER_SAMPLE_TICKS 64
#define PROFILER_SAMPLE_PERIOD_NS UINT64_C(10000000)
/* Stable C ABI: all pointers below are borrowed only until the sink returns. */
typedef struct {
    uint64_t due_ns, wake_ns;
} profiler_sample_tick;
typedef struct {
    const char *name;
    size_t name_length;
    const char *scope;
    size_t scope_length;
    uint64_t closure_identity;
    int internal;
} profiler_sample_frame;
typedef struct {
    uint64_t wakes, dropped_ticks, pending_ticks, snapshot_ns;
    uint64_t worker_stack_bytes, worker_guard_bytes, control_bytes;
    int running, termination, error, system_error;
} profiler_sample_status;
typedef struct {
    const profiler_sample_frame *frames;
    size_t frame_count;
    const profiler_sample_tick *ticks;
    size_t tick_count;
    uint64_t service_ns;
    profiler_sample_status status;
    int stack_truncated;
} profiler_sample_observation;
/* start: 1 started, 2 already running, 3 fork terminated, 0 unavailable.
 * termination: 0 none, 1 stopped, 2 fork, 3 error.
 * error: 0 none, 1 initialization, 2 capacity, 3 clock, 4 range,
 * 5 wait, 6 create, 7 join, 8 signal mask, 9 identity, 10 counters. */
uint64_t profiler_sampling_registry_bytes(void);
int profiler_sampling_supported(void);
int profiler_sampling_register(void);
void profiler_sampling_module_shutdown(void);
void profiler_sampling_request_init(void);
/* Only when no session depends on backend state; fork remains sticky. */
void profiler_sampling_reset(void);
void profiler_sampling_request_shutdown(void);
int profiler_sampling_start(void);
void profiler_sampling_stop(void);
/* Clock-free resource cleanup diagnostic; separate from sealed measurements. */
void profiler_sampling_cleanup_error(int *kind, int *system_error);
void profiler_sampling_status(profiler_sample_status *out);
size_t profiler_sampling_drain(profiler_sample_tick out[PROFILER_SAMPLE_TICKS],
                               profiler_sample_status *status);
/* Called by C on the PHP thread; must contain Rust panics and drop all owners
 * before returning. The previous Zend interrupt handler is called afterwards. */
void profiler_sampling_observe(const profiler_sample_observation *observation);
#endif
