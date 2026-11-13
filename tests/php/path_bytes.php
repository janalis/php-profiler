<?php

declare(strict_types=1);

use PhpProfiler\Profiler;

$previous = getcwd();
$directory =
    sys_get_temp_dir() . '/php-profiler-path-bytes-' . getmypid() . (PHP_OS_FAMILY === 'Linux' ? "-\xff" : '-é');
if (!mkdir($directory, 0700)) {
    throw new RuntimeException('cannot create path fixture');
}
try {
    if (!chdir($directory)) {
        throw new RuntimeException('cannot enter path fixture');
    }
    $expected = getcwd() . '/profile.json';
    $session = Profiler::start('profile.json');
    usleep(1);
    $session->stop();
    $path = $session->getReportPath();
    if ($path !== $expected || !is_file($path)) {
        throw new RuntimeException('relative PHP working directory or filesystem path bytes changed');
    }
    unlink($path);
    Profiler::resetAll();
    echo "PHP virtual working directory and path bytes passed\n";
} finally {
    chdir($previous);
    foreach (glob($directory . '/*') as $path) {
        unlink($path);
    }
    rmdir($directory);
}
