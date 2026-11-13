<?php

use PhpProfiler\Profiler;

function opcode_visibility_dynamic(callable $function, string $value): int
{
    return $function($value);
}

$value = str_repeat('x', 23);
$direct = Profiler::start();
$directLength = strlen($value);
$direct->stop();
$directReport = $direct->getReport();
$direct->reset();

$dynamic = Profiler::start();
$dynamicLength = opcode_visibility_dynamic('strlen', $value);
$dynamic->stop();
$dynamicReport = $dynamic->getReport();
$dynamic->reset();

if (
    $directLength !== 23
    || $dynamicLength !== 23
    || isset($directReport['functions']['strlen'])
    || ($dynamicReport['functions']['strlen']['calls'] ?? 0) !== 1
    || $directReport['status'] !== 'complete'
    || $dynamicReport['status'] !== 'complete'
    || $directReport['dropped_calls'] !== 0
    || $dynamicReport['dropped_calls'] !== 0
) {
    throw new RuntimeException('observer-visible builtin opcode boundary changed');
}
echo "native call observed; specialized strlen opcode remains outside call graph\n";
