<?php

declare(strict_types=1);

use PhpProfiler\Profiler;

ini_set('memory_limit', '512M');
function profiler_frame_capacity_leaf(int $depth): int
{
    return $depth ? profiler_frame_capacity_leaf($depth - 1) + 1 : 0;
}

$session = Profiler::start();
if (profiler_frame_capacity_leaf(8200) !== 8200)
    throw new RuntimeException('frame capacity changed recursive result');
$session->stop();
if ($session->getReport()['status'] !== 'incomplete')
    throw new RuntimeException('live frame capacity failure was hidden');
$session->reset();
$healthy = Profiler::start();
usleep(1);
$healthy->stop();
if ($healthy->getReport()['status'] !== 'complete')
    throw new RuntimeException('frame capacity failure poisoned later capture');
Profiler::resetAll();
echo "authentic recursive live-frame capacity and reset recovery passed\n";
