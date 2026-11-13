<?php

declare(strict_types=1);

use PhpProfiler\Profiler;

$fault = $argv[1] ?? '';
if (!in_array($fault, ['cpu', 'wall'], true)) {
    throw new RuntimeException('expected clock fault mode');
}
function late_output_leaf(): void
{
    usleep(1);
}

$directory = sys_get_temp_dir() . '/profiler-late-output-' . bin2hex(random_bytes(8));
if (!mkdir($directory, 0700)) {
    throw new RuntimeException('cannot create private output directory');
}
$directory = realpath($directory);
$path = null;
try {
    $session = Profiler::start();
    late_output_leaf();
    $session->stop();
    $before = $session->getReportJson();
    putenv('PROFILER_TEST_CLOCK=' . $fault);
    $session->setOutputPath($directory);
    $session->stop();
    putenv('PROFILER_TEST_CLOCK');
    $path = $session->getReportPath();
    if (
        $path === null
        || dirname($path) !== $directory
        || file_get_contents($path) !== $before
        || $session->getReportJson() !== $before
    ) {
        throw new RuntimeException('late directory publication changed the frozen report');
    }
    // Keep the first record: request-sticky errors cannot be hidden by reset.
    $next = Profiler::start();
    late_output_leaf();
    $next->stop();
    $report = $next->getReport();
    if (
        $report['status'] !== 'complete'
        || $report['unavailable_metrics'] !== []
        || $report['functions']['late_output_leaf']['calls'] !== 1
    ) {
        throw new RuntimeException('late output observed unrelated clocks and poisoned the next capture');
    }
} finally {
    putenv('PROFILER_TEST_CLOCK');
    Profiler::resetAll();
    Profiler::disable();
    if ($path !== null && is_file($path)) {
        unlink($path);
    }
    rmdir($directory);
}
echo "late directory publication ignores unrelated $fault clocks and preserves later captures\n";
