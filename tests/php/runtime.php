<?php

declare(strict_types=1);

use PhpProfiler\Profiler;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function profiler_test_leaf(): int
{
    return strlen(str_repeat('a', 23));
}

function profiler_test_recursive(int $depth): int
{
    return $depth === 0 ? profiler_test_leaf() : profiler_test_recursive($depth - 1);
}

$directory = sys_get_temp_dir() . '/rust-profiler-test-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$directory = realpath($directory);
$path = $directory . '/profile.json';
try {
    $id = Profiler::start($path);
    check(profiler_test_recursive(3) === 23, 'execution changed');
    try {
        throw new LogicException('expected');
    } catch (LogicException $exception) {
        check($exception->getMessage() === 'expected', 'exception changed');
    }
    $fiber = new Fiber(function (): void {
        profiler_test_leaf();
        Fiber::suspend();
        profiler_test_leaf();
    });
    $fiber->start();
    profiler_test_leaf();
    $fiber->resume();
    Profiler::stop($id);
    $report = Profiler::getReport($id);
    check(is_array($report) && $report !== [], 'missing structured report');
    check($report['schema_version'] === 1, 'unexpected schema');
    check($report['status'] === 'complete', 'fiber accounting must complete');
    check($report['functions']['profiler_test_recursive']['calls'] === 4, 'incorrect recursive call count');
    check($report['elapsed_wall_ns'] >= ($report['thread_cpu_ns'] / 10), 'implausible clock units');
    $json = Profiler::getReportJson($id);
    check(json_decode($json, true, 512, JSON_THROW_ON_ERROR) == $report, 'JSON differs from report');
    check(Profiler::getReportPath($id) === $path, 'wrong report path');
    check(is_file($path), 'report was not persisted');
    check(json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) == $report, 'persisted report differs');
    check(str_contains($json, 'profiler_test_leaf'), 'leaf instrumentation missing');
    check(str_contains($json, 'profiler_test_recursive'), 'recursive instrumentation missing');
    $second = Profiler::start();
    profiler_test_leaf();
    Profiler::stop($second);
    check($id->getId() > 0 && $second->getId() > $id->getId(), 'session IDs must be positive, unique and increasing');
    check(Profiler::getReport($id) === $report, 'completed session changed');
    Profiler::reset($second);
    Profiler::disable();
    echo "runtime integration passed\n";
} finally {
    Profiler::disable();
    foreach (glob($directory . '/*') as $file) {
        unlink($file);
    }
    rmdir($directory);
}
