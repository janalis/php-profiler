<?php

declare(strict_types=1);

// Runtime dl() must fail before profiler class and observer registration.
require_once __DIR__ . '/lib/Fail.php';
require_once __DIR__ . '/lib/Paths.php';
require_once __DIR__ . '/lib/Process.php';

use PhpProfiler\Tooling\Paths;
use PhpProfiler\Tooling\Process;

use function PhpProfiler\Tooling\fail;
use function PhpProfiler\Tooling\requiredEnvironment;

chdir(dirname(__DIR__));
$php = (string) getenv('PHP') ?: 'php';
$extension = Paths::resolve(requiredEnvironment('PROFILER_EXTENSION'));
$result = Process::run([
    $php,
    '-n',
    '-d',
    'enable_dl=1',
    '-d',
    'extension_dir=' . dirname($extension),
    'tests/native/runtime-dl.php',
    basename($extension),
], timeout: 30);
$output = $result['stdout'] . $result['stderr'];
if (
    $result['status'] !== 255
    || !str_contains($output, 'runtime dl rejected before class registration')
    || !str_contains($output, 'Unable to start php_profiler module')
    || str_contains($output, 'AFTER_DL_UNEXPECTED')
) {
    fail('runtime module startup did not reject safely: ' . $output);
}
// The same artifact still loads normally at process startup in a fresh process.
$code = 'use PhpProfiler\Profiler; $s=Profiler::start(); usleep(1); $s->stop(); if(($s->getReport()["functions"]["usleep"]["calls"]??0)!==1)throw new RuntimeException("permanent startup failed"); echo "permanent startup after rejected dl passed\n";';
Process::check([$php, '-n', '-d', 'extension=' . $extension, '-r', $code], timeout: 30);
echo "runtime dl rejected with native fatal 255 before registration; shutdown PHP remains valid\n";
