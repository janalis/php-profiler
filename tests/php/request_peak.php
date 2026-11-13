<?php

declare(strict_types=1);

use PhpProfiler\Profiler;

$session = Profiler::start();
$large = str_repeat('p', 2 * 1024 * 1024);
unset($large);
memory_reset_peak_usage();
$small = str_repeat('s', 16384);
$expected = memory_get_peak_usage();
$session->stop();
$report = $session->getReport();
if ($report['request_peak_bytes'] !== $expected) {
    throw new RuntimeException('profiler reset or misread the application request peak window');
}
if ($report['observed_boundary_peak_bytes'] < (2 * 1024 * 1024)) {
    throw new RuntimeException('boundary observations confused with reset request peak');
}
unset($small);
Profiler::resetAll();
echo "application-resettable request peak snapshot passed\n";
