<?php

declare(strict_types=1);

use PhpProfiler\Profiler;

$fault = $argv[1] ?? '';
if (!in_array($fault, ['cpu', 'wall'], true)) {
    throw new RuntimeException('expected a CPU or wall clock interposition mode');
}
function inactive_fiber_leaf(): void
{
    usleep(1);
}

function assert_inactive_fiber_report(array $report): int
{
    if (
        $report['status'] !== 'complete'
        || $report['unavailable_metrics'] !== []
        || $report['functions']['inactive_fiber_leaf']['calls'] !== 1
    ) {
        throw new RuntimeException('inactive Fiber clocks poisoned a later capture');
    }
    foreach ($report['timeline'] as $event) {
        if ($event['symbol'] === 'inactive_fiber_leaf') {
            return $event['context_id'];
        }
    }
    throw new RuntimeException('missing measured leaf event');
}

// No profiling has ever started. Both terminated and abandoned suspended Fibers
// must remain application-only execution, even when a native clock would fail.
putenv('PROFILER_TEST_CLOCK=' . $fault);
$finished = new Fiber(static function (): void {});
$finished->start();
unset($finished);
$suspended = new Fiber(static function (): void {
    Fiber::suspend();
});
$suspended->start();
unset($suspended);
putenv('PROFILER_TEST_CLOCK');
$retained = Profiler::start(null, ['timeline' => true]);
inactive_fiber_leaf();
$retained->stop();
assert_inactive_fiber_report($retained->getReport());
$before = $retained->getReportJson();

// Tracking another retained owner must not make an unrelated, unprofiled Fiber
// consult clocks or invent an identity to remove.
putenv('PROFILER_TEST_CLOCK=' . $fault);
$unprofiled = new Fiber(static function (): void {});
$unprofiled->start();
unset($unprofiled);
putenv('PROFILER_TEST_CLOCK');

// Retaining a completed main-context session preserves the identity storage.
// Destroy each Fiber only AFTER its session stops: inactive cleanup must still
// remove its identity, so allocator address reuse never aliases a later owner.
$contexts = [];
$previous = null;
for ($index = 0; $index < 32; ++$index) {
    $fiber = new Fiber(static function () use (&$previous, $fault) {
        try {
            Profiler::getReport();
            throw new RuntimeException('new Fiber inherited a destroyed owner\'s default session');
        } catch (\PhpProfiler\StateException $expected) {
        }
        // Keep the previous owner's report until after checking default lookup.
        if ($previous !== null) {
            $previous->reset();
        }
        $session = Profiler::start(null, ['timeline' => true]);
        inactive_fiber_leaf();
        $session->stop();
        // Zend may destroy the execution context before Fiber::start returns.
        putenv('PROFILER_TEST_CLOCK=' . $fault);
        return $session;
    });
    $fiber->start();
    putenv('PROFILER_TEST_CLOCK');
    $session = $fiber->getReturn();
    $json = $session->getReportJson();
    $contexts[] = assert_inactive_fiber_report($session->getReport());
    putenv('PROFILER_TEST_CLOCK=' . $fault);
    unset($fiber);
    putenv('PROFILER_TEST_CLOCK');
    if ($session->getReportJson() !== $json || $retained->getReportJson() !== $before) {
        throw new RuntimeException('inactive Fiber destruction changed a sealed report');
    }
    $previous = $session;
}
if (count(array_unique($contexts)) !== 32) {
    throw new RuntimeException('inactive Fiber owner identities were reused');
}
$previous->reset();
$healthy = Profiler::start(null, ['timeline' => true]);
inactive_fiber_leaf();
$healthy->stop();
assert_inactive_fiber_report($healthy->getReport());
Profiler::resetAll();
Profiler::disable();
echo "inactive Fiber destruction avoids $fault clocks and releases retained owner identities\n";
