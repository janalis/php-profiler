<?php

declare(strict_types=1);

use PhpProfiler\Profiler;

function clock_fault_leaf(): void
{
    usleep(1);
}

$session = Profiler::start();
clock_fault_leaf();
$session->stop();
$report = $session->getReport();
if ($report['status'] !== 'incomplete') {
    throw new RuntimeException('Clock failure must prevent complete report');
}
if (!str_contains(implode(' ', $report['limitations']), 'clock')) {
    throw new RuntimeException('Clock failure must be disclosed');
}
foreach ([
    'inclusive_wall_ns',
    'exclusive_cpu_ns',
    'session.elapsed_wall_ns',
    'session.thread_cpu_ns',
    'hotspots',
] as $metric) {
    if (
        !in_array($metric, $report['unavailable_metrics'], true)
        || !str_starts_with($report['metric_provenance'][$metric], 'unavailable:')
    ) {
        throw new RuntimeException('failed timing was presented as an available measurement');
    }
}
if ($report['hotspots'] !== []) {
    throw new RuntimeException('rankings used unavailable timing data');
}
echo "clock failure disclosed\n";
