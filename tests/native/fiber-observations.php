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
// Without a CPU-measuring session, destruction forwards the CPU placeholder, never a reading.
$session = Profiler::start(null, ['cpu' => false]);
if (!profiler_test_fiber_observation_arm(true)) {
    throw new RuntimeException('cannot establish healthy independent clock bounds');
}
$fiber = new Fiber(static function (): int {
    return 73;
});
$fiber->start();
unset($fiber);
if (!profiler_test_fiber_observation_valid()) {
    throw new RuntimeException('an unmeasured Fiber destruction forwarded a CPU value, not the placeholder');
}
$session->stop();
// Any CPU-measuring session restores genuine readings for every overlapping session.
$unmeasured = Profiler::start(null, ['cpu' => false]);
$measured = Profiler::start();
if (!profiler_test_fiber_observation_arm()) {
    throw new RuntimeException('cannot establish healthy independent clock bounds');
}
$fiber = new Fiber(static function (): int {
    return 73;
});
$fiber->start();
unset($fiber);
if (!profiler_test_fiber_observation_valid()) {
    throw new RuntimeException('a CPU-measuring session received a skipped CPU reading');
}
$measured->stop();
$unmeasured->stop();
Profiler::resetAll();
Profiler::disable();
echo "active Fiber destruction forwards independently bracketed clocks and exact Zend heap\n";
