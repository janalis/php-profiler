<?php

declare(strict_types=1);

use PhpProfiler\Profiler;

Profiler::resetAll();
$one = function (): void {
    usleep(1);
};
$two = function (): void {
    usleep(1);
};
$object = new class {
    public function run(): void
    {
        usleep(1);
    }
};
function generated_symbol_named(): void
{
    usleep(1);
}

$nativeCallable = Closure::fromCallable('usleep');
$userCallable = Closure::fromCallable('generated_symbol_named');
$session = Profiler::start(null, ['timeline' => true]);
$one();
$one();
$two();
$object->run();
$nativeCallable(1);
$userCallable();
$session->stop();
$json = $session->getReportJson();
if (str_contains($json, __FILE__) || str_contains($json, dirname(__FILE__)) || str_contains($json, 'class@anonymous')) {
    throw new RuntimeException('generated symbol leaks source path');
}
$functions = $session->getReport()['functions'];
if (($functions['{closure#1}']['calls'] ?? 0) !== 2 || ($functions['{closure#2}']['calls'] ?? 0) !== 1) {
    throw new RuntimeException('distinct closure sites collapsed');
}
if (($functions['generated_symbol_named']['calls'] ?? 0) !== 1 || ($functions['usleep']['calls'] ?? 0) !== 6) {
    throw new RuntimeException('callable closure function identity changed');
}
if (($functions['anonymous-class#1::run']['calls'] ?? 0) !== 1) {
    throw new RuntimeException('anonymous class symbol missing');
}
Profiler::resetAll();
echo "generated symbol privacy passed\n";
