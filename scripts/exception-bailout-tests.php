<?php

declare(strict_types=1);

// Deterministic exception-allocation bailout ownership, separate from natural OOM.
require_once __DIR__ . '/lib/ExceptionFixture.php';
require_once __DIR__ . '/lib/Json.php';

use PhpProfiler\Tooling\ExceptionFixture;
use PhpProfiler\Tooling\Json;
use PhpProfiler\Tooling\Process;

chdir(dirname(__DIR__));
$php = (string) getenv('PHP') ?: 'php';
putenv('PROFILER_TEST_CLONE_BAILOUT');
if (PHP_OS_FAMILY !== 'Darwin') {
    putenv('PROFILER_TEST_EXCEPTION_BAILOUT=1');
}
$fixture = ExceptionFixture::path();
$directory = ExceptionFixture::temporaryDirectory('profiler-exception-bailout-');
$environment = [
    ...Process::environment(),
    ...ExceptionFixture::interposer('tests/native/exception-bailout.c', $directory),
];
foreach ([6, 7] as $case) {
    $countsPath = "{$directory}/counts-{$case}.json";
    $result = Process::run(
        [
            $php,
            '-n',
            '-d',
            'memory_limit=-1',
            '-d',
            'extension=' . $fixture,
            'tests/native/exception-bailout.php',
            $countsPath,
            (string) $case,
        ],
        $environment,
        timeout: 30,
    );
    $output = $result['stdout'] . $result['stderr'];
    if (
        $result['status'] !== 255
        || !str_contains($output, 'BEFORE_EXCEPTION')
        || !str_contains($output, 'EXCEPTION_ALLOCATION_BAILOUT')
        || str_contains($output, 'AFTER_EXCEPTION')
        || str_contains($output, 'CAUGHT_FATAL')
        || !file_exists($countsPath)
    ) {
        ExceptionFixture::fail(
            "exception bailout case{$case} fatal semantics failed exit="
            . json_encode($result['status'])
            . ":\n{$output}",
        );
    }
    $counts = json_decode((string) file_get_contents($countsPath), true, flags: JSON_THROW_ON_ERROR);
    echo "exception injected bailout case{$case} Rust ownership: ", Json::encode($counts, sortKeys: false), "\n";
    if ($counts['before'] !== $counts['after']) {
        ExceptionFixture::fail(
            'Rust exception ownership survived injected bailout: ' . Json::encode($counts, sortKeys: false),
        );
    }
}
