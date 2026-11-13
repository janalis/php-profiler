/* External test-only thunk: keep the original source call external so Mull's
 * scalar/void-call mutation inventory cannot vanish through test inlining. */
#include "../../crates/php-profiler/src/sampling.h"
#ifdef PROFILER_SAMPLING_TEST_TRANSPORTS
extern void __real_profiler_sampling_observe(const profiler_sample_observation *);
#else
#define __real_profiler_sampling_observe profiler_sampling_observe
#endif
extern int sampling_frames_inspect_contract(const profiler_sample_observation *observation);
void __wrap_profiler_sampling_observe(const profiler_sample_observation *observation) {
    if (sampling_frames_inspect_contract(observation))
        __real_profiler_sampling_observe(observation);
}
