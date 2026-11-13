<?php

declare(strict_types=1);

use PhpProfiler\PersistenceException;
use PhpProfiler\Profiler;

function retained_session_leaf(): int
{
    return strlen(str_repeat('r', 13));
}

$completed = Profiler::start();
retained_session_leaf();
$completed->stop();
$before = $completed->getReportJson();
$active = Profiler::start();
$fiber = new Fiber(static function (): void {
    retained_session_leaf();
    Fiber::suspend();
    retained_session_leaf();
});
$fiber->start();
$fiber->resume();
unset($fiber);
$active->stop();
if (
    $completed->getReportJson() !== $before
    || $active->getReport()['status'] !== 'complete'
    || $active->getReport()['functions']['retained_session_leaf']['calls'] !== 2
) {
    throw new RuntimeException('fiber lifecycle changed an already completed session');
}
Profiler::resetAll();
$directory = sys_get_temp_dir() . '/profiler-disable-error-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$path = $directory . '/existing.json';
file_put_contents($path, 'original');
try {
    $unwritable = Profiler::start($path);
    $other = Profiler::start();
    retained_session_leaf();
    try {
        Profiler::disable();
        throw new RuntimeException('disable must report the persistence error');
    } catch (PersistenceException $expected) {
    }
    if (
        Profiler::isEnabled()
        || $unwritable->getReport()['status'] !== 'complete'
        || $other->getReport()['status'] !== 'complete'
        || file_get_contents($path) !== 'original'
    ) {
        throw new RuntimeException('disable failed to finish independent sessions after a write error');
    }
    $next = Profiler::start();
    retained_session_leaf();
    $next->stop();
    if ($next->getReport()['status'] !== 'complete') {
        throw new RuntimeException('disable persistence error poisoned the next job');
    }
} finally {
    Profiler::resetAll();
    Profiler::disable();
    unlink($path);
    rmdir($directory);
}
echo "retained completed sessions survive fibers and disable errors finish independent sessions\n";
