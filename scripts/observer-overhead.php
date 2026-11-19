<?php

declare(strict_types=1);

// Paired disabled-observer release comparison; retains every timed sample.
require_once __DIR__ . '/lib/Arguments.php';
require_once __DIR__ . '/lib/Benchmark.php';
require_once __DIR__ . '/lib/Json.php';
require_once __DIR__ . '/lib/Paths.php';
require_once __DIR__ . '/lib/Process.php';
require_once __DIR__ . '/lib/PythonRandom.php';

use PhpProfiler\Tooling\Arguments;
use PhpProfiler\Tooling\Benchmark;
use PhpProfiler\Tooling\Json;
use PhpProfiler\Tooling\Paths;
use PhpProfiler\Tooling\Process;
use PhpProfiler\Tooling\PythonRandom;

$usage = 'observer-overhead.php --baseline <path> --candidate <path> [--php <php>] [--iterations <n>] [--repeats <n>]';
['options' => $options, 'positionals' => $positionals] = Arguments::parse(
    $argv,
    ['baseline', 'candidate', 'php', 'iterations', 'repeats'],
    usage: $usage,
);
if ($positionals !== [] || !is_string($options['baseline'] ?? null) || !is_string($options['candidate'] ?? null)) {
    Arguments::fail('--baseline and --candidate are required', $usage);
}
$php = (string) ($options['php'] ?? 'php');
$iterations = (int) ($options['iterations'] ?? 1000000);
$repeats = (int) ($options['repeats'] ?? 31);
if ($iterations < 1 || $repeats < 3) {
    Arguments::fail('positive iteration count and at least three repeats required', $usage);
}
$artifacts = ['baseline' => Paths::resolve($options['baseline']), 'candidate' => Paths::resolve($options['candidate'])];
$source = <<<'PHPSOURCE'
    <?php
    function observer_bench_leaf(int $i):int{return ($i*3)%97;}
    $sum=0;$iterations=(int)$argv[1];
    $t=hrtime(true);
    for($i=0;$i<$iterations;$i++){$sum+=observer_bench_leaf($i);}
    $elapsed=hrtime(true)-$t;
    echo json_encode(['elapsed_ns'=>$elapsed,'sum'=>$sum]);

    PHPSOURCE;
$directory = sys_get_temp_dir() . '/profiler-observer-overhead-' . bin2hex(random_bytes(6));
mkdir($directory, 0o700);
register_shutdown_function(static fn() => Paths::removeTree($directory));
$script = "{$directory}/bench.php";
file_put_contents($script, $source);
$samples = [];
$expected = null;
for ($repetition = 0; $repetition <= $repeats; $repetition++) {
    $order = (new PythonRandom($repetition))->shuffle(['absent', 'baseline', 'candidate']);
    $values = [];
    foreach ($order as $name) {
        $command = [$php, '-n'];
        if ($name !== 'absent') {
            array_push($command, '-d', 'extension=' . $artifacts[$name]);
        }
        array_push($command, $script, (string) $iterations);
        $value = json_decode(Process::output($command), true, flags: JSON_THROW_ON_ERROR);
        $expected ??= $value['sum'];
        if ($value['sum'] !== $expected) {
            throw new RuntimeException('observer changed the workload result');
        }
        $values[$name] = $value['elapsed_ns'];
    }
    if ($repetition > 0) {
        $samples[] = ['repetition' => $repetition, 'order' => $order, 'elapsed_ns' => $values];
    }
}
$medians = [];
foreach (['absent', 'baseline', 'candidate'] as $name) {
    $medians[$name] = Benchmark::median(array_map(
        static fn(array $sample): int => $sample['elapsed_ns'][$name],
        $samples,
    ));
}
echo
    Json::encode(
        [
            'platform' => Benchmark::platform(),
            'php' => Process::output([$php, '-v']),
            'artifacts' => array_map(static fn(string $path): array => [
                'path' => $path,
                'sha256' => hash_file('sha256', $path),
            ], $artifacts),
            'iterations' => $iterations,
            'repeats' => $repeats,
            'opcache' => 'disabled (-n)',
            'profiling' => 'never enabled',
            'median_ns' => $medians,
            'candidate_to_baseline_paired_median' => Benchmark::median(array_map(
                static fn(array $sample): float => (
                    $sample['elapsed_ns']['candidate'] / (float) $sample['elapsed_ns']['baseline']
                ),
                $samples,
            )),
            'baseline_to_absent' => $medians['baseline'] / (float) $medians['absent'],
            'candidate_to_absent' => $medians['candidate'] / (float) $medians['absent'],
            'samples' => $samples,
        ],
        indent: 2,
        sortKeys: false,
    ),
    "\n"
;
