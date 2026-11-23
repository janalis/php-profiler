<?php

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use PhpProfiler\Tooling\Process;

/** @return array<string, int> */
function exportMetrics(int $calls, int $inclusiveWall, int $exclusiveWall, int $inclusiveCpu, int $exclusiveCpu): array
{
    return [
        'calls' => $calls,
        'exceptions' => 0,
        'inclusive_wall_ns' => $inclusiveWall,
        'exclusive_wall_ns' => $exclusiveWall,
        'inclusive_cpu_ns' => $inclusiveCpu,
        'exclusive_cpu_ns' => $exclusiveCpu,
        'memory_delta_bytes' => 0,
    ];
}

/** @return array<string, mixed> */
function exportFrame(int $id, ?int $parent, string $symbol, int $start, int $end, int $context = 0): array
{
    return [
        'frame_id' => $id,
        'parent_frame_id' => $parent,
        'symbol' => $symbol,
        'start_ns' => $start,
        'end_ns' => $end,
        'completed' => true,
        'context_id' => $context,
        'active_wall_ns' => $end - $start,
        'active_cpu_ns' => $end - $start - 10,
        'suspended_wall_ns' => 0,
        'outcome' => 'return',
    ];
}

/**
 * A small hand-built version 1 report with a call graph and a timeline.
 *
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function exportReport(array $overrides = []): array
{
    $incomplete = exportFrame(4, null, '{closure#1}', 1_005_600, 1_005_650, 2);
    $incomplete['completed'] = false;
    $incomplete['outcome'] = 'incomplete';
    return array_replace([
        'schema_version' => 1,
        'status' => 'complete',
        'elapsed_wall_ns' => 2000,
        'thread_cpu_ns' => 1500,
        'observed_boundary_peak_bytes' => 4096,
        'dropped_calls' => 0,
        'unmatched_exits' => 0,
        'clock_regressions' => 0,
        'memory_delta_bytes' => 0,
        'functions' => [
            'main' => exportMetrics(1, 1000, 400, 800, 300),
            'work' => exportMetrics(2, 600, 600, 500, 500),
        ],
        'edges' => [
            ['caller' => '{session}', 'callee' => 'main', 'metrics' => exportMetrics(1, 1000, 400, 800, 300)],
            ['caller' => 'main', 'callee' => 'work', 'metrics' => exportMetrics(2, 600, 600, 500, 500)],
        ],
        'timeline' => [
            exportFrame(2, 1, 'work', 1_005_100, 1_005_400),
            exportFrame(1, null, 'main', 1_005_000, 1_006_000),
            exportFrame(3, 1, 'work', 1_005_500, 1_005_800),
            $incomplete,
        ],
        'limitations' => [],
        'metric_provenance' => [],
        'capabilities' => [],
        'operations' => [],
        'timeline_enabled' => true,
        'termination_reason' => 'stopped',
        'runtime_metadata' => [],
        'dropped_timeline_events' => 0,
        'request_peak_bytes' => null,
        'hotspots' => [],
        'metric_overflows' => 0,
        'unavailable_metrics' => [],
    ], $overrides);
}

/**
 * A version 2 report whose sampling section holds four samples.
 *
 * @param array<string, mixed> $sampling
 * @return array<string, mixed>
 */
function sampledReport(array $sampling = []): array
{
    return exportReport([
        'schema_version' => 2,
        'sampling' => array_replace(
            [
                'mode' => 'wall-safepoint',
                'interval_ns' => 10_000_000,
                'start_ns' => 1_005_000,
                'end_ns' => 1_006_000,
                'stack_depth_limit' => 64,
                'status' => 'complete',
                'termination_reason' => 'stopped',
                'final_counters_available' => true,
                'counter_overflow' => false,
                'timestamps_valid' => true,
                'counters_valid' => true,
                'counters' => [
                    'serviced_wakes' => 4,
                    'dropped_ticks' => 0,
                    'coalesced_wakes' => 0,
                    'dropped_samples' => 0,
                    'unserved_wakes' => 0,
                    'invalid_observations' => 0,
                ],
                'captured_samples' => 4,
                'truncated_stacks' => 0,
                'symbols' => ['main', 'work', '{closure#1}'],
                // Leaf to root, per sample.
                'frames' => [1, 0, 1, 0, 0, 2, 1, 0],
                'samples' => [
                    exportSample(0, 2),
                    exportSample(2, 2),
                    exportSample(4, 1),
                    exportSample(5, 3),
                ],
                'metric_provenance' => [],
                'final_counter_snapshot_ns' => 1_006_000,
                'failure_reason' => null,
            ],
            $sampling,
        ),
    ]);
}

