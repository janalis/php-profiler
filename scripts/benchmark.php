<?php

declare(strict_types=1);

// Paired subprocess benchmark; run against the release artifact on an idle host.
require_once __DIR__ . '/lib/Arguments.php';
require_once __DIR__ . '/lib/Benchmark.php';
require_once __DIR__ . '/lib/Json.php';
require_once __DIR__ . '/lib/Paths.php';
require_once __DIR__ . '/lib/Process.php';
require_once __DIR__ . '/lib/PythonRandom.php';
require_once __DIR__ . '/lib/ValidationInputs.php';

use PhpProfiler\Tooling\Arguments;
use PhpProfiler\Tooling\Benchmark;
use PhpProfiler\Tooling\Json;
use PhpProfiler\Tooling\Paths;
use PhpProfiler\Tooling\Process;
use PhpProfiler\Tooling\PythonRandom;
use PhpProfiler\Tooling\ValidationInputs;

const SOURCE = <<<'PHPSOURCE'
    <?php
    use PhpProfiler\Profiler;
    function bench_leaf(int $i): int { return ($i * 3) % 97; }
    $enabled = ($argv[1] ?? '') === 'enabled';
    $iterations = (int)$argv[2]; $workload = $argv[3];
    $statuses = []; $leafCalls = 0; $samplingStatuses = []; $samplingSamples = 0; $samplingReports = [];
    $sampling = ($argv[4] ?? '0') === '1';
    $disabledWarmup = false;
    if (($argv[1] ?? '') === 'disabled' && ($argv[5] ?? '0') === '1') {
        // Real prior capture, outside both timed regions; no sampler is requested.
        $warmup = Profiler::start(); bench_leaf(0); $warmup->stop();
        $warmupReport = $warmup->getReport();
        if ($warmupReport['status'] !== 'complete' ||
            ($warmupReport['functions']['bench_leaf']['calls'] ?? 0) !== 1) {
            throw new RuntimeException('disabled-state warmup did not capture the real function');
        }
        $warmup->reset(); Profiler::disable(); unset($warmup, $warmupReport);
        if (Profiler::isEnabled()) { throw new RuntimeException('warmup failed to disable profiling'); }
        $disabledWarmup = true;
    }
    if ($enabled && $workload !== 'worker') { $session = Profiler::start(null, ['sampling' => $sampling]); }
    $usageBefore = getrusage();
    $t = hrtime(true); $sum = 0;
    for ($i = 0; $i < $iterations; ++$i) {
        switch ($workload) {
            case 'calls': $sum += bench_leaf($i); break;
            case 'cpu':
                for ($j = 0; $j < 1000; ++$j) { $sum += ($i * 3 + $j) % 97; }
                break;
            case 'sleep': usleep(100); $sum += $i; break;
            case 'memory': $buffer = str_repeat('a', 65536); $sum += strlen($buffer); unset($buffer); break;
            case 'worker':
                if ($enabled) { $job = Profiler::start(null, ['sampling' => $sampling]); }
                $sum += bench_leaf($i);
                if ($enabled) { $job->stop(); $report = $job->getReport(); $statuses[] = $report['status']; $leafCalls += $report['functions']['bench_leaf']['calls'] ?? 0; if ($sampling) { $samplingReports[] = $report['sampling']; $samplingStatuses[] = $report['sampling']['status']; $samplingSamples += $report['sampling']['captured_samples']; } $job->reset(); }
                break;
        }
    }
    $elapsed = hrtime(true) - $t;
    $usageAfter = getrusage();
    $cpu = (($usageAfter['ru_utime.tv_sec'] - $usageBefore['ru_utime.tv_sec'] +
             $usageAfter['ru_stime.tv_sec'] - $usageBefore['ru_stime.tv_sec']) * 1000000 +
            $usageAfter['ru_utime.tv_usec'] - $usageBefore['ru_utime.tv_usec'] +
            $usageAfter['ru_stime.tv_usec'] - $usageBefore['ru_stime.tv_usec']) * 1000;
    if ($enabled && $workload !== 'worker') {
        $session->stop(); $report = $session->getReport(); $statuses[] = $report['status']; $leafCalls += $report['functions']['bench_leaf']['calls'] ?? 0;
        if ($sampling) { $samplingReports[] = $report['sampling']; $samplingStatuses[] = $report['sampling']['status']; $samplingSamples += $report['sampling']['captured_samples']; }
    }
    if ($enabled) { Profiler::disable(); }
    echo json_encode(['disabled_warmup' => $disabledWarmup, 'elapsed_ns' => $elapsed, 'cpu_ns' => $cpu, 'sum' => $sum, 'capture_statuses' => array_count_values($statuses), 'leaf_calls' => $leafCalls, 'sampling_statuses' => array_count_values($samplingStatuses), 'sampling_samples' => $samplingSamples, 'sampling_reports' => $samplingReports]);

    PHPSOURCE;

