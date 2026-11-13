<?php

declare(strict_types=1);

use PhpProfiler\Profiler;

// Distinct generated sites stress request-owned identity storage, independently
// of the much smaller call-graph report limits. Application objects remain live.
foreach (['closures', 'anonymous-classes'] as $mode) {
    Profiler::resetAll();
    $expression = $mode === 'closures'
        ? 'static function (): int { return 7; }'
        : 'new class { public function run(): int { return 7; } }';
    $objects = eval('return [' . implode(',', array_fill(0, 8193, $expression)) . '];');
    $session = Profiler::start();
    foreach ($objects as $object) {
        $result = $mode === 'closures' ? $object() : $object->run();
        if ($result !== 7) {
            throw new RuntimeException('generated application semantics changed');
        }
    }
    $session->stop();
    $report = $session->getReport();
    if ($report['status'] !== 'incomplete' || Profiler::isEnabled()) {
        throw new RuntimeException($mode . ': identity storage exhaustion was not explicit and contained');
    }
    $session->reset();
    $recovered = Profiler::start();
    usleep(1);
    $recovered->stop();
    if ($recovered->getReport()['status'] !== 'complete') {
        throw new RuntimeException($mode . ': exhausted identity storage poisoned the next worker job');
    }
    unset($objects);
}
Profiler::resetAll();
Profiler::disable();
echo "generated symbol identity exhaustion is bounded and next-job recovery passes\n";