/** @return array<string, mixed> */
function exportSample(int $first, int $count, bool $truncated = false): array
{
    return [
        'due_ns' => 1_005_000,
        'first_wake_ns' => 1_005_001,
        'last_wake_ns' => 1_005_001,
        'service_ns' => 1_005_002,
        'context_id' => 0,
        'wake_count' => 1,
        'dropped_ticks' => 0,
        'first_frame' => $first,
        'frame_count' => $count,
        'depth_truncated' => $truncated,
        'stack_truncated' => false,
    ];
}

/**
 * Runs the exporter on a plain PHP CLI without any INI file or tooling prepend.
 *
 * @param array<string, mixed>|string $report a report, or raw file contents
 * @param list<string> $arguments
 * @return array{status: int|null, stdout: string, stderr: string}
 */
function runExport(array|string $report, array $arguments): array
{
    $directory = temporaryDirectory();
    $path = "{$directory}/report.json";
    file_put_contents($path, is_string($report) ? $report : json_encode($report, JSON_THROW_ON_ERROR));
    return Process::run([PHP_BINARY, '-n', ROOT . '/scripts/export-report.php', $path, ...$arguments]);
}

/** @param array{status: int|null, stdout: string, stderr: string} $result */
function exportFailed(array $result, string $message): void
{
    same(1, $result['status'], $result['stderr']);
    same('', $result['stdout']);
    same(true, str_contains($result['stderr'], $message), $result['stderr']);
}

const CALLGRIND_HEADER = <<<'TEXT'
    # callgrind format
    version: 1
    creator: php-profiler scripts/export-report.php
    # Exported from a php-profiler JSON report (schema_version 1, status complete).
    # The report has no source locations: every function is in the pseudo file "[php]" at line 0.
    # Self cost: functions[*].exclusive_*_ns. Call cost: edges[*].metrics.inclusive_*_ns.
    # Call counts: edges[*].metrics.calls. Times include instrumentation overhead.
    # {session} is the session root: it calls the functions entered at the top of the session.

    TEXT;

