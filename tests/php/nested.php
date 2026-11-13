<?php

declare(strict_types=1);

use PhpProfiler\Profiler;

function nested_leaf(): void
{
    usleep(100);
}

$outer = Profiler::start();
nested_leaf();
$inner = Profiler::start();
nested_leaf();
Profiler::stop();
nested_leaf();
Profiler::stop();
foreach ([$outer, $inner] as $id) {
    if (!str_contains(Profiler::getReportJson($id), 'nested_leaf')) {
        throw new RuntimeException('nested session missed calls');
    }
}
$outerReport = Profiler::getReport($outer);
$innerReport = Profiler::getReport($inner);
if (
    $outerReport['functions']['nested_leaf']['calls'] !== 3
    || $innerReport['functions']['nested_leaf']['calls'] !== 1
) {
    throw new RuntimeException('independent nested call counts incorrect');
}
Profiler::disable();
echo "nested lifecycle passed\n";