$root = Paths::resolve(dirname(__DIR__));
$sourceBefore = ValidationInputs::snapshot($root);
$usage = 'benchmark.php --extension <path> [--repeats <n>] [--iterations <n>] [--workload calls|cpu|sleep|memory|worker] [--sampling] [--warm-disabled]';
['options' => $options, 'positionals' => $positionals] = Arguments::parse(
    $argv,
    ['extension', 'repeats', 'iterations', 'workload'],
    ['sampling', 'warm-disabled'],
    $usage,
);
$workload = $options['workload'] ?? 'calls';
if (
    $positionals !== []
    || !is_string($options['extension'] ?? null)
    || !in_array($workload, ['calls', 'cpu', 'sleep', 'memory', 'worker'], true)
) {
    Arguments::fail('--extension is required and the workload must be known', $usage);
}
$repeats = (int) ($options['repeats'] ?? 11);
$iterations = (int) ($options['iterations'] ?? 100);
$sampling = isset($options['sampling']);
$warmDisabled = isset($options['warm-disabled']);
if ($repeats < 3 || $iterations < 1) {
    Arguments::fail('use at least three repeats and one iteration', $usage);
}
$extension = Paths::resolve($options['extension']);
$extensionBefore = hash_file('sha256', $extension);
$results = ['absent' => [], 'disabled' => [], 'enabled' => []];
$directory = sys_get_temp_dir() . '/php-profiler-bench-' . bin2hex(random_bytes(6));
mkdir($directory, 0o700);
register_shutdown_function(static fn() => Paths::removeTree($directory));
$script = "{$directory}/bench.php";
file_put_contents($script, SOURCE);
$reference = null;
for ($repetition = 0; $repetition <= $repeats; $repetition++) {
    foreach ((new PythonRandom($repetition))->shuffle(array_keys($results)) as $mode) {
        $command = ['php', '-n'];
        if ($mode !== 'absent') {
            array_push($command, '-d', 'extension=' . $extension);
        }
        array_push(
            $command,
            $script,
            $mode,
            (string) $iterations,
            $workload,
            $sampling ? '1' : '0',
            $warmDisabled ? '1' : '0',
        );
        $started = hrtime(true);
        $sample = json_decode(Process::output($command), true, flags: JSON_THROW_ON_ERROR);
        $sample['process_ns'] = hrtime(true) - $started;
        $reference ??= $sample['sum'];
        if ($sample['sum'] !== $reference) {
            throw new RuntimeException('profiling changed workload result');
        }
        if (($sample['disabled_warmup'] ?? null) !== ($warmDisabled && $mode === 'disabled')) {
            throw new RuntimeException('disabled-state warmup evidence disagrees with requested mode');
        }
        Benchmark::validateCapture($sample, $mode, $iterations, $workload);
        Benchmark::validateTiming($sample);
        Benchmark::validateSampling($sample, $mode, $sampling, $iterations, $workload);
        if ($repetition > 0) {
            $results[$mode][] = $sample;
        }
    }
}
$summary = [];
foreach ($results as $mode => $samples) {
    $values = array_column($samples, 'elapsed_ns');
    $cpu = array_column($samples, 'cpu_ns');
    $summary[$mode] = [
        'median_ns' => Benchmark::median($values),
        'min_ns' => min($values),
        'max_ns' => max($values),
        'median_cpu_ns' => Benchmark::median($cpu),
        'min_cpu_ns' => min($cpu),
        'max_cpu_ns' => max($cpu),
        'samples' => $samples,
    ];
}
foreach (['disabled', 'enabled'] as $mode) {
    $summary[$mode]['median_ratio_to_absent'] = $summary[$mode]['median_ns'] / (float) $summary['absent']['median_ns'];
    $baselineCpu = $summary['absent']['median_cpu_ns'];
    $summary[$mode]['median_cpu_ratio_to_absent'] = $baselineCpu
        ? $summary[$mode]['median_cpu_ns'] / (float) $baselineCpu
        : null;
}
if (ValidationInputs::snapshot($root) !== $sourceBefore || hash_file('sha256', $extension) !== $extensionBefore) {
    throw new RuntimeException('benchmark source or extension changed during capture');
}
$sessions = $workload === 'worker' ? $iterations : 1;
$complete =
    $results['enabled'] !== []
    && array_filter(
        $results['enabled'],
        static fn(array $sample): bool => $sample['capture_statuses'] !== ['complete' => $sessions],
    ) === [];
echo
    Json::encode(
        [
            'source_sha256' => (object) ValidationInputs::inPathOrder($sourceBefore),
            'source_snapshot_matches' => true,
            'extension_sha256' => $extensionBefore,
            'extension_snapshot_matches' => true,
            'platform' => Benchmark::platform(),
            'php' => Process::output(['php', '-v']),
            'extension' => $extension,
            'iterations' => $iterations,
            'repeats' => $repeats,
            'workload' => $workload,
            'sampling_requested' => $sampling,
            'disabled_process_state' => $warmDisabled
                ? 'after a real stopped/reset capture; setup and teardown excluded from timing; allocator/cache state is also warmed'
                : 'profiling never started in the disabled process',
            'sampling_note' => 'captures shorter than the timer interval may correctly retain zero samples; exact instrumentation counts and sampled counter/status consistency are checked separately; real unserved stop-boundary wakes remain disclosed as truncated sampling',
            'complete_capture' => $complete,
            'capture_validation' => [
                'expected_sessions_per_enabled_sample' => $sessions,
                'expected_leaf_calls_per_enabled_sample' => in_array($workload, ['calls', 'worker'], true)
                    ? $iterations
                    : 0,
            ],
            'timed_region' => $workload === 'worker'
                ? 'per-iteration start/application/stop/report/reset lifecycle'
                : 'application loop only; admission, stop, report retrieval and disable excluded',
            'cpu_measurement' => 'process user+system CPU from PHP getrusage; microsecond resolution converted to nanoseconds; brackets wall region and its clock reads; includes all process threads; null ratio when absent median rounds to zero',
            'opcache' => 'disabled (-n)',
            'results' => $summary,
        ],
        indent: 2,
        sortKeys: false,
    ),
    "\n"
;
