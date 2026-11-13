<?php

declare(strict_types=1);

use PhpProfiler\PersistenceException;
use PhpProfiler\Profiler;

$restore = null;
if (is_writable('/')) {
    if (!function_exists('posix_geteuid') || !function_exists('posix_seteuid') || posix_geteuid() !== 0) {
        throw new RuntimeException('root directory fixture needs a real unprivileged effective UID');
    }
    $restore = posix_geteuid();
    if (!posix_seteuid(65534))
        throw new RuntimeException('cannot drop effective UID');
}
try {
    clearstatcache();
    // PHP is_writable/access uses the real UID; open/create uses the effective UID.
    if ($restore === null && is_writable('/'))
        throw new RuntimeException('root is writable by test identity');
    if ($restore !== null && (posix_geteuid() !== 65534 || fileowner('/') === 65534 || (fileperms('/') & 0022) !== 0)) {
        throw new RuntimeException('root permissions do not establish an unprivileged write denial');
    }
    $session = Profiler::start('/');
    $rejected = false;
    try {
        $session->stop();
    } catch (PersistenceException $error) {
        if (!str_contains($error->getMessage(), 'Cannot persist profile'))
            throw $error;
        $rejected = true;
    }
    if (!$rejected || $session->getReportPath() !== null || $session->getReport()['status'] !== 'complete') {
        throw new RuntimeException('root permission denial lost report or published a file');
    }
    Profiler::resetAll();
} finally {
    if ($restore !== null && !posix_seteuid($restore))
        throw new RuntimeException('cannot restore effective UID before profile flush');
}
echo "root trailing separator and genuine OS permission denial preserve report\n";
