<?php

declare(strict_types=1);

use PhpProfiler\Profiler;

function tracking_leaf(): void
{
    usleep(1);
}

function tracking_unprofiled_fibers(): void
{
    $finished = new Fiber(static function (): void {});
    $finished->start();
    unset($finished);
    $suspended = new Fiber(static function (): void {
        Fiber::suspend();
    });
    $suspended->start();
    unset($suspended);
}

$initial = profiler_test_fiber_callbacks();
if ($initial['tracking']) {
    throw new RuntimeException('fresh request tracks nonexistent owners');
}
tracking_unprofiled_fibers();
if (profiler_test_fiber_callbacks() !== $initial) {
    throw new RuntimeException('never-started profiling invoked inactive Rust Fiber callbacks');
}
$retained = Profiler::start();
if (!profiler_test_fiber_callbacks()['tracking']) {
    throw new RuntimeException('active session did not retain owner tracking');
}
$active = new Fiber('tracking_leaf');
$active->start();
unset($active);
$measured = profiler_test_fiber_callbacks();
if ($measured['measured'] !== ($initial['measured'] + 1) || $measured['cleanup'] !== $initial['cleanup']) {
    throw new RuntimeException('active Fiber destruction did not delegate real measurements');
}
$retained->stop();
$before = $retained->getReportJson();
$owner = null;
$fiber = new Fiber(static function () use (&$owner): void {
    $owner = Profiler::start();
    tracking_leaf();
    $owner->stop();
});
$fiber->start();
$ownerJson = $owner->getReportJson();
unset($fiber);
$cleaned = profiler_test_fiber_callbacks();
if (
    !$cleaned['tracking']
    || $cleaned['cleanup'] !== ($measured['cleanup'] + 1)
    || $cleaned['measured'] !== $measured['measured']
    || $owner->getReportJson() !== $ownerJson
    || $retained->getReportJson() !== $before
) {
    throw new RuntimeException('inactive tracked owner destruction sampled or changed sealed reports');
}
$owner->reset();
$retained->reset();
$reset = profiler_test_fiber_callbacks();
if ($reset['tracking']) {
    throw new RuntimeException('last owner reset left inactive tracking enabled');
}
tracking_unprofiled_fibers();
Profiler::resetAll();
Profiler::disable();
if (profiler_test_fiber_callbacks() !== $reset) {
    throw new RuntimeException('reset lifecycle continued invoking inactive Rust callbacks');
}
echo "Fiber tracking bypasses inactive callbacks, preserves tracked owners, and measures active destruction\n";
