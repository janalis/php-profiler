<?php

declare(strict_types=1);

use PhpProfiler\Profiler;

function sealed_original_work(): int
{
    usleep(1000);
    return 17;
}

function sealed_unrelated_work(): int
{
    return 29;
}

$session = Profiler::start();
if (sealed_original_work() !== 17)
    throw new RuntimeException('original execution changed');
profiler_adapter_sealed_control('hold');
try {
    $session->stop();
    throw new RuntimeException('full quota unexpectedly materialized report');
} catch (PhpProfiler\StateException $error) {
    if (!str_contains($error->getMessage(), 'budget exhausted'))
        throw $error;
}
try {
    Profiler::getReportJson($session);
    throw new RuntimeException('static report wrapper accepted unfinished materialization');
} catch (PhpProfiler\StateException $error) {
    if ($error->getMessage() !== 'Stop the session before retrieving its report')
        throw $error;
}
profiler_adapter_sealed_control('checkpoint');
ini_set('opcache.enable', str_repeat('0', 129));
memory_reset_peak_usage();
$foreign = str_repeat('x', 4 * 1024 * 1024);
for ($i = 0; $i < 20; ++$i) {
    $closure = eval('return static function(): int { return ' . $i . '; };');
    if ($closure() !== $i || sealed_unrelated_work() !== 29)
        throw new RuntimeException('sealed-window execution changed');
    unset($closure);
}
usleep(150000);
profiler_adapter_sealed_control('poison');
profiler_adapter_sealed_control('unchanged');
profiler_adapter_sealed_control('release');
$session->stop();
profiler_adapter_sealed_control('report');
$report = $session->getReport();
if (isset($report['functions']['sealed_unrelated_work']))
    throw new RuntimeException('sealed engine collected unrelated work');
profiler_adapter_sealed_control('cleanup');
$healthy = Profiler::start();
$healthy->stop();
if ($healthy->getReport()['status'] !== 'complete')
    throw new RuntimeException('reset did not recover');
Profiler::resetAll();
$overflow = Profiler::start();
profiler_adapter_sealed_control('frame-max');
if (sealed_unrelated_work() !== 29)
    throw new RuntimeException('counter exhaustion changed PHP result');
profiler_adapter_sealed_control('frame-max-check');
$overflow->stop();
if ($overflow->getReport()['status'] !== 'incomplete')
    throw new RuntimeException('frame exhaustion aliased identities');
profiler_adapter_sealed_control('cleanup');
$stillExhausted = Profiler::start();
if (sealed_unrelated_work() !== 29)
    throw new RuntimeException('exhaustion after reset changed PHP result');
$stillExhausted->stop();
if ($stillExhausted->getReport()['status'] !== 'incomplete')
    throw new RuntimeException('reset reused exhausted frame identities');
echo "sealed stop quota retry preserves native facts; exhausted identities remain fail closed until next request\n";
