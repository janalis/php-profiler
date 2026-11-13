<?php

declare(strict_types=1);

use PhpProfiler\Profiler;

$name = 'adapter_oversized_' . str_repeat('n', 1025);
eval('function ' . $name . '():void{}');
$session = Profiler::start();
$name();
$session->stop();
$report = $session->getReport();
if ($report['status'] !== 'truncated' || $report['dropped_calls'] !== 1) {
    throw new RuntimeException('oversized native symbol was not explicitly dropped');
}
Profiler::resetAll();
// Begin fibers before capture so each resumed context has one captured frame.
// Retain suspended contexts to exercise both adapter context and frame limits.
ini_set('memory_limit', '512M');
$fibers = [];
for ($i = 0; $i < 8193; $i++) {
    $fiber = new Fiber(static function (): void {
        Fiber::suspend();
        Fiber::suspend();
    });
    $fiber->start();
    $fibers[] = $fiber;
}
$session = Profiler::start();
foreach ($fibers as $fiber) {
    $fiber->resume();
}
$session->stop();
if ($session->getReport()['status'] !== 'incomplete') {
    throw new RuntimeException('adapter context capacity failure was hidden');
}
$session->reset();
unset($fibers, $fiber);
$recovered = Profiler::start();
usleep(1);
$recovered->stop();
if ($recovered->getReport()['status'] !== 'complete') {
    throw new RuntimeException('adapter did not recover after resetting its last session');
}
Profiler::resetAll();
echo "adapter bounds and recovery passed\n";
