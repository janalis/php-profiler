<?php

declare(strict_types=1);

use PhpProfiler\Profiler;

function worker_job(): int
{
    return array_sum([1, 2, 3]);
}

for ($iteration = 0; $iteration < 1000; ++$iteration) {
    $id = Profiler::start();
    if (worker_job() !== 6) {
        throw new RuntimeException('changed result');
    }
    Profiler::stop($id);
    $json = Profiler::getReportJson($id);
    if (!str_contains($json, 'worker_job')) {
        throw new RuntimeException('missing job');
    }
    Profiler::reset($id);
}
Profiler::disable();
echo "worker lifecycle passed\n";
