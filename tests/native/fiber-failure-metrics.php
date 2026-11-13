<?php

declare(strict_types=1);

use PhpProfiler\Profiler;

function suspended_failure_owner(array &$retained): void
{
    // Retain distinct opcode arrays so the bounded private identity map fills.
    for ($i = 0; $i < 8200; ++$i) {
        $closure = eval('return static fn(): int => 1;');
        $retained[] = $closure;
        $closure();
    }
    Fiber::suspend();
}

$retained = [];
$session = Profiler::start();
$fiber = new Fiber(static function () use (&$retained): void {
    suspended_failure_owner($retained);
});
$fiber->start();
if (Profiler::isEnabled()) {
    throw new RuntimeException('private identity limit was not reached');
}
unset($fiber);
$session->stop();
$report = $session->getReport();
$owner = $report['functions']['suspended_failure_owner'] ?? null;
if (
    $report['status'] !== 'incomplete'
    || $report['clock_regressions'] !== 0
    || $owner === null
    || $owner['memory_delta_bytes'] < (1024 * 1024)
) {
    throw new RuntimeException('destroyed failure context used invalid clock/heap observations');
}
$session->reset();
$healthy = Profiler::start();
usleep(1);
$healthy->stop();
if ($healthy->getReport()['status'] !== 'complete') {
    throw new RuntimeException('failed capture poisoned next job');
}
echo "destroyed failure context retains real clock and heap observations\n";
