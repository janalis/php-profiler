<?php

declare(strict_types=1);

use PhpProfiler\Profiler;

// Identical source line/name, distinct opcode bodies and identities.
$one = static function (int $delay): void {
    usleep($delay);
};
$two = static function (int $delay): void {
    usleep($delay + 1);
};
$session = Profiler::start();
$one(1);
$two(1);
$one(2);
$two(2);
$session->stop();
$report = $session->getReport();
$closures = array_filter(
    $report['functions'],
    static fn(array $metrics, string $name): bool => str_starts_with($name, '{closure#'),
    ARRAY_FILTER_USE_BOTH,
);
if (count($closures) !== 2 || array_column(array_values($closures), 'calls') !== [2, 2]) {
    throw new RuntimeException('same-line closure sites collapsed into one identity');
}
Profiler::resetAll();
Profiler::disable();
echo "same-line closures retain distinct compiled-site cache identities passed\n";
