<?php

declare(strict_types=1);

use PhpProfiler\Profiler;

// Runs with the clock-fault interposer: every profiler CPU clock read fails.
function unmeasured_cpu_leaf(): void
{
    usleep(1);
}

function unmeasured_cpu_workload(): void
{
    unmeasured_cpu_leaf();
    $fiber = new Fiber(static function (): void {
        unmeasured_cpu_leaf();
        Fiber::suspend();
        unmeasured_cpu_leaf();
    });
    $fiber->start();
    $fiber->resume();
    $suspended = new Fiber(static function (): void {
        Fiber::suspend();
    });
    $suspended->start();
    unset($suspended);
}

putenv('PROFILER_TEST_CLOCK=cpu');
// Sessions that do not measure CPU never read the CPU clock: not at their start
// or stop, at observer boundaries, at Fiber switches or at Fiber destruction.
$first = Profiler::start(null, ['cpu' => false, 'timeline' => true]);
unmeasured_cpu_workload();
$second = Profiler::start(null, ['cpu' => false]);
unmeasured_cpu_workload();
$second->stop();
$first->stop();
foreach ([$first, $second] as $session) {
    $report = $session->getReport();
    if (
        $report['status'] !== 'complete'
        || $report['clock_regressions'] !== 0
        || $report['unavailable_metrics'] !== [
            'exclusive_cpu_ns',
            'inclusive_cpu_ns',
            'session.thread_cpu_ns',
            'timeline.active_cpu_ns',
        ]
        || $report['functions']['unmeasured_cpu_leaf']['inclusive_wall_ns'] <= 0
    ) {
        throw new RuntimeException('a session without CPU measurement read the failing CPU clock');
    }
}
Profiler::resetAll();
// The interposer is active: a measuring session observes the failure.
$measured = Profiler::start();
unmeasured_cpu_leaf();
$measured->stop();
$report = $measured->getReport();
if ($report['status'] !== 'incomplete' || !in_array('session.thread_cpu_ns', $report['unavailable_metrics'], true)) {
    throw new RuntimeException('the CPU clock fault was not injected');
}
Profiler::resetAll();
// An overlapping measuring session reads the failing CPU clock; that failure
// belongs to it alone. The session without CPU keeps its measured wall metrics.
$unmeasured = Profiler::start(null, ['cpu' => false]);
$measured = Profiler::start();
unmeasured_cpu_workload();
$unmeasured->stop();
unmeasured_cpu_leaf();
$measured->stop();
$report = $unmeasured->getReport();
if (
    $report['status'] !== 'complete'
    || $report['clock_regressions'] !== 0
    || $report['hotspots'] === []
    || $report['functions']['unmeasured_cpu_leaf']['inclusive_wall_ns'] <= 0
    || !str_starts_with($report['metric_provenance']['inclusive_wall_ns'], 'measured:')
    || !str_starts_with($report['metric_provenance']['session.elapsed_wall_ns'], 'measured:')
    || $report['unavailable_metrics'] !== [
        'exclusive_cpu_ns',
        'inclusive_cpu_ns',
        'session.thread_cpu_ns',
        'timeline.active_cpu_ns',
    ]
    || !str_starts_with($report['metric_provenance']['inclusive_cpu_ns'], 'unavailable:thread-cpu-disabled')
) {
    throw new RuntimeException('another session\'s CPU clock failure invalidated a session without CPU');
}
$report = $measured->getReport();
if (
    $report['status'] !== 'incomplete'
    || $report['hotspots'] !== []
    || !str_starts_with($report['metric_provenance']['inclusive_wall_ns'], 'unavailable:clock-failure')
    || !str_starts_with($report['metric_provenance']['session.thread_cpu_ns'], 'unavailable:clock-failure')
) {
    throw new RuntimeException('an overlapping measuring session hid its CPU clock failure');
}
Profiler::resetAll();
// Once every session is gone the CPU failure is forgotten with the clock state.
putenv('PROFILER_TEST_CLOCK=');
$recovered = Profiler::start();
unmeasured_cpu_leaf();
$recovered->stop();
if ($recovered->getReport()['status'] !== 'complete') {
    throw new RuntimeException('a CPU clock failure outlived its sessions');
}
Profiler::resetAll();
Profiler::disable();
echo "unmeasured CPU never reads the CPU clock\n";
