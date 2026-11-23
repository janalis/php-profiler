<?php

declare(strict_types=1);

namespace PhpProfiler\Tooling;

use JsonException;
use RuntimeException;

/** A report that cannot be exported, with a message for the user. */
final class ReportExportError extends RuntimeException {}

/**
 * Converts a JSON report into formats standard viewers open: Callgrind (KCachegrind,
 * QCacheGrind), Chrome Trace Event JSON (Perfetto, chrome://tracing) and folded stacks
 * (flamegraph.pl, speedscope). Runs on a plain PHP 8.3 CLI with no extension loaded.
 * Values the report marks unavailable are omitted, never printed as zeros.
 */
final class ReportExport
{
    public const FORMATS = ['callgrind', 'chrome-trace', 'collapsed'];

    private const METRICS = [
        'calls',
        'inclusive_wall_ns',
        'exclusive_wall_ns',
        'inclusive_cpu_ns',
        'exclusive_cpu_ns',
        'memory_delta_bytes',
        'exceptions',
    ];

    /** Callgrind events: name => [long name, exclusive metric, inclusive metric]. */
    private const EVENTS = [
        'wall_ns' => ['Wall time (ns)', 'exclusive_wall_ns', 'inclusive_wall_ns'],
        'cpu_ns' => ['Thread CPU time (ns)', 'exclusive_cpu_ns', 'inclusive_cpu_ns'],
    ];

