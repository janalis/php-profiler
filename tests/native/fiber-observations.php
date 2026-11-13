<?php

declare(strict_types=1);

use PhpProfiler\Profiler;

// Keep a real, externally observable allocation alive through context teardown.
$retained = str_repeat('m', 1024 * 1024);
$session = Profiler::start();
if (!profiler_test_fiber_observation_arm()) {
    throw new RuntimeException('cannot establish healthy independent clock bounds');
}
$fiber = new Fiber(static function (): int {
    return 73;
});
if ($fiber->start() !== null || $fiber->getReturn() !== 73) {
    throw new RuntimeException('Fiber application return semantics changed');
}
unset($fiber);
if (!profiler_test_fiber_observation_valid() || strlen($retained) !== (1024 * 1024)) {
    throw new RuntimeException('active Fiber destruction violated its native observation contract');
}
$session->stop();
if ($session->getReport()['status'] !== 'complete') {
    throw new RuntimeException('native observation check invalidated healthy measurements');
}
Profiler::resetAll();
Profiler::disable();
echo "active Fiber destruction forwards independently bracketed clocks and exact Zend heap\n";
