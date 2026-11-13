<?php

declare(strict_types=1);

use PhpProfiler\ConfigurationException;
use PhpProfiler\Profiler;

$plain = Profiler::start(null, ['sampling' => false]);
$plain->stop();
if ($plain->getReport()['schema_version'] !== 1) {
    throw new RuntimeException('disabled sampling changed v1');
}
$plain->reset();
try {
    Profiler::start(null, ['sampling' => 1]);
    throw new RuntimeException('sampling accepted non-boolean');
} catch (ConfigurationException) {
}
if (getenv('CI_PROFILER_REQUIRE_SAMPLING') === '1' && !Profiler::isSamplingSupported()) {
    throw new RuntimeException('this CI platform requires native sampling');
}
if (!Profiler::isSamplingSupported()) {
    try {
        Profiler::start(null, ['sampling' => true]);
        throw new RuntimeException('unsupported sampling accepted');
    } catch (ConfigurationException) {
    }
    echo "unsupported sampler preserves ordinary profiling\n";
    return;
}
function sampled_cpu_work(): int
{
    $value = 0;
    for ($index = 0; $index < 10000000; ++$index) {
        $value += $index & 7;
    }
    return $value;
}

$sampled = Profiler::start(null, ['sampling' => true, 'timeline' => true]);
$ordinary = Profiler::start();
if (sampled_cpu_work() !== 35000000) {
    throw new RuntimeException('sampling changed execution');
}
usleep(100000); // Actual independently generated wakes can coalesce in internal code.
$ordinary->stop();
$sampled->stop();
$report = $sampled->getReport();
if (
    $ordinary->getReport()['schema_version'] !== 1
    || $report['schema_version'] !== 2
    || $report['status'] !== 'complete'
    || $report['functions']['sampled_cpu_work']['calls'] !== 1
    || $report['sampling']['captured_samples'] < 1
) {
    throw new RuntimeException('sampling did not preserve independent captures');
}
if (json_decode($sampled->getReportJson(), true, 512, JSON_THROW_ON_ERROR) !== $report) {
    throw new RuntimeException('sampled JSON and structured report disagree');
}
$ordinary->reset();
$sampled->reset();
Profiler::disable();
echo "wall-timer safepoint sampling and independent v1/v2 captures passed\n";
