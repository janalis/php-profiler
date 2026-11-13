<?php

declare(strict_types=1);

use PhpProfiler\Profiler;

require __DIR__ . '/../support/report-oom-scenario.php';
function fpm_fatal_leaf(): void
{
    usleep(1);
}

$mode = $_SERVER['PROFILE_FATAL_MODE'];
$path = $_SERVER['PROFILE_REPORT_PATH'];
echo 'WORKER_PID=' . getmypid() . "\n";
if (str_starts_with($mode, 'oom-')) {
    run_report_oom_scenario(substr($mode, 4), static function () use ($path): void {
        Profiler::start($path);
        fpm_fatal_leaf();
    });
} else {
    Profiler::start($path);
    fpm_fatal_leaf();
    if ($mode === 'exit') {
        exit(7);
    }
    throw new RuntimeException('expected uncaught FPM fixture exception');
}
echo "UNEXPECTED_CONTINUATION\n";
