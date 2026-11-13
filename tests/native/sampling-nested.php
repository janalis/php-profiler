<?php

declare(strict_types=1);

use PhpProfiler\Profiler;

require __DIR__ . '/../support/stopped-sampling-assertions.php';
function sampling_nested_check(bool $condition, string $message): void
{
    if (!$condition)
        throw new RuntimeException($message);
}

$expectedSampling = profiler_test_sampling_status()['expected_supported'];
if (Profiler::isSamplingSupported() !== $expectedSampling)
    throw new RuntimeException('public capability differs from compiled platform contract');
if (!$expectedSampling)
    return;
function sampling_nested_leaf(): int
{
    sampling_nested_check(profiler_test_sampling_service(), 'nested timer tick unavailable');
    return 71;
}

$firstClosure = static function (): int {
    sampling_nested_check(profiler_test_sampling_service(), 'first closure timer tick unavailable');
    return 83;
};
$secondClosure = static function (): int {
    sampling_nested_check(profiler_test_sampling_service(), 'second closure timer tick unavailable');
    return 89;
};
$outer = Profiler::start(null, ['sampling' => true]);
sampling_nested_check($firstClosure() === 83, 'first closure semantics');
$inner = Profiler::start(null, ['sampling' => true]);
sampling_nested_check($secondClosure() === 89, 'second closure semantics');
$ordinary = Profiler::start();
sampling_nested_check(sampling_nested_leaf() === 71, 'first nested semantics');
$inner->stop();
$innerReport = $inner->getReport();
assert_stopped_sampling($innerReport['sampling']);
sampling_nested_check(
    $innerReport['status'] === 'complete'
    && $innerReport['sampling']['captured_samples'] > 0
    && $innerReport['runtime_metadata']['sampling.cleanup_deferred'] === 'true',
    'inner independent finish/deferred cleanup',
);
$innerJson = $inner->getReportJson();
$status = profiler_test_sampling_status();
sampling_nested_check(
    $status['created'] === 1 && $status['joined'] === 0 && $status['running'] === 1,
    'nested session created/stopped a separate worker',
);
sampling_nested_check(sampling_nested_leaf() === 71, 'second nested semantics');
$ordinary->stop();
$outer->stop();
$outerReport = $outer->getReport();
$status = profiler_test_sampling_status();
sampling_nested_check(
    $status['created'] === 1 && $status['joined'] === 1 && $status['running'] === 0,
    'last owner did not join shared worker',
);
sampling_nested_check(
    $outerReport['runtime_metadata']['sampling.cleanup_deferred'] === 'false'
    && $outerReport['runtime_metadata']['sampling.cleanup_error_code'] === '0',
    'last owner cleanup metadata',
);
sampling_nested_check(
    $outerReport['functions']['sampling_nested_leaf']['calls'] === 2
    && $innerReport['functions']['sampling_nested_leaf']['calls'] === 1
    && $ordinary->getReport()['functions']['sampling_nested_leaf']['calls'] === 2,
    'independent call ownership',
);
sampling_nested_check(
    $ordinary->getReport()['schema_version'] === 1 && $outerReport['schema_version'] === 2,
    'independent schema versions',
);
sampling_nested_check($inner->getReportJson() === $innerJson, 'later cleanup changed sealed inner report');
$closureGraph = [];
foreach ($outerReport['functions'] as $symbol => $metrics) {
    if (preg_match('/^\{closure#[0-9]+\}$/D', $symbol) === 1)
        $closureGraph[$symbol] = $metrics['calls'];
}
sampling_nested_check(
    count($closureGraph) === 2 && array_values($closureGraph) === [1, 1],
    'distinct real closure call identities',
);
$closureSamples = [];
foreach ($outerReport['sampling']['samples'] as $sample) {
    foreach (array_slice($outerReport['sampling']['frames'], $sample['first_frame'], $sample['frame_count']) as $id) {
        $symbol = $outerReport['sampling']['symbols'][$id];
        if (preg_match('/^\{sample-closure#[0-9]+\}$/D', $symbol) === 1) {
            $closureSamples[$symbol] = ($closureSamples[$symbol] ?? 0) + 1;
        }
    }
}
sampling_nested_check(
    count($closureSamples) === 2 && min($closureSamples) > 0,
    'nested sampled start reset live closure identities',
);
Profiler::resetAll();
Profiler::disable();
echo "nested sampled sessions share one worker and preserve independent sealed reports passed\n";
