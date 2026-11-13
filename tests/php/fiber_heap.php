<?php

declare(strict_types=1);

use PhpProfiler\Profiler;

function fiber_heap_target(): void
{
    $GLOBALS['fiber_heap_buffers'][] = str_repeat('a', 1024 * 1024);
    Fiber::suspend();
    $GLOBALS['fiber_heap_buffers'][] = str_repeat('b', 1024 * 1024);
}

$GLOBALS['fiber_heap_buffers'] = [];
$fiber = new Fiber('fiber_heap_target');
$session = Profiler::start();
$fiber->start();
$foreign = str_repeat('c', 1024 * 1024);
$fiber->resume();
$session->stop();
$report = $session->getReport();
$delta = $report['functions']['fiber_heap_target']['memory_delta_bytes'];
$expected = 2 * 1024 * 1024;
if (abs($delta - $expected) > 65536 || $report['status'] !== 'complete') {
    throw new RuntimeException('fiber heap attribution includes foreign suspended allocation: ' . $delta);
}
if ($report['memory_delta_bytes'] < (3 * 1024 * 1024) || strlen($foreign) !== (1024 * 1024)) {
    throw new RuntimeException('session heap observation or allocation semantics changed');
}
if (count($GLOBALS['fiber_heap_buffers']) !== 2) {
    throw new RuntimeException('fiber allocations changed');
}
Profiler::resetAll();
Profiler::disable();
unset($fiber, $foreign, $GLOBALS['fiber_heap_buffers']);
echo "fiber heap attribution excludes foreign suspended allocations passed\n";
