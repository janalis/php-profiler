<?php

declare(strict_types=1);

use PhpProfiler\Profiler;

$expected = profiler_test_sampling_status()['expected_supported'];
if (Profiler::isSamplingSupported() !== $expected)
    throw new RuntimeException('capability mismatch');
if (!$expected)
    return;
$session = Profiler::start(null, ['sampling' => true]);
$closure = static function (): int {
    if (!profiler_test_sampling_service())
        throw new RuntimeException('real identity-fault service absent');
    return 79;
};
profiler_test_sampling_fault('identity');
if ($closure() !== 79)
    throw new RuntimeException('closure application semantics changed');
$session->stop();
$report = $session->getReport();
$closureRows = array_filter(
    $report['functions'],
    static fn(string $name): bool => str_contains($name, '{closure#'),
    ARRAY_FILTER_USE_KEY,
);
if (
    profiler_test_sampling_fault_consumed() !== 1
    || $report['status'] !== 'complete'
    || count($closureRows) !== 1
    || array_values($closureRows)[0]['calls'] !== 1
    || $report['sampling']['failure_reason'] !== 'identity_failure'
) {
    throw new RuntimeException('sampling-only identity failure lost graph accuracy or disclosure');
}
foreach ($report['sampling']['symbols'] as $symbol) {
    if (str_contains($symbol, 'sample-closure#'))
        throw new RuntimeException('unidentified closure received an invented alias');
}
$prefix = false;
foreach ($report['sampling']['samples'] as $sample) {
    $stack = array_map(
        static fn(int $id): string => $report['sampling']['symbols'][$id],
        array_slice($report['sampling']['frames'], $sample['first_frame'], $sample['frame_count']),
    );
    if ($sample['depth_truncated'] && $stack === ['profiler_test_sampling_service'])
        $prefix = true;
}
if (!$prefix)
    throw new RuntimeException('sampling identity failure did not preserve only the valid physical prefix');
if (str_contains($session->getReportJson(), __FILE__))
    throw new RuntimeException('identity failure leaked generated source path');
Profiler::resetAll();
Profiler::disable();
echo "sampling-only UID failure preserves graph and honest truncated prefix passed\n";
