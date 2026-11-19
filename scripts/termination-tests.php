<?php

declare(strict_types=1);

// Compares real exit and exception behaviour and verifies shutdown profile finalization.
require_once __DIR__ . '/lib/ExceptionFixture.php';
require_once __DIR__ . '/lib/Fail.php';
require_once __DIR__ . '/lib/Process.php';

use PhpProfiler\Tooling\ExceptionFixture;
use PhpProfiler\Tooling\Process;

use function PhpProfiler\Tooling\fail;
use function PhpProfiler\Tooling\requiredEnvironment;

$extension = requiredEnvironment('PROFILER_EXTENSION');
$environment = Process::childEnvironment();
$fixture = dirname(__DIR__) . '/tests/fixtures/termination.php';
$directory = ExceptionFixture::temporaryDirectory('php-profiler-termination-');
foreach (['normal' => 0, 'exit' => 7, 'exception' => 255] as $mode => $expected) {
    $path = "{$directory}/{$mode}.json";
    $baseline = Process::run(['php', '-n', $fixture, $path, $mode], $environment);
    $profiled = Process::run(['php', '-n', '-d', 'extension=' . $extension, $fixture, $path, $mode], $environment);
    if ($baseline['status'] !== $expected || $profiled['status'] !== $baseline['status']) {
        fail(
            "{$mode}: exit semantics changed: " . json_encode($baseline['status']) . '/'
                . json_encode($profiled['status']),
        );
    }
    if ($profiled['stdout'] !== $baseline['stdout'] || $profiled['stderr'] !== $baseline['stderr']) {
        fail("{$mode}: application output changed");
    }
    $report = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    if ($report['functions']['termination_leaf']['calls'] !== 1) {
        fail("{$mode}: shutdown lost call");
    }
}
echo "normal/exit/uncaught exception semantics and shutdown persistence passed\n";
