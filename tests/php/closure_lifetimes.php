<?php

declare(strict_types=1);

use PhpProfiler\Profiler;

function closure_lifetime_eval(int $value): Closure
{
    return eval('return static function(): int { return ' . $value . '; };');
}

function closure_lifetime_factory(): Closure
{
    return static function (): int {
        return 7;
    };
}

final class ClosureLifetimeScope {}

function closure_lifetime_rows(array $report): array
{
    return array_filter(
        $report['functions'],
        static fn(array $metrics, string $name): bool => preg_match('/(?:^|::)\{closure#\d+\}$/', $name) === 1,
        ARRAY_FILTER_USE_BOTH,
    );
}

$session = Profiler::start();
$checksum = 0;
$retained = null;
for ($i = 0; $i < 40; $i++) {
    $closure = closure_lifetime_eval($i);
    $checksum += $closure() + $closure();
    if ($i === 0) {
        $retained = $closure;
    }
    unset($closure);
}
for ($i = 0; $i < 12; $i++) {
    $closure = closure_lifetime_factory();
    $checksum += $closure() + $closure();
    unset($closure);
}
// A changed binding scope creates its own runtime cache/callable lifetime.
for ($i = 0; $i < 8; $i++) {
    $closure = closure_lifetime_factory()->bindTo(null, ClosureLifetimeScope::class);
    $checksum += $closure() + $closure();
    unset($closure);
}
$session->stop();
$report = $session->getReport();
$rows = closure_lifetime_rows($report);
$counts = array_count_values(array_column(array_values($rows), 'calls'));
if (
    $checksum !== 1840
    || $report['status'] !== 'complete'
    || count($rows) !== 49
    || $counts !== [2 => 48, 24 => 1]
    || array_sum(array_column($rows, 'calls')) !== 120
) {
    throw new RuntimeException(
        'closure cache lifetimes merged or shared compiled sites split: ' . json_encode($counts),
    );
}
Profiler::resetAll();
// Existing cache IDs must survive reset; a new cache at that same eval site
// must not collide with the retained closure's first issued identity.
$next = Profiler::start();
$retained();
$retained();
$fresh = closure_lifetime_eval(999);
if ($fresh() !== 999 || $fresh() !== 999) {
    throw new RuntimeException('closure semantics changed');
}
$next->stop();
$rows = closure_lifetime_rows($next->getReport());
if (count($rows) !== 2 || array_column(array_values($rows), 'calls') !== [2, 2]) {
    throw new RuntimeException('closure identity sequence collided after reset');
}
Profiler::resetAll();
echo "closure lifetime reuse, shared factory caches, scope binding and reset isolation passed\n";
