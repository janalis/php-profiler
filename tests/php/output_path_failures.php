<?php

declare(strict_types=1);

use PhpProfiler\PersistenceException;
use PhpProfiler\Profiler;

$session = Profiler::start(str_repeat('x', 4096));
try {
    $session->stop();
    throw new RuntimeException('unresolvable path was accepted');
} catch (PersistenceException $error) {
    if (!str_contains(strtolower($error->getMessage()), 'resolve')) {
        throw new RuntimeException('unresolvable path reached filesystem publication');
    }
}
if ($session->getReportPath() !== null || $session->getReportJson() === '') {
    throw new RuntimeException('resolution failure lost the in-memory report');
}
$session->reset();
$missing = sys_get_temp_dir() . '/profiler-missing-' . bin2hex(random_bytes(8));
$session = Profiler::start($missing . '/report.json');
try {
    $session->stop();
    throw new RuntimeException('missing output directory was created');
} catch (PersistenceException $error) {
    if (!str_contains(strtolower($error->getMessage()), 'persist')) {
        throw new RuntimeException('filesystem publication failure was misclassified');
    }
}
if (is_dir($missing) || $session->getReportPath() !== null || $session->getReportJson() === '') {
    throw new RuntimeException('publication failure changed filesystem or lost report');
}
$session->reset();
echo "path resolution and filesystem errors remain distinct and recoverable\n";
