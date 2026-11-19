<?php

declare(strict_types=1);

// Exact Rust ownership check when Zend bails out allocating a clone error.
require_once __DIR__ . '/lib/ExceptionFixture.php';
require_once __DIR__ . '/lib/Json.php';

use PhpProfiler\Tooling\ExceptionFixture;
use PhpProfiler\Tooling\Json;
use PhpProfiler\Tooling\Process;

chdir(dirname(__DIR__));
$php = (string) getenv('PHP') ?: 'php';
putenv('PROFILER_TEST_EXCEPTION_BAILOUT');
if (PHP_OS_FAMILY !== 'Darwin') {
    // The executable defines zend_throw_error, so ELF preloading cannot reliably
    // interpose calls. Wrap only the auxiliary module's references at link time.
    putenv('PROFILER_TEST_CLONE_BAILOUT=1');
}
$fixture = ExceptionFixture::path();
$directory = ExceptionFixture::temporaryDirectory('profiler-clone-ownership-');
$environment = [...getenv(), ...ExceptionFixture::interposer('tests/native/clone-bailout.c', $directory)];
$countsPath = "{$directory}/counts.json";
$result = Process::run(
    [$php, '-n', '-d', 'extension=' . $fixture, 'tests/native/clone-bailout.php', $countsPath],
    $environment,
    timeout: 30,
);
$output = $result['stdout'] . $result['stderr'];
if (
    $result['status'] !== 255
    || !str_contains($output, 'BEFORE_CLONE')
    || !str_contains($output, 'CLONE_ALLOCATION_BAILOUT')
    || str_contains($output, 'AFTER_CLONE')
    || str_contains($output, 'CAUGHT_FATAL')
    || !file_exists($countsPath)
) {
    ExceptionFixture::fail('clone fatal semantics failed exit=' . json_encode($result['status']) . ":\n{$output}");
}
$counts = json_decode((string) file_get_contents($countsPath), true, flags: JSON_THROW_ON_ERROR);
echo 'clone bailout Rust ownership: ', Json::encode($counts, sortKeys: false), "\n";
if ($counts['before'] !== $counts['after']) {
    ExceptionFixture::fail('owned Rust allocation survived clone bailout: ' . Json::encode($counts, sortKeys: false));
}
