<?php

declare(strict_types=1);

// Exercises patched upstream exception semantics and actual Zend bailout ownership.
require_once __DIR__ . '/lib/ExceptionFixture.php';
require_once __DIR__ . '/lib/Json.php';

use PhpProfiler\Tooling\ExceptionFixture;
use PhpProfiler\Tooling\Json;
use PhpProfiler\Tooling\Process;

chdir(dirname(__DIR__));
$php = (string) getenv('PHP') ?: 'php';
$command = [$php, '-n', '-d', 'extension=' . ExceptionFixture::path()];
Process::check([...$command, 'tests/native/vendored-exception.php']);
foreach ([6, 7] as $case) {
    $directory = ExceptionFixture::temporaryDirectory('profiler-exception-ownership-');
    $path = "{$directory}/allocations.json";
    $result = Process::run([
        ...$command,
        'tests/native/vendored-exception-oom.php',
        $path,
        (string) $case,
    ], timeout: 30);
    $output = $result['stdout'] . $result['stderr'];
    if (
        $result['status'] !== 255
        || !str_contains($output, 'BEFORE_EXCEPTION')
        || !str_contains($output, 'Allowed memory size')
        || str_contains($output, 'AFTER_EXCEPTION')
        || str_contains($output, 'CAUGHT_FATAL')
        || !file_exists($path)
    ) {
        ExceptionFixture::fail(
            "exception OOM case{$case} changed fatal/shutdown semantics exit="
            . json_encode($result['status'])
            . ":\n{$output}",
        );
    }
    $counts = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    // The same fallback path primed cleanup bookkeeping before the baseline.
    // Every Rust-owned error, class, message and fallback allocation must be released.
    if ($counts['after'] !== $counts['before']) {
        ExceptionFixture::fail(
            'Rust exception ownership leaked across Zend bailout: ' . Json::encode($counts, sortKeys: false),
        );
    }
    echo "vendored exception OOM case{$case} Rust ownership reclaimed: ", Json::encode($counts, sortKeys: false), "\n";
}
