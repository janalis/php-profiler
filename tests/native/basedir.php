<?php

declare(strict_types=1);

use PhpProfiler\PersistenceException;
use PhpProfiler\Profiler;

$target = $argv[1];
$allowed = $argv[2];
$mode = $argv[3];
if ($mode === 'relative' && !chdir($allowed)) {
    throw new RuntimeException('chdir failed');
}
$session = Profiler::start($target);
usleep(1);
if ($mode === 'tightening' && ini_set('open_basedir', $allowed) === false) {
    throw new RuntimeException('could not tighten open_basedir');
}
$rejected = false;
try {
    $session->stop();
} catch (PersistenceException $failure) {
    if ($mode === 'parent-denied' && $failure->getMessage() !== 'Output path is blocked by PHP open_basedir')
        throw $failure;
    $rejected = true;
}
if ($rejected !== !in_array($mode, ['allowed', 'relative'], true)) {
    throw new RuntimeException('open_basedir policy mismatch');
}
$report = $session->getReport();
if ($report['functions']['usleep']['calls'] !== 1) {
    throw new RuntimeException('persistence policy lost in-memory report');
}
if ($rejected && $session->getReportPath() !== null) {
    throw new RuntimeException('rejected persistence has a published path');
}
$session->reset();
Profiler::disable();
echo "open_basedir $mode passed\n";
