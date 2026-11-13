<?php

declare(strict_types=1);

use PhpProfiler\Profiler;

function rollover_cpu_us(): int
{
    $usage = getrusage();
    return (
        (($usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec']) * 1000000)
        + $usage['ru_utime.tv_usec']
        + $usage['ru_stime.tv_usec']
    );
}

$session = Profiler::start();
usleep(1100000);
$session->stop();
$report = $session->getReport();
if ($report['status'] !== 'complete' || $report['elapsed_wall_ns'] < 1000000000) {
    throw new RuntimeException('wall clock lost a seconds rollover');
}
$session->reset();
$session = Profiler::start();
$cpuStart = rollover_cpu_us();
$deadline = hrtime(true) + 30000000000;
$sum = 0;
do {
    for ($i = 0; $i < 20000; ++$i) {
        $sum += $i % 97;
    }
    if (hrtime(true) > $deadline) {
        throw new RuntimeException('CPU rollover workload timed out');
    }
} while ((rollover_cpu_us() - $cpuStart) < 1050000);
$session->stop();
$report = $session->getReport();
if ($sum <= 0 || $report['status'] !== 'complete' || $report['thread_cpu_ns'] < 1000000000) {
    throw new RuntimeException('thread CPU clock lost a seconds rollover');
}
$session->reset();
Profiler::disable();
echo "real wall and thread CPU seconds rollover passed\n";
