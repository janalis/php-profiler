<?php

declare(strict_types=1);

use PhpProfiler\Profiler;

ini_set('memory_limit', '512M');
$mode = $argv[1] ?? 'named';
if (!in_array($mode, ['named', 'generated', 'classes'], true)) {
    throw new RuntimeException('unknown large-profile scenario');
}
$callables = [];
for ($index = 0; $index < 8192; ++$index) {
    if ($mode === 'generated') {
        $callables[] = eval('return static function (): int { return 7; };');
    } elseif ($mode === 'classes') {
        $callables[] = [eval('return new class { public function run(): int { return 7; } };'), 'run'];
    } else {
        $name = str_pad('large_profile_fn_' . $index . '_', 64, 'x');
        eval('function ' . $name . '(): int { return 7; }');
        $callables[] = $name;
    }
}
$directory = sys_get_temp_dir() . '/php-profiler-large-' . bin2hex(random_bytes(8));
if (!mkdir($directory, 0700)) {
    throw new RuntimeException('cannot create private report directory');
}
$directory = realpath($directory);
$path = $directory . '/report.json';
try {
    $session = Profiler::start($path, ['max_events' => 8192, 'timeline' => false]);
    $sum = 0;
    foreach ($callables as $callable) {
        $sum += $callable();
    }
    $sum += $callables[0](); // A full symbol table must still aggregate a known callable.
    $session->stop();
    $report = $session->getReport();
    if (
        $sum !== (8193 * 7)
        || $report['status'] !== 'complete'
        || count($report['functions']) !== 8192
        || count($report['edges']) !== 8192
        || $report['dropped_calls'] !== 0
        || !Profiler::isEnabled()
    ) {
        throw new RuntimeException('large profile lost complete attribution or changed application semantics');
    }
    $calls = array_sum(array_column($report['functions'], 'calls'));
    $first = match ($mode) {
        'generated' => '{closure#1}',
        'classes' => 'anonymous-class#1::run',
        default => $callables[0],
    };
    if ($calls !== 8193 || $report['functions'][$first]['calls'] !== 2) {
        throw new RuntimeException('full identity storage stopped aggregating existing calls');
    }
    if (
        $session->getReportPath() !== $path
        || json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) !== $report
        || json_decode($session->getReportJson(), true, 512, JSON_THROW_ON_ERROR) !== $report
    ) {
        throw new RuntimeException('large persisted/accessor reports disagree');
    }
    $session->reset();
    // The default stays small; a larger graph is explicitly requested above.
    $default = Profiler::start();
    foreach ($callables as $callable) {
        $callable();
    }
    $default->stop();
    $limited = $default->getReport();
    if (
        $limited['status'] !== 'truncated'
        || count($limited['functions']) !== 512
        || $limited['dropped_calls'] !== (8192 - 512)
    ) {
        throw new RuntimeException('default capture bound changed');
    }
} finally {
    Profiler::resetAll();
    Profiler::disable();
    if (is_file($path)) {
        unlink($path);
    }
    rmdir($directory);
}
echo $mode . ": 8192-symbol graphs retain repeated calls and local JSON; default remains bounded\n";
