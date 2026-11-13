<?php

declare(strict_types=1);

use PhpProfiler\Profiler;

$session = Profiler::start();
usleep(1);
$session->stop();
$report = $session->getReport();
if ($report['request_peak_bytes'] !== null || $report['capabilities']['boundary_memory'] !== false) {
    throw new RuntimeException('Zend heap counters reported available while disabled');
}
foreach ([
    'memory_delta_bytes',
    'observed_boundary_peak_bytes',
    'session.memory_delta_bytes',
    'request_peak_bytes',
] as $metric) {
    if (!str_starts_with($report['metric_provenance'][$metric], 'unavailable:')) {
        throw new RuntimeException('unavailable metric lacks explicit provenance: ' . $metric);
    }
}
echo "disabled Zend heap metrics explicitly unavailable\n";
