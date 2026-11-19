<?php

declare(strict_types=1);

// Paired FPM request batches; no network service outside a private Unix socket.
require_once __DIR__ . '/lib/Arguments.php';
require_once __DIR__ . '/lib/Benchmark.php';
require_once __DIR__ . '/lib/FpmServer.php';
require_once __DIR__ . '/lib/Json.php';
require_once __DIR__ . '/lib/Paths.php';
require_once __DIR__ . '/lib/PythonRandom.php';
require_once __DIR__ . '/lib/ValidationInputs.php';

use PhpProfiler\Tooling\Arguments;
use PhpProfiler\Tooling\Benchmark;
use PhpProfiler\Tooling\FpmServer;
use PhpProfiler\Tooling\Json;
use PhpProfiler\Tooling\Paths;
use PhpProfiler\Tooling\PythonRandom;
use PhpProfiler\Tooling\ValidationInputs;

/**
 * The named fields, in order; a missing one is an invalid response.
 *
 * @param list<string> $keys
 */
function pick(array $values, array $keys): array
{
    $picked = [];
    foreach ($keys as $key) {
        $picked[$key] = array_key_exists($key, $values)
            ? $values[$key]
            : throw new RuntimeException("FPM benchmark response lacks {$key}");
    }
    return $picked;
}

$usage = 'fpm-benchmark.php --extension <path> [--repeats <n>] [--requests <n>] [--iterations <n>] [--sampling]';
['options' => $options, 'positionals' => $positionals] = Arguments::parse(
    $argv,
    ['extension', 'repeats', 'requests', 'iterations'],
    ['sampling'],
    $usage,
);
if ($positionals !== [] || !is_string($options['extension'] ?? null)) {
    Arguments::fail('--extension is required', $usage);
}
$repeats = (int) ($options['repeats'] ?? 11);
$requests = (int) ($options['requests'] ?? 20);
$iterations = (int) ($options['iterations'] ?? 1000);
$sampling = isset($options['sampling']);
if ($repeats < 3 || $requests < 1 || $iterations < 1) {
    Arguments::fail('use at least three repeats and positive request/iteration counts', $usage);
}
$root = Paths::resolve(dirname(__DIR__));
chdir($root);
$sourceBefore = ValidationInputs::snapshot($root);
$extension = Paths::resolve($options['extension']);
$extensionBefore = hash_file('sha256', $extension);
$expected = 0;
for ($i = 0; $i < $iterations; $i++) {
    $expected += ($i * 3) % 97;
}
$samples = ['absent' => [], 'disabled' => [], 'enabled' => []];
$workloads = ['absent' => [], 'disabled' => [], 'enabled' => []];
$metadata = [];
for ($repetition = 0; $repetition <= $repeats; $repetition++) {
    foreach ((new PythonRandom($repetition))->shuffle(array_keys($samples)) as $mode) {
        $server = new FpmServer($mode === 'absent' ? null : $extension);
        $server->serve(static function (FpmServer $server) use (
            $mode,
            $iterations,
            $sampling,
            $expected,
            $requests,
            $repetition,
            &$samples,
            &$workloads,
            &$metadata,
        ): void {
            $request = static function () use ($server, $mode, $iterations, $sampling, $expected, &$metadata): array {
                $result = $server->request('tests/fpm/benchmark.php', [
                    'PROFILE_MODE' => $mode,
                    'PROFILE_ITERATIONS' => (string) $iterations,
                    'PROFILE_SAMPLING' => $sampling ? '1' : '0',
                ]);
                if ($result['sum'] !== $expected) {
                    throw new RuntimeException('application response changed');
                }
                Benchmark::validateTiming($result);
                Benchmark::validateSampling($result, $mode, $sampling, $iterations, 'calls');
                if ($mode === 'enabled' && ($result['status'] !== 'complete' || $result['calls'] !== $iterations)) {
                    throw new RuntimeException('benchmark capture incomplete or wrong call count');
                }
                $metadata[$mode] = pick($result, ['php', 'zts', 'sapi', 'opcache', 'jit']);
                return pick($result, [
                    'elapsed_ns',
                    'cpu_ns',
                    'sampling_statuses',
                    'sampling_samples',
                    'sampling_reports',
                ]);
            };
            $request(); // Warm the reused worker before timing.
            $started = hrtime(true);
            $measured = [];
            for ($index = 0; $index < $requests; $index++) {
                $measured[] = $request();
            }
            $elapsed = hrtime(true) - $started;
            if ($repetition > 0) {
                $samples[$mode][] = $elapsed;
                $workloads[$mode][] = $measured;
            }
        });
    }
}
$summary = [];
foreach ($samples as $mode => $values) {
    $summary[$mode] = [
        'median_batch_ns' => Benchmark::median($values),
        'min_batch_ns' => min($values),
        'max_batch_ns' => max($values),
        'samples_batch_ns' => $values,
    ];
}
foreach ($workloads as $mode => $batches) {
    $summary[$mode]['workload_samples'] = $batches;
    foreach (['elapsed_ns', 'cpu_ns'] as $field) {
        $summary[$mode]['median_workload_batch_' . $field] = Benchmark::median(array_map(
            static fn(array $batch): int => array_sum(array_column($batch, $field)),
            $batches,
        ));
    }
}
foreach (['disabled', 'enabled'] as $mode) {
    $summary[$mode]['ratio_to_absent'] =
        $summary[$mode]['median_batch_ns'] / (float) $summary['absent']['median_batch_ns'];
    $baselineCpu = $summary['absent']['median_workload_batch_cpu_ns'];
    $summary[$mode]['workload_cpu_ratio_to_absent'] = $baselineCpu
        ? $summary[$mode]['median_workload_batch_cpu_ns'] / (float) $baselineCpu
        : null;
}
if (ValidationInputs::snapshot($root) !== $sourceBefore || hash_file('sha256', $extension) !== $extensionBefore) {
    throw new RuntimeException('benchmark source or extension changed during capture');
}
echo
    Json::encode(
        [
            'platform' => Benchmark::platform(),
            'metadata' => $metadata,
            'repeats' => $repeats,
            'requests_per_batch' => $requests,
            'iterations_per_request' => $iterations,
            'sampling_requested' => $sampling,
            'source_sha256' => (object) ValidationInputs::inPathOrder($sourceBefore),
            'source_snapshot_matches' => true,
            'extension_sha256' => $extensionBefore,
            'extension_snapshot_matches' => true,
            'workload_cpu_measurement' => 'per-request process user+system CPU from getrusage, microsecond resolution converted to nanoseconds; brackets application-loop wall region and clock reads; excludes stop/report and FastCGI transport; null ratio when absent median rounds to zero',
            'complete_capture' => true,
            'timed_region' => 'full reused-worker request batches including application, stop/report and local FastCGI transport; worker startup excluded',
            'results' => $summary,
        ],
        indent: 2,
        sortKeys: false,
    ),
    "\n"
;
