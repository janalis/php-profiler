<?php

declare(strict_types=1);

use PhpProfiler\Profiler;

function schema_leaf(int $x): int
{
    return $x + 1;
}

$reports = [];
foreach ([false, true] as $timeline) {
    $session = Profiler::start(null, ['timeline' => $timeline, 'max_events' => 2]);
    for ($i = 0; $i < 5; ++$i) {
        schema_leaf($i);
    }
    $session->stop();
    $reports[] = json_decode($session->getReportJson(), false, 512, JSON_THROW_ON_ERROR);
    $session->reset();
}
$session = Profiler::start();
Profiler::disable();
$reports[] = json_decode($session->getReportJson(), false, 512, JSON_THROW_ON_ERROR);
$session->reset();
if (Profiler::isSamplingSupported()) {
    foreach ([64, 1] as $limit) {
        $session = Profiler::start(null, ['sampling' => true, 'max_events' => $limit]);
        $sum = 0;
        for ($i = 0; $i < 10000000; ++$i) {
            $sum += $i & 7;
        }
        usleep(100000);
        $session->stop();
        $sampled = json_decode($session->getReportJson(), false, 512, JSON_THROW_ON_ERROR);
        if ($sum !== 35000000 || $sampled->sampling->captured_samples < 1) {
            throw new RuntimeException('schema fixture requires actual timer samples');
        }
        $reports[] = $sampled;
        $session->reset();
    }
}
echo json_encode($reports, JSON_THROW_ON_ERROR);
