<?php

declare(strict_types=1);

use PhpProfiler\Profiler;

function repeated_bounded_leaf(int $input): int
{
    $GLOBALS['capacity_side_effect'] += $input;
    return $input;
}

foreach ([false, true] as $timeline) {
    $session = Profiler::start(null, ['max_events' => 8, 'timeline' => $timeline]);
    $sum = 0;
    $GLOBALS['capacity_side_effect'] = 0;
    for ($i = 0; $i < 2000; ++$i) {
        $sum += repeated_bounded_leaf((int) ($argv[1] ?? 1));
    }
    $session->stop();
    $report = $session->getReport();
    if (
        $GLOBALS['capacity_side_effect'] !== 2000
        || $sum !== 2000
        || $report['functions']['repeated_bounded_leaf']['calls'] !== 2000
    ) {
        throw new RuntimeException('bounded storage incorrectly dropped repeated aggregation');
    }
    if ($timeline && ($report['dropped_timeline_events'] <= 0 || count($report['timeline']) > 8)) {
        throw new RuntimeException('timeline overflow not bounded or disclosed');
    }
    if (!$timeline && $report['status'] !== 'complete') {
        throw new RuntimeException('unbounded logical calls marked incomplete');
    }
    $session->reset();
}
Profiler::disable();
echo "bounded storage preserves repeated call counts passed\n";
