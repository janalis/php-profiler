<?php

declare(strict_types=1);

use PhpProfiler\Profiler;
use PhpProfiler\StateException;

// Dedicated heavyweight CLI fixture: PHP-owned code/decoded JSON is outside
// the native requested-capacity ledger. Keep it out of repeated mutation runs.
ini_set('memory_limit', '1G');
$names = [];
for ($index = 0; $index < 8192; ++$index) {
    $names[] = str_pad('boundary_' . $index . '_', 1021, 'x');
}
$source = 'class P {';
foreach ($names as $index => $name) {
    $body = $index === 8191 ? 'return 1;' : 'return 1 + self::' . $names[$index + 1] . '();';
    $source .= 'public static function ' . $name . '(): int {' . $body . '}';
}
eval($source . '}');
unset($source, $body, $name);
$first = $names[0];
$directory = sys_get_temp_dir() . '/php-profiler-boundary-' . bin2hex(random_bytes(8));
if (!mkdir($directory, 0700)) {
    throw new RuntimeException('cannot create private report directory');
}
$directory = realpath($directory);
$path = $directory . '/report.json';
try {
    $session = Profiler::start($path, ['max_events' => 8192, 'timeline' => true]);
    $value = P::$first();
    $session->stop();
    if ($value !== 8192 || $session->getReportPath() !== $path || !is_file($path)) {
        throw new RuntimeException('deep capture changed execution or did not publish its report');
    }
    $size = filesize($path);
    if ($size <= (16 * 1024 * 1024)) {
        throw new RuntimeException('fixture did not cross accessor size boundary');
    }
    foreach (['getReport', 'getReportJson'] as $accessor) {
        try {
            $session->$accessor();
            throw new RuntimeException($accessor . ' accepted an oversized report');
        } catch (StateException $expected) {
            if (!str_contains($expected->getMessage(), '16 MiB PHP accessor limit')) {
                throw new RuntimeException($accessor . ' failed for an unexpected reason', 0, $expected);
            }
        }
        if ($session->getReportPath() !== $path || filesize($path) !== $size) {
            throw new RuntimeException('accessor refusal changed the published report');
        }
    }
    $report = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (
        $report['status'] !== 'complete'
        || !$report['timeline_enabled']
        || count($report['functions']) !== 8192
        || count($report['edges']) !== 8192
        || count($report['timeline']) !== 8192
        || $report['dropped_calls'] !== 0
        || $report['dropped_timeline_events'] !== 0
        || $report['unmatched_exits'] !== 0
        || $report['metric_overflows'] !== 0
        || $report['clock_regressions'] !== 0
    ) {
        throw new RuntimeException('deep boundary profile lost complete graph or timeline attribution');
    }
    $edges = [];
    foreach ($report['edges'] as $edge) {
        $edges[$edge['callee']] = $edge;
    }
    $events = [];
    foreach ($report['timeline'] as $event) {
        $events[$event['symbol']] = $event;
    }
    foreach ($names as $index => $name) {
        $symbol = 'P::' . $name;
        $metrics = $report['functions'][$symbol];
        $edge = $edges[$symbol];
        $event = $events[$symbol];
        $caller = $index === 0 ? '{session}' : 'P::' . $names[$index - 1];
        if (
            strlen($symbol) !== 1024
            || $metrics['calls'] !== 1
            || $metrics['exceptions'] !== 0
            || $metrics['inclusive_wall_ns'] < $metrics['exclusive_wall_ns']
            || $metrics['inclusive_cpu_ns'] < $metrics['exclusive_cpu_ns']
            || $edge['caller'] !== $caller
            || $edge['metrics'] !== $metrics
            || !$event['completed']
            || $event['outcome'] !== 'return'
            || $event['active_wall_ns'] !== $metrics['inclusive_wall_ns']
            || $event['active_cpu_ns'] !== $metrics['inclusive_cpu_ns']
            || $event['suspended_wall_ns'] !== 0
            || $event['start_ns'] > $event['end_ns']
        ) {
            throw new RuntimeException('deep graph identity, measurements or return outcome disagree');
        }
        if ($index === 0) {
            if ($event['parent_frame_id'] !== null) {
                throw new RuntimeException('root event has a parent');
            }
        } else {
            $parent = $events[$caller];
            if (
                $event['parent_frame_id'] !== $parent['frame_id']
                || $event['context_id'] !== $parent['context_id']
                || $event['start_ns'] < $parent['start_ns']
                || $event['end_ns'] > $parent['end_ns']
            ) {
                throw new RuntimeException('deep timeline nesting changed');
            }
        }
    }
    if (scandir($directory) !== ['.', '..', 'report.json']) {
        throw new RuntimeException('atomic report publication left temporary files');
    }
    echo 'deep8192: complete 1024-byte symbols, exact graph/timeline and oversized local JSON (' . $size . " bytes)\n";
} finally {
    Profiler::resetAll();
    Profiler::disable();
    if (is_file($path)) {
        unlink($path);
    }
    rmdir($directory);
}
