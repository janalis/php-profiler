<?php

declare(strict_types=1);

// Bounds raw INI metadata in a separate PHP process with OPcache present.
require_once __DIR__ . '/lib/Fail.php';
require_once __DIR__ . '/lib/Process.php';

use PhpProfiler\Tooling\Process;

use function PhpProfiler\Tooling\requiredEnvironment;

$environment = Process::childEnvironment();
$base = [(string) getenv('PHP') ?: 'php', '-n'];
if (Process::run([...$base, '-r', "exit(extension_loaded('Zend OPcache') ? 0 : 1);"], $environment)['status'] !== 0) {
    array_push($base, '-d', 'zend_extension=opcache');
}
Process::check([
    ...$base,
    '-d',
    'extension=' . requiredEnvironment('PROFILER_EXTENSION'),
    'tests/native/metadata-bounds.php',
], $environment);
