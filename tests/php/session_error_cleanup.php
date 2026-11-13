<?php

declare(strict_types=1);

use PhpProfiler\PersistenceException;
use PhpProfiler\Profiler;

function stopped_session_deep_call(int $depth): void
{
    if ($depth > 0) {
        stopped_session_deep_call($depth - 1);
    }
}

$directory = sys_get_temp_dir() . '/profiler-stop-error-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$path = $directory . '/existing.json';
file_put_contents($path, 'original file');
try {
    $failedWrite = Profiler::start($path);
    usleep(1);
    try {
        $failedWrite->stop();
        throw new RuntimeException('expected persistence failure');
    } catch (PersistenceException $expected) {
    }
    $original = $failedWrite->getReport();
    // Profiling must already be inactive even though stop returned a write error.
    stopped_session_deep_call(9000);
    $next = Profiler::start();
    usleep(1);
    $next->stop();
    if ($next->getReport()['status'] !== 'complete') {
        throw new RuntimeException('failed persistence left active callbacks poisoning the next session');
    }
    $next->reset();
    if (Profiler::getReport() != $original) {
        throw new RuntimeException('reset cleared owner context needed by a retained report');
    }
    if (file_get_contents($path) !== 'original file') {
        throw new RuntimeException('persistence error modified existing file');
    }
    $failedWrite->reset();
    echo "failed persistence capture cleanup and retained selection passed\n";
} finally {
    Profiler::resetAll();
    unlink($path);
    rmdir($directory);
}
