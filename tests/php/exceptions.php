<?php

declare(strict_types=1);

use PhpProfiler\Profiler;

function profiled_throwing_leaf(): never
{
    throw new LogicException('private_exception_message');
}

function profiled_returning_leaf(int $value): int
{
    $GLOBALS['exception_return_sideeffect'] = $value;
    return $value;
}

$session = Profiler::start(null, ['timeline' => true]);
try {
    profiled_throwing_leaf();
} catch (LogicException $failure) {
    if ($failure->getMessage() !== 'private_exception_message') {
        throw new RuntimeException('exception changed');
    }
}
if (profiled_returning_leaf((int) ($argv[1] ?? 42)) !== 42) {
    throw new RuntimeException('return changed');
}
$session->stop();
$report = $session->getReport();
if (
    $report['functions']['profiled_throwing_leaf']['exceptions'] !== 1
    || $report['functions']['profiled_returning_leaf']['exceptions'] !== 0
) {
    throw new RuntimeException('exception outcome counts incorrect');
}
$outcomes = [];
foreach ($report['timeline'] as $event) {
    $outcomes[$event['symbol']] = $event['outcome'];
}
if ($outcomes['profiled_throwing_leaf'] !== 'exception' || $outcomes['profiled_returning_leaf'] !== 'return') {
    throw new RuntimeException('exception timeline outcome missing');
}
if (str_contains($session->getReportJson(), 'private_exception_message')) {
    throw new RuntimeException('exception message leaked');
}
Profiler::resetAll();
Profiler::disable();
echo "native exception outcomes and privacy passed\n";
