<?php

declare(strict_types=1);

use PhpProfiler\Profiler;

$session = Profiler::start();
$allocation = str_repeat('x', 2 * 1024 * 1024);
usleep(1); // Observe the live allocation at an ordinary function boundary.
unset($allocation);
memory_reset_peak_usage();
$afterApplicationReset = memory_get_peak_usage(false);
$session->stop();
$beforeReportConversion = memory_get_peak_usage(false);
$report = $session->getReport();
if (
    $report['request_peak_bytes'] < $afterApplicationReset
    || $report['request_peak_bytes'] > $beforeReportConversion
    || $report['observed_boundary_peak_bytes'] <= $report['request_peak_bytes']
) {
    throw new RuntimeException('request peak reset window was confused with observed session boundaries');
}
$session->reset();
echo "application peak reset semantics preserved\n";
