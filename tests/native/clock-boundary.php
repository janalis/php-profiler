<?php

declare(strict_types=1);

use PhpProfiler\Profiler;

function clock_boundary_leaf(): void
{
    usleep(1);
}

$session = Profiler::start(null, ['timeline' => true]);
clock_boundary_leaf();
$session->stop();
$report = $session->getReport();
if ($report['status'] !== 'complete') {
    throw new RuntimeException('representable clock boundary rejected');
}
if (str_starts_with(getenv('PROFILER_TEST_CLOCK'), 'wall')) {
    $events = array_values(array_filter($report['timeline'], fn($event) => $event['symbol'] === 'clock_boundary_leaf'));
    $expected = str_ends_with(getenv('PROFILER_TEST_CLOCK'), '_zero') ? 0 : '18446744073709551615';
    if (count($events) !== 1 || $events[0]['start_ns'] !== $expected || $events[0]['end_ns'] !== $expected) {
        throw new RuntimeException('unsigned nanosecond boundary lost precision in PHP data');
    }
}
echo "representable clock boundary preserved losslessly\n";