return [
    'callgrind maps exclusive costs to functions and inclusive costs to calls' => static function (): void {
        $result = runExport(exportReport(), ['--format', 'callgrind']);
        same(0, $result['status'], $result['stderr']);
        same('', $result['stderr']);
        same(CALLGRIND_HEADER . <<<'TEXT'
            positions: line
            event: wall_ns : Wall time (ns)
            event: cpu_ns : Thread CPU time (ns)
            events: wall_ns cpu_ns

            fl=(1) [php]
            fn=(1) main
            0 400 300
            cfn=(2) work
            calls=2 0
            0 600 500
            fn=(2)
            0 600 500
            fn=(3) {session}
            cfn=(1)
            calls=1 0
            0 1000 800

            TEXT, $result['stdout']);
    },
    'callgrind omits unavailable times and notes truncation instead of printing zeros' => static function (): void {
        $result = runExport(exportReport([
            'status' => 'truncated',
            'dropped_calls' => 7,
            'unavailable_metrics' => ['exclusive_wall_ns', 'inclusive_wall_ns'],
        ]), ['--format', 'callgrind']);
        same(0, $result['status'], $result['stderr']);
        same(str_replace('status complete', 'status truncated', CALLGRIND_HEADER) . <<<'TEXT'
            # WARNING: report status is truncated; attribution is incomplete.
            # dropped_calls: 7 calls were dropped after the event budget was exhausted.
            # Unavailable metrics (omitted, not zero): exclusive_wall_ns, inclusive_wall_ns.
            # Wall time (ns) is omitted: the report marks exclusive_wall_ns or inclusive_wall_ns unavailable.
            positions: line
            event: cpu_ns : Thread CPU time (ns)
            events: cpu_ns

            fl=(1) [php]
            fn=(1) main
            0 300
            cfn=(2) work
            calls=2 0
            0 500
            fn=(2)
            0 500
            fn=(3) {session}
            cfn=(1)
            calls=1 0
            0 800

            TEXT, $result['stdout']);
    },
    'callgrind without call counts keeps self costs and drops the call graph' => static function (): void {
        $result = runExport(exportReport(['unavailable_metrics' => ['calls']]), ['--format', 'callgrind']);
        same(0, $result['status'], $result['stderr']);
        same(true, str_ends_with($result['stdout'], <<<'TEXT'
            # The call graph is omitted: the report marks calls unavailable, and Callgrind calls= lines need counts.
            positions: line
            event: wall_ns : Wall time (ns)
            event: cpu_ns : Thread CPU time (ns)
            events: wall_ns cpu_ns

            fl=(1) [php]
            fn=(1) main
            0 400 300
            fn=(2) work
            0 600 500

            TEXT), $result['stdout']);
    },
    'callgrind fails when no time is available and keeps large counts exact' => static function (): void {
        exportFailed(
            runExport(exportReport([
                'unavailable_metrics' => ['exclusive_cpu_ns', 'inclusive_wall_ns'],
            ]), ['--format', 'callgrind']),
            'both wall and CPU times unavailable',
        );
        $json = json_encode(exportReport(), JSON_THROW_ON_ERROR);
        $json = str_replace('"exclusive_wall_ns":600', '"exclusive_wall_ns":18446744073709551615', $json);
        $result = runExport($json, ['--format', 'callgrind']);
        same(0, $result['status'], $result['stderr']);
        same(true, str_contains($result['stdout'], "fn=(2)\n0 18446744073709551615 500\n"), $result['stdout']);
    },
    'chrome trace emits complete events in microseconds per context' => static function (): void {
        $result = runExport(exportReport(), ['--format', 'chrome-trace']);
        same(0, $result['status'], $result['stderr']);
        same('', $result['stderr']);
        $args = static fn(int $id, ?int $parent, int $wall, int $context = 0, bool $done = true): string => (
            '"args":{"frame_id":'
            . $id
            . ',"parent_frame_id":'
            . ($parent ?? 'null')
            . ',"context_id":'
            . $context
            . ',"completed":'
            . ($done ? 'true,"outcome":"return"' : 'false,"outcome":"incomplete"')
            . ',"active_wall_ns":'
            . $wall
            . ',"active_cpu_ns":'
            . ($wall - 10)
            . ',"suspended_wall_ns":0}}'
        );
        $expected = implode(",\n", [
            '{"traceEvents":[',
            '{"name":"process_name","ph":"M","pid":1,"tid":0,"args":{"name":"php-profiler"}}',
            '{"name":"thread_name","ph":"M","pid":1,"tid":0,"args":{"name":"context 0"}}',
            '{"name":"thread_name","ph":"M","pid":1,"tid":2,"args":{"name":"context 2"}}',
            '{"name":"main","cat":"php","ph":"X","ts":0,"dur":1,"pid":1,"tid":0,' . $args(1, null, 1000),
            '{"name":"work","cat":"php","ph":"X","ts":0.1,"dur":0.3,"pid":1,"tid":0,' . $args(2, 1, 300),
            '{"name":"work","cat":"php","ph":"X","ts":0.5,"dur":0.3,"pid":1,"tid":0,' . $args(3, 1, 300),
            '{"name":"{closure#1}","cat":"php","ph":"X","ts":0.6,"dur":0.05,"pid":1,"tid":2,'
                . $args(4, null, 50, 2, false),
        ]);
        $expected = str_replace("[,\n", "[\n", $expected);
        $expected .=
            "\n],\n\"displayTimeUnit\":\"ns\",\n\"otherData\":"
            . '{"exporter":"php-profiler scripts/export-report.php","schema_version":"1","status":"complete",'
            . '"timestamp_origin_ns":"1005000","dropped_timeline_events":"0","unavailable_metrics":"",'
            . '"notes":"Times include instrumentation overhead. ts is relative to timestamp_origin_ns."}}'
            . "\n";
        same($expected, $result['stdout']);
        same(7, count(json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR)['traceEvents']));
    },
    'chrome trace records truncation and omits unavailable timeline metrics' => static function (): void {
        $result = runExport(exportReport([
            'status' => 'truncated',
            'dropped_timeline_events' => 3,
            'unavailable_metrics' => ['exclusive_cpu_ns', 'inclusive_cpu_ns', 'timeline.active_cpu_ns'],
        ]), ['--format', 'chrome-trace']);
        same(0, $result['status'], $result['stderr']);
        $trace = json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR);
        same([
            'frame_id',
            'parent_frame_id',
            'context_id',
            'completed',
            'outcome',
            'active_wall_ns',
            'suspended_wall_ns',
        ], array_keys($trace['traceEvents'][3]['args']));
        same('3', $trace['otherData']['dropped_timeline_events']);
        same(
            'Times include instrumentation overhead. ts is relative to timestamp_origin_ns. '
            . 'Timeline truncated: 3 completed invocation records were dropped after the timeline capacity was reached. '
            . 'WARNING: report status is truncated; attribution is incomplete. '
            . 'Unavailable metrics (omitted, not zero): exclusive_cpu_ns, inclusive_cpu_ns, timeline.active_cpu_ns.',
            $trace['otherData']['notes'],
        );
    },
    'chrome trace needs a timeline with valid timestamps' => static function (): void {
        exportFailed(
            runExport(exportReport(['timeline_enabled' => false, 'timeline' => []]), ['--format', 'chrome-trace']),
            "start the session with ['timeline' => true]",
        );
        exportFailed(
            runExport(exportReport(['unavailable_metrics' => ['timeline.end_ns']]), ['--format', 'chrome-trace']),
            'marks timeline.end_ns unavailable',
        );
        $backwards = exportReport();
        $backwards['timeline'][0]['end_ns'] = 1;
        exportFailed(runExport($backwards, ['--format', 'chrome-trace']), 'report.timeline[0] ends before it starts');
    },
    'collapsed folds sampled stacks root first and reports truncation on stderr' => static function (): void {
        $report = sampledReport([
            'status' => 'truncated',
            'counters' => [
                'serviced_wakes' => 5,
                'dropped_ticks' => 0,
                'coalesced_wakes' => 2,
                'dropped_samples' => 1,
                'unserved_wakes' => 0,
                'invalid_observations' => 0,
            ],
        ]);
        $report['sampling']['samples'][3]['depth_truncated'] = true;
        $result = runExport($report, ['--format', 'collapsed']);
        same(0, $result['status'], $result['stderr']);
        same("[truncated];main;work;{closure#1} 1\nmain 1\nmain;work 2\n", $result['stdout']);
        same(
            "note: sampling status is truncated: the samples do not cover the whole session\n"
            . "note: 1 sampled stacks are truncated; their missing root frames appear as [truncated]\n"
            . "note: sampling.counters.dropped_samples is 1; those wakes have no stack\n",
            $result['stderr'],
        );
    },
    'collapsed never synthesizes stacks from the call graph' => static function (): void {
        exportFailed(
            runExport(exportReport(), ['--format', 'collapsed']),
            'the call graph only records caller/callee pairs, so stacks are not synthesized from its edges',
        );
        $report = sampledReport();
        $report['sampling']['frames'][1] = 9;
        exportFailed(runExport($report, ['--format', 'collapsed']), 'report.sampling.frames[1] names no symbol');
        $report = sampledReport();
        $report['sampling']['samples'][0]['frame_count'] = 64;
        exportFailed(runExport($report, ['--format', 'collapsed']), 'addresses frames beyond');
    },
    'malformed reports fail with a message and no output' => static function (): void {
        $broken = exportReport();
        $broken['edges'][1]['metrics']['inclusive_wall_ns'] = -1;
        $cases = [
            'not json' => ['{"schema_version":', 'not valid JSON'],
            'a list' => ['[1]', 'not a JSON object'],
            'version' => [exportReport(['schema_version' => 3]), 'unsupported schema_version: expected 1 or 2, got 3'],
            'missing' => [array_diff_key(exportReport(), ['functions' => true]), 'report is missing functions'],
            'edges' => [exportReport(['edges' => ['caller' => 'a']]), 'report.edges must be a list'],
            'negative' => [$broken, 'report.edges[1].metrics.inclusive_wall_ns must be a non-negative integer, got -1'],
        ];
        foreach ($cases as $name => [$report, $message]) {
            $result = runExport($report, ['--format', 'callgrind']);
            same(1, $result['status'], $name . ': ' . $result['stderr']);
            same('', $result['stdout'], $name);
            same(true, str_contains($result['stderr'], $message), $name . ': ' . $result['stderr']);
        }
        exportFailed(Process::run([
            PHP_BINARY,
            '-n',
            ROOT . '/scripts/export-report.php',
            '/nonexistent/r.json',
            '--format=callgrind',
        ]), 'cannot read report: /nonexistent/r.json');
    },
    'output files are replaced whole, never left partial' => static function (): void {
        $directory = temporaryDirectory();
        $output = "{$directory}/profile.callgrind";
        file_put_contents($output, 'previous');
        $failed = runExport(exportReport(['schema_version' => 0]), ['--format', 'callgrind', '--output', $output]);
        same(1, $failed['status'], $failed['stderr']);
        same('previous', file_get_contents($output));
        $written = runExport(exportReport(), ['--format', 'callgrind', "--output={$output}"]);
        same([0, '', ''], [$written['status'], $written['stdout'], $written['stderr']]);
        same(true, str_starts_with((string) file_get_contents($output), "# callgrind format\n"));
        // Exports carry the report's private program identifiers, like the extension's 0600 reports.
        clearstatcache();
        same(0o600, fileperms($output) & 0o777);
        same(['profile.callgrind'], array_values(array_diff(scandir($directory), ['.', '..'])));
        exportFailed(runExport(exportReport(), [
            '--format',
            'callgrind',
            '--output',
            "{$directory}/missing/out",
        ]), 'output directory does not exist');
    },
    'usage errors exit with status 2' => static function (): void {
        same(2, runExport(exportReport(), [])['status']);
        same(2, runExport(exportReport(), ['--format', 'pprof'])['status']);
        same(2, runExport(exportReport(), ['--format', 'callgrind', 'extra.json'])['status']);
    },
    'a real report with a timeline exports to every available format' => static function (): void {
        $extension = (string) getenv('PROFILER_EXTENSION');
        if ($extension === '') {
            $extension = ROOT . '/target/debug/libphp_profiler.' . (PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so');
        }
        if (!is_file($extension)) {
            fwrite(STDERR, "ReportExportTest: skipped the real report, no built extension at {$extension}\n");
            return;
        }
        $code = <<<'PHP'
            function leaf(int $n): int { return $n < 2 ? $n : leaf($n - 1) + leaf($n - 2); }
            $sampling = PhpProfiler\Profiler::isSamplingSupported();
            $session = PhpProfiler\Profiler::start(null, ['timeline' => true, 'sampling' => $sampling]);
            $fiber = new Fiber(static function (): void { leaf(8); Fiber::suspend(); leaf(8); });
            $fiber->start();
            leaf(10);
            $fiber->resume();
            $session->stop();
            echo $session->getReportJson();
            PHP;
        $run = Process::run([PHP_BINARY, '-n', '-d', "extension={$extension}", '-r', $code]);
        if ($run['status'] !== 0) {
            // A stale or mismatched build cannot load; that is not this exporter's failure.
            fwrite(STDERR, "ReportExportTest: skipped the real report, the extension did not run:\n{$run['stderr']}");
            return;
        }
        $report = json_decode($run['stdout'], true, flags: JSON_THROW_ON_ERROR);
        $callgrind = runExport($run['stdout'], ['--format', 'callgrind']);
        same(0, $callgrind['status'], $callgrind['stderr']);
        same(1, preg_match(
            '/^fn=\(\d+\) leaf\n0 \d+ \d+\ncfn=\(\d+\)\ncalls=\d+ 0\n0 \d+ \d+$/m',
            $callgrind['stdout'],
        ));
        $chrome = runExport($run['stdout'], ['--format', 'chrome-trace']);
        same(0, $chrome['status'], $chrome['stderr']);
        $trace = json_decode($chrome['stdout'], true, flags: JSON_THROW_ON_ERROR);
        $complete = array_filter($trace['traceEvents'], static fn(array $event): bool => $event['ph'] === 'X');
        same(count($report['timeline']), count($complete));
        same(true, count(array_unique(array_column($complete, 'tid'))) >= 2, 'the fiber has its own context');
        $collapsed = runExport($run['stdout'], ['--format', 'collapsed']);
        if (isset($report['sampling'])) {
            same(0, $collapsed['status'], $collapsed['stderr']);
        } else {
            same(1, $collapsed['status'], $collapsed['stderr']);
        }
    },
];