    /**
     * Reads, validates and converts a report file.
     *
     * @return array{output: string, notes: list<string>} notes are for standard error
     */
    public static function exportFile(string $path, string $format): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new ReportExportError("cannot read report: {$path}");
        }
        $contents = @file_get_contents($path);
        if (!is_string($contents)) {
            throw new ReportExportError("cannot read report: {$path}");
        }
        return self::export(self::decode($contents), $format);
    }

    /**
     * Integers beyond PHP's range stay decimal strings, so no count is rounded.
     *
     * @return array<string, mixed>
     */
    public static function decode(string $json): array
    {
        try {
            $report = json_decode($json, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException $error) {
            throw new ReportExportError('the report is not valid JSON: ' . $error->getMessage());
        }
        if (!is_array($report) || $report !== [] && array_is_list($report)) {
            throw new ReportExportError('the report is not a JSON object');
        }
        $version = $report['schema_version'] ?? null;
        if ($version !== 1 && $version !== 2) {
            throw new ReportExportError('unsupported schema_version: expected 1 or 2, got ' . self::show($version));
        }
        self::requireKeys($report, ['status', 'unavailable_metrics'], 'report');
        if (!is_array($report['unavailable_metrics']) || !array_is_list($report['unavailable_metrics'])) {
            throw new ReportExportError('report.unavailable_metrics must be a list');
        }
        foreach ($report['unavailable_metrics'] as $name) {
            if (!is_string($name)) {
                throw new ReportExportError('report.unavailable_metrics must contain strings');
            }
        }
        return $report;
    }

    /**
     * @param array<string, mixed> $report a decoded report
     * @return array{output: string, notes: list<string>}
     */
    public static function export(array $report, string $format): array
    {
        return match ($format) {
            'callgrind' => ['output' => self::callgrind($report), 'notes' => []],
            'chrome-trace' => ['output' => self::chromeTrace($report), 'notes' => []],
            'collapsed' => self::collapsed($report),
            default => throw new ReportExportError(
                "unknown format: {$format}; expected one of " . implode(', ', self::FORMATS),
            ),
        };
    }

    /** Writes through a temporary file in the target directory, so no partial file is ever visible. */
    public static function write(string $path, string $contents): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            throw new ReportExportError("output directory does not exist: {$directory}");
        }
        $temporary = @tempnam($directory, '.export-report-');
        // tempnam() silently falls back to the system directory, where rename() could cross devices.
        if (!is_string($temporary) || realpath(dirname($temporary)) !== realpath($directory)) {
            if (is_string($temporary)) {
                @unlink($temporary);
            }
            throw new ReportExportError("cannot create a temporary file in: {$directory}");
        }
        try {
            if (@file_put_contents($temporary, $contents) !== strlen($contents)) {
                throw new ReportExportError("cannot write: {$temporary}");
            }
            // Keep tempnam()'s 0600: exports carry the report's private program identifiers.
            @chmod($temporary, 0o600);
            if (!@rename($temporary, $path)) {
                throw new ReportExportError("cannot replace: {$path}");
            }
        } finally {
            if (file_exists($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /**
     * Callgrind: each function's exclusive cost is its self cost; each edge is a call with
     * the edge's call count and inclusive cost. The report has no source locations, so all
     * functions share the pseudo file "[php]" at line 0.
     *
     * @param array<string, mixed> $report
     */
    public static function callgrind(array $report): string
    {
        self::requireKeys($report, ['functions', 'edges'], 'report');
        $functions = self::map($report['functions'], 'report.functions');
        $edges = self::list($report['edges'], 'report.edges');
        $unavailable = $report['unavailable_metrics'];
        $events = [];
        foreach (self::EVENTS as $event => [, $exclusive, $inclusive]) {
            if (!in_array($exclusive, $unavailable, true) && !in_array($inclusive, $unavailable, true)) {
                $events[] = $event;
            }
        }
        if ($events === []) {
            throw new ReportExportError('callgrind needs a cost: the report marks both wall and CPU times unavailable');
        }
        $withCalls = !in_array('calls', $unavailable, true);

        $costs = [];
        foreach ($functions as $symbol => $metrics) {
            $costs[(string) $symbol] = self::costs($metrics, $events, 1, "report.functions[{$symbol}]");
        }
        $calls = [];
        foreach ($edges as $index => $edge) {
            $where = "report.edges[{$index}]";
            if (!is_array($edge)) {
                throw new ReportExportError("{$where} must be an object");
            }
            self::requireKeys($edge, ['caller', 'callee', 'metrics'], $where);
            if (!is_string($edge['caller']) || !is_string($edge['callee'])) {
                throw new ReportExportError("{$where}.caller and callee must be strings");
            }
            $metrics = self::map($edge['metrics'], "{$where}.metrics");
            $calls[$edge['caller']][$edge['callee']] = [
                self::count($metrics['calls'] ?? null, "{$where}.metrics.calls"),
                self::costs($metrics, $events, 2, "{$where}.metrics"),
            ];
        }

        $lines = [
            '# callgrind format',
            'version: 1',
            'creator: php-profiler scripts/export-report.php',
            '# Exported from a php-profiler JSON report (schema_version '
                . self::show($report['schema_version'])
                . ', status '
                . self::show($report['status'])
                . ').',
            '# The report has no source locations: every function is in the pseudo file "[php]" at line 0.',
            '# Self cost: functions[*].exclusive_*_ns. Call cost: edges[*].metrics.inclusive_*_ns.',
            '# Call counts: edges[*].metrics.calls. Times include instrumentation overhead.',
        ];
        if (isset($calls['{session}'])) {
            $lines[] = '# {session} is the session root: it calls the functions entered at the top of the session.';
        }
        foreach (self::callgrindNotes($report, $events, $withCalls) as $note) {
            $lines[] = '# ' . $note;
        }
        $lines[] = 'positions: line';
        foreach ($events as $event) {
            $lines[] = "event: {$event} : " . self::EVENTS[$event][0];
        }
        $lines[] = 'events: ' . implode(' ', $events);
        $lines[] = '';
        $lines[] = 'fl=(1) [php]';

        $ids = [];
        $name = static function (string $symbol) use (&$ids): string {
            if (isset($ids[$symbol])) {
                return '(' . $ids[$symbol] . ')';
            }
            $ids[$symbol] = count($ids) + 1;
            return '(' . $ids[$symbol] . ') ' . self::line($symbol);
        };
        $symbols = array_keys($costs + ($withCalls ? $calls : []));
        $symbols = array_map(strval(...), $symbols);
        sort($symbols, SORT_STRING);
        foreach ($symbols as $symbol) {
            $lines[] = 'fn=' . $name($symbol);
            if (isset($costs[$symbol])) {
                $lines[] = '0 ' . implode(' ', $costs[$symbol]);
            }
            if (!$withCalls) {
                continue;
            }
            $callees = $calls[$symbol] ?? [];
            ksort($callees, SORT_STRING);
            foreach ($callees as $callee => [$count, $cost]) {
                $lines[] = 'cfn=' . $name((string) $callee);
                $lines[] = "calls={$count} 0";
                $lines[] = '0 ' . implode(' ', $cost);
            }
        }
        return implode("\n", $lines) . "\n";
    }

    /**
     * Chrome Trace Event JSON from the timeline: one complete ("X") event per record, in
     * microseconds from the earliest record start, with the fiber context as the thread.
     *
     * @param array<string, mixed> $report
     */
    public static function chromeTrace(array $report): string
    {
        self::requireKeys($report, ['timeline_enabled', 'timeline', 'dropped_timeline_events'], 'report');
        if ($report['timeline_enabled'] !== true) {
            throw new ReportExportError(
                "the report has no timeline: start the session with ['timeline' => true], "
                . 'for example Profiler::start($path, [\'timeline\' => true]), or set php_profiler.timeline=1',
            );
        }
        $unavailable = $report['unavailable_metrics'];
        foreach (['timeline.start_ns', 'timeline.end_ns'] as $metric) {
            if (in_array($metric, $unavailable, true)) {
                throw new ReportExportError(
                    "the report marks {$metric} unavailable: timeline timestamps are not valid",
                );
            }
        }
        $records = self::list($report['timeline'], 'report.timeline');
        $required = [
            'frame_id',
            'parent_frame_id',
            'symbol',
            'start_ns',
            'end_ns',
            'completed',
            'context_id',
            'active_wall_ns',
            'active_cpu_ns',
            'suspended_wall_ns',
            'outcome',
        ];
        $origin = null;
        foreach ($records as $index => $record) {
            if (!is_array($record)) {
                throw new ReportExportError("report.timeline[{$index}] must be an object");
            }
            self::requireKeys($record, $required, "report.timeline[{$index}]");
            $start = self::integer($record['start_ns'], "report.timeline[{$index}].start_ns");
            $origin = $origin === null ? $start : min($origin, $start);
        }
        $origin ??= 0;

        $events = [];
        $contexts = [];
        foreach ($records as $index => $record) {
            $where = "report.timeline[{$index}]";
            $start = self::integer($record['start_ns'], "{$where}.start_ns");
            $end = self::integer($record['end_ns'], "{$where}.end_ns");
            if ($end < $start) {
                throw new ReportExportError("{$where} ends before it starts");
            }
            if (!is_string($record['symbol']) || !is_bool($record['completed']) || !is_string($record['outcome'])) {
                throw new ReportExportError("{$where} has a malformed symbol, completed or outcome");
            }
            $context = self::integer($record['context_id'], "{$where}.context_id");
            $contexts[$context] = true;
            $parent = $record['parent_frame_id'];
            $arguments = [
                'frame_id' => self::integer($record['frame_id'], "{$where}.frame_id"),
                'parent_frame_id' => $parent === null ? null : self::integer($parent, "{$where}.parent_frame_id"),
                'context_id' => $context,
                'completed' => $record['completed'],
                'outcome' => $record['outcome'],
            ];
            foreach (['active_wall_ns', 'active_cpu_ns', 'suspended_wall_ns'] as $metric) {
                if (!in_array("timeline.{$metric}", $unavailable, true)) {
                    $arguments[$metric] = self::count($record[$metric], "{$where}.{$metric}");
                }
            }
            $events[] = [
                'name' => $record['symbol'],
                'cat' => 'php',
                'ph' => 'X',
                'ts' => self::microseconds($start - $origin),
                'dur' => self::microseconds($end - $start),
                'pid' => 1,
                'tid' => $context,
                'args' => $arguments,
                // Sort keys, not output.
                '_start' => $start,
                '_duration' => $end - $start,
            ];
        }
        usort(
            $events,
            static fn(array $left, array $right): int => (
                [$left['tid'], $left['_start'], -$left['_duration'], $left['args']['frame_id']] <=> [
                    $right['tid'],
                    $right['_start'],
                    -$right['_duration'],
                    $right['args']['frame_id'],
                ]
            ),
        );
        ksort($contexts);
        $metadata = [[
            'name' => 'process_name',
            'ph' => 'M',
            'pid' => 1,
            'tid' => 0,
            'args' => ['name' => 'php-profiler'],
        ]];
        foreach (array_keys($contexts) as $context) {
            $metadata[] = [
                'name' => 'thread_name',
                'ph' => 'M',
                'pid' => 1,
                'tid' => $context,
                'args' => ['name' => "context {$context}"],
            ];
        }
        $encoded = [];
        foreach ([...$metadata, ...$events] as $event) {
            unset($event['_start'], $event['_duration']);
            $encoded[] = self::json($event);
        }

        $notes = ['Times include instrumentation overhead. ts is relative to timestamp_origin_ns.'];
        $dropped = self::counter(
            $report,
            'dropped_timeline_events',
            self::count($report['dropped_timeline_events'], 'report.dropped_timeline_events'),
        );
        if ($dropped !== '0') {
            $notes[] = "Timeline truncated: {$dropped} completed invocation records were dropped after the timeline capacity was reached.";
        }
        $notes = [...$notes, ...self::reportNotes($report)];
        $other = [
            'exporter' => 'php-profiler scripts/export-report.php',
            'schema_version' => (string) $report['schema_version'],
            'status' => self::show($report['status']),
            'timestamp_origin_ns' => (string) $origin,
            'dropped_timeline_events' => (string) $dropped,
            'unavailable_metrics' => implode(', ', $unavailable),
            'notes' => implode(' ', $notes),
        ];
        return (
            "{\"traceEvents\":[\n"
            . implode(",\n", $encoded)
            . "\n],\n\"displayTimeUnit\":\"ns\",\n\"otherData\":"
            . self::json($other)
            . "}\n"
        );
    }

    /**
     * Folded stacks from sampling: one actual stack per retained sample, root first.
     * A call graph only has caller/callee pairs, so no stack is ever built from it.
     *
     * @param array<string, mixed> $report
     * @return array{output: string, notes: list<string>}
     */
    public static function collapsed(array $report): array
    {
        if (!array_key_exists('sampling', $report)) {
            throw new ReportExportError(
                'the report has no sampling data (schema_version '
                . self::show($report['schema_version'])
                . "): start the session with ['sampling' => true] on a platform that supports it (Linux, glibc 2.32+). "
                . 'Folded stacks need real sampled stacks; the call graph only records caller/callee pairs, '
                . 'so stacks are not synthesized from its edges.',
            );
        }
        $sampling = self::map($report['sampling'], 'report.sampling');
        self::requireKeys($sampling, ['status', 'symbols', 'frames', 'samples'], 'report.sampling');
        $symbols = self::list($sampling['symbols'], 'report.sampling.symbols');
        $frames = self::list($sampling['frames'], 'report.sampling.frames');
        $samples = self::list($sampling['samples'], 'report.sampling.samples');
        $stacks = [];
        $truncated = 0;
        foreach ($samples as $index => $sample) {
            $where = "report.sampling.samples[{$index}]";
            if (!is_array($sample)) {
                throw new ReportExportError("{$where} must be an object");
            }
            self::requireKeys($sample, ['first_frame', 'frame_count', 'depth_truncated', 'stack_truncated'], $where);
            $first = self::integer($sample['first_frame'], "{$where}.first_frame");
            $count = self::integer($sample['frame_count'], "{$where}.frame_count");
            if (($first + $count) > count($frames)) {
                throw new ReportExportError("{$where} addresses frames beyond report.sampling.frames");
            }
            $names = [];
            // Frames are stored leaf to root; folded stacks list the root first.
            for ($position = $first + $count - 1; $position >= $first; $position--) {
                $id = self::integer($frames[$position], "report.sampling.frames[{$position}]");
                if (!isset($symbols[$id]) || !is_string($symbols[$id])) {
                    throw new ReportExportError("report.sampling.frames[{$position}] names no symbol");
                }
                $names[] = self::frame($symbols[$id]);
            }
            // Truncation drops the root side of a stack, so the marker stands in for it.
            if ($sample['depth_truncated'] === true || $sample['stack_truncated'] === true) {
                array_unshift($names, '[truncated]');
                $truncated++;
            }
            $stack = $names === [] ? '[no-frames]' : implode(';', $names);
            $stacks[$stack] = ($stacks[$stack] ?? 0) + 1;
        }
        ksort($stacks, SORT_STRING);
        $output = '';
        foreach ($stacks as $stack => $count) {
            $output .= "{$stack} {$count}\n";
        }

        $notes = [];
        if ($sampling['status'] !== 'complete') {
            $notes[] =
                'sampling status is '
                . self::show($sampling['status'])
                . ': the samples do not cover the whole session';
        }
        if ($truncated > 0) {
            $notes[] = "{$truncated} sampled stacks are truncated; their missing root frames appear as [truncated]";
        }
        if ($samples === []) {
            $notes[] = 'the report contains no samples; the output is empty';
        }
        $counters = $sampling['counters'] ?? null;
        if (is_array($counters)) {
            foreach (['dropped_samples', 'dropped_ticks', 'unserved_wakes', 'invalid_observations'] as $counter) {
                $value = $counters[$counter] ?? 0;
                if ($value !== 0 && $value !== '0') {
                    $notes[] = "sampling.counters.{$counter} is " . self::show($value) . '; those wakes have no stack';
                }
            }
        }
        if (($sampling['counters_valid'] ?? true) === false) {
            $notes[] = 'sampling counters are marked invalid';
        }
        if (is_string($sampling['failure_reason'] ?? null)) {
            // capacity and stopped end a capture normally; only the other reasons are failures.
            $reason = $sampling['failure_reason'];
            $notes[] =
                (in_array($reason, ['capacity', 'stopped'], true) ? 'sampling ended: ' : 'sampling failed: ') . $reason;
        }
        return ['output' => $output, 'notes' => $notes];
    }

    /** @return list<string> */
    private static function callgrindNotes(array $report, array $events, bool $withCalls): array
    {
        $notes = self::reportNotes($report);
        foreach (self::EVENTS as $event => [$long, $exclusive, $inclusive]) {
            if (!in_array($event, $events, true)) {
                $notes[] = "{$long} is omitted: the report marks {$exclusive} or {$inclusive} unavailable.";
            }
        }
        if (!$withCalls) {
            $notes[] = 'The call graph is omitted: the report marks calls unavailable, and Callgrind calls= lines need counts.';
        }
        return $notes;
    }

    /** A counter the report marks unavailable is a saturated placeholder, never a measurement. */
    private static function counter(array $report, string $key, int|string $value): string
    {
        return in_array($key, $report['unavailable_metrics'], true) ? 'unavailable (saturated)' : self::show($value);
    }

    /**
     * Report-wide truncation and validity facts.
     *
     * @return list<string>
     */
    private static function reportNotes(array $report): array
    {
        $notes = [];
        if ($report['status'] !== 'complete') {
            $notes[] = 'WARNING: report status is ' . self::show($report['status']) . '; attribution is incomplete.';
        }
        $counters = [
            'dropped_calls' => 'calls were dropped after the event budget was exhausted.',
            'unmatched_exits' => 'exits had no matching recorded entry.',
            'clock_regressions' => 'backward clock observations make timings incomplete.',
            'metric_overflows' => 'metric arithmetic overflows were detected.',
        ];
        foreach ($counters as $key => $text) {
            $value = $report[$key] ?? 0;
            if ($value !== 0 && $value !== '0') {
                $notes[] = "{$key}: " . self::counter($report, $key, $value) . " {$text}";
            }
        }
        if ($report['unavailable_metrics'] !== []) {
            $notes[] =
                'Unavailable metrics (omitted, not zero): ' . implode(', ', $report['unavailable_metrics']) . '.';
        }
        return $notes;
    }

    /**
     * @param list<string> $events
     * @param 1|2 $kind 1 for self cost (exclusive), 2 for call cost (inclusive)
     * @return list<int|string>
     */
    private static function costs(mixed $metrics, array $events, int $kind, string $where): array
    {
        $metrics = self::map($metrics, $where);
        self::requireKeys($metrics, self::METRICS, $where);
        $costs = [];
        foreach ($events as $event) {
            $metric = self::EVENTS[$event][$kind];
            $costs[] = self::count($metrics[$metric], "{$where}.{$metric}");
        }
        return $costs;
    }

    /** Nanoseconds as exact microseconds: an integer when whole, else a float with up to three decimals. */
    private static function microseconds(int $nanoseconds): int|float
    {
        return ($nanoseconds % 1000) === 0 ? intdiv($nanoseconds, 1000) : $nanoseconds / 1000;
    }

    private static function json(mixed $value): string
    {
        $precision = ini_get('serialize_precision');
        ini_set('serialize_precision', '-1');
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        } finally {
            ini_set('serialize_precision', (string) $precision);
        }
    }

    /** A folded-stack frame: ";" separates frames and a line break ends a stack, so both become "?". */
    private static function frame(string $symbol): string
    {
        return (string) preg_replace('/[;\x00-\x1f\x7f]/', '?', $symbol);
    }

    /** A Callgrind name: one line, so control characters become "?". */
    private static function line(string $symbol): string
    {
        return (string) preg_replace('/[\x00-\x1f\x7f]/', '?', $symbol);
    }

    /** @param list<string> $keys */
    private static function requireKeys(array $value, array $keys, string $where): void
    {
        $missing = array_values(array_diff($keys, array_keys($value)));
        if ($missing !== []) {
            throw new ReportExportError("{$where} is missing " . implode(', ', $missing));
        }
    }

    /** @return array<array-key, mixed> */
    private static function map(mixed $value, string $where): array
    {
        if (!is_array($value) || $value !== [] && array_is_list($value)) {
            throw new ReportExportError("{$where} must be an object");
        }
        return $value;
    }

    /** @return list<mixed> */
    private static function list(mixed $value, string $where): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new ReportExportError("{$where} must be a list");
        }
        return $value;
    }

    /** A non-negative count; values beyond PHP's integer range stay exact decimal strings. */
    private static function count(mixed $value, string $where): int|string
    {
        if (is_int($value) && $value >= 0 || is_string($value) && preg_match('/\A[1-9][0-9]*\z/', $value) === 1) {
            return $value;
        }
        throw new ReportExportError("{$where} must be a non-negative integer, got " . self::show($value));
    }

    /** A non-negative integer within PHP's range. */
    private static function integer(mixed $value, string $where): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        throw new ReportExportError("{$where} must be a non-negative integer within range, got " . self::show($value));
    }

    private static function show(mixed $value): string
    {
        return is_string($value) ? $value : (string) json_encode($value);
    }
}
