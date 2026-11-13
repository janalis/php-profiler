<?php

declare(strict_types=1);

use PhpProfiler\Profiler;

function default_main_leaf(): void
{
    usleep(1);
}

function default_fiber_leaf(): void
{
    usleep(1);
}

$main = Profiler::start();
default_main_leaf();
$main->stop();
$before = $main->getReportJson();
$foreign = null;
$fiber = new Fiber(static function () use (&$foreign): void {
    $foreign = Profiler::start();
    default_fiber_leaf();
    $foreign->stop();
    if (Profiler::getReport()['functions']['default_fiber_leaf']['calls'] !== 1) {
        throw new RuntimeException('fiber default selected another context session');
    }
    Fiber::suspend();
});
$fiber->start();
if (Profiler::getReportJson() !== $before || $foreign->getReport()['functions']['default_fiber_leaf']['calls'] !== 1) {
    throw new RuntimeException('later foreign session changed main-context default selection');
}
$fiber->resume();
unset($fiber);
if (Profiler::getReportJson() !== $before) {
    throw new RuntimeException('destroyed foreign context changed retained main default');
}
Profiler::resetAll();
Profiler::disable();
echo "omitted handles select only the caller context despite newer foreign sessions\n";
