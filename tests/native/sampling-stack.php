<?php

declare(strict_types=1);

use PhpProfiler\Profiler;

function sampling_stack_check(bool $condition, string $message): void
{
    if (!$condition)
        throw new RuntimeException($message);
}

$expectedSampling = profiler_test_sampling_status()['expected_supported'];
if (Profiler::isSamplingSupported() !== $expectedSampling)
    throw new RuntimeException('public capability differs from compiled platform contract');
if (!$expectedSampling) {
    echo "sampling stack ABI unsupported on this platform\n";
    return;
}
function sampled_named_stack(string $privateArgument): int
{
    sampling_stack_check(profiler_test_sampling_service(), 'actual tick not serviced');
    return strlen($privateArgument);
}

final class SamplingStackReceiver
{
    public function capture(): int
    {
        sampling_stack_check(profiler_test_sampling_service(), 'method tick not serviced');
        return 37;
    }
}

function sampled_generator_stack(): Generator
{
    sampling_stack_check(profiler_test_sampling_service(), 'generator tick not serviced');
    yield 41;
}

function sampled_delegated_stack(): Generator
{
    yield from sampled_generator_stack();
}

function sampled_outer_stack(): Generator
{
    yield from sampled_delegated_stack();
}

function sampled_delegated_deep_stack(int $depth): Generator
{
    if ($depth > 0) {
        yield from sampled_delegated_deep_stack($depth - 1);
        return;
    }
    sampling_stack_check(profiler_test_sampling_service(), 'deep delegated tick not serviced');
    yield 59;
}

function sampled_empty_marker(): Generator
{
    if (false)
        yield 1;
}

function sampled_deep_stack(int $depth): void
{
    if ($depth > 0) {
        sampled_deep_stack($depth - 1);
        return;
    }
    sampling_stack_check(profiler_test_sampling_service(), 'deep tick not serviced');
}

sampling_stack_check(profiler_test_sampling_blank_generator(), 'genuine Zend-created blank marker classification');
$unstartedMarker = sampled_empty_marker();
$marker = profiler_test_sampling_generator_marker($unstartedMarker);
sampling_stack_check(
    $marker['count'] === 0 && $marker['truncated'] && !$marker['closed'],
    'unstarted engine marker classification',
);
$unstartedMarker->next();
$marker = profiler_test_sampling_generator_marker($unstartedMarker);
sampling_stack_check(
    $marker['count'] === 0 && $marker['truncated'] && $marker['closed'],
    'closed engine marker classification',
);
$secret = 'sampling-private-argument-do-not-collect';
$session = Profiler::start(null, ['sampling' => true, 'timeline' => true]);
sampling_stack_check(sampled_named_stack($secret) === strlen($secret), 'named semantics');
sampling_stack_check((new SamplingStackReceiver())->capture() === 37, 'method semantics');
$closure = static function (): int {
    sampling_stack_check(profiler_test_sampling_service(), 'closure tick not serviced');
    return 43;
};
sampling_stack_check($closure() === 43, 'closure semantics');
$anonymous = new class {
    public function capture(): int
    {
        sampling_stack_check(profiler_test_sampling_service(), 'anonymous tick not serviced');
        return 47;
    }
};
sampling_stack_check($anonymous->capture() === 47, 'anonymous semantics');
sampling_stack_check(iterator_to_array(sampled_generator_stack()) === [41], 'generator semantics');
sampling_stack_check(iterator_to_array(sampled_delegated_stack()) === [41], 'delegated generator semantics');
sampling_stack_check(iterator_to_array(sampled_outer_stack()) === [41], 'nested delegated generator semantics');
sampling_stack_check(iterator_to_array(sampled_delegated_deep_stack(70)) === [59], 'deep delegation semantics');
$fiber = new Fiber(static function (): int {
    sampling_stack_check(profiler_test_sampling_service(), 'fiber tick not serviced');
    return 53;
});
$fiber->start();
sampling_stack_check($fiber->getReturn() === 53, 'fiber semantics');
sampled_deep_stack(70);
$session->stop();
$report = $session->getReport();
$sampling = $report['sampling'];
sampling_stack_check($sampling['captured_samples'] >= 10, 'stack observations missing');
foreach ([
    'sampled_named_stack',
    'SamplingStackReceiver::capture',
    'sampled_generator_stack',
    'sampled_delegated_stack',
    'sampled_outer_stack',
    'profiler_test_sampling_service',
] as $name) {
    sampling_stack_check(in_array($name, $sampling['symbols'], true), 'missing stack symbol: ' . $name);
}
$symbols = implode("\n", $sampling['symbols']);
sampling_stack_check(str_contains($symbols, '{sample-closure#'), 'closure privacy identity missing');
sampling_stack_check(str_contains($symbols, 'sample-anonymous-class#'), 'anonymous privacy identity missing');
sampling_stack_check($sampling['truncated_stacks'] > 0, 'deep stack truncation not disclosed');
$contexts = [];
$sawDelegatedOrder = false;
$sawDeepDelegation = false;
foreach ($sampling['samples'] as $sample) {
    sampling_stack_check(
        $sample['first_wake_ns'] >= $sample['due_ns']
        && $sample['last_wake_ns'] >= $sample['first_wake_ns']
        && $sample['service_ns'] >= $sample['last_wake_ns'],
        'sample clock ordering',
    );
    $contexts[$sample['context_id']] = true;
    $stack = array_map(
        static fn(int $id): string => $sampling['symbols'][$id],
        array_slice($sampling['frames'], $sample['first_frame'], $sample['frame_count']),
    );
    $leaf = array_search('sampled_generator_stack', $stack, true);
    $middle = array_search('sampled_delegated_stack', $stack, true);
    $outer = array_search('sampled_outer_stack', $stack, true);
    if ($leaf !== false && $middle !== false && $outer !== false) {
        sampling_stack_check($leaf < $middle && $middle < $outer, 'delegated ancestors are not nearest-first');
        $sawDelegatedOrder = true;
    }
    sampling_stack_check($sample['frame_count'] <= 64, 'native stack walk exceeded fixed bound');
    if (in_array('sampled_delegated_deep_stack', $stack, true)) {
        sampling_stack_check(
            $sample['depth_truncated'] && $stack === ['profiler_test_sampling_service', 'sampled_delegated_deep_stack'],
            'bounded delegation invented an edge to an unproven distant ancestor',
        );
        $sawDeepDelegation = true;
    }
}
sampling_stack_check($sawDelegatedOrder, 'full nested delegation stack missing');
sampling_stack_check($sawDeepDelegation, 'deep delegation truncation not independently checked');
sampling_stack_check(count($contexts) >= 2, 'fiber sample context isolation missing');
$json = $session->getReportJson();
sampling_stack_check(
    !str_contains($json, $secret) && !str_contains($json, __FILE__),
    'sampling leaked private payload/path',
);
$session->reset();
Profiler::disable();
echo "real sampling stack ABI/privacy/depth/fiber contracts passed\n";
