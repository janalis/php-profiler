<?php

declare(strict_types=1);

// Runs comparative workloads; retains measurements without an invented overhead limit.
require_once __DIR__ . '/lib/Arguments.php';
require_once __DIR__ . '/lib/Json.php';
require_once __DIR__ . '/lib/Paths.php';

use PhpProfiler\Tooling\Arguments;
use PhpProfiler\Tooling\Json;
use PhpProfiler\Tooling\Paths;

$usage = 'performance-evidence.php --extension <path> --output <directory> [--repeats <n>] [--fpm] [--with-sampling] [--with-warm-disabled]';
['options' => $options, 'positionals' => $positionals] = Arguments::parse(
    $argv,
    ['extension', 'output', 'repeats'],
    ['fpm', 'with-sampling', 'with-warm-disabled'],
    $usage,
);
if ($positionals !== [] || !is_string($options['extension'] ?? null) || !is_string($options['output'] ?? null)) {
    Arguments::fail('--extension and --output are required', $usage);
}
$repeats = (int) ($options['repeats'] ?? 7);
if ($repeats < 3) {
    Arguments::fail('at least three repeats required', $usage);
}
$root = Paths::resolve(dirname(__DIR__));
$output = Paths::resolve($options['output']);
if (!is_dir($output) && !mkdir($output, 0o777, true)) {
    throw new RuntimeException('create ' . $output);
}
$cpuCount = static function (): ?int {
    $count = PHP_OS_FAMILY === 'Darwin'
        ? shell_exec('sysctl -n hw.logicalcpu')
        : shell_exec('getconf _NPROCESSORS_ONLN');
    return is_string($count) && preg_match('/\A\d+\s*\z/', $count) ? (int) $count : null;
};
$loadBefore = sys_getloadavg();
$tool = "{$root}/scripts/php-tool.sh";
$commands = [];
foreach ([['calls', 1000000], ['cpu', 10000], ['sleep', 1000], ['memory', 10000], ['worker', 1000]] as [
    $workload,
    $iterations,
]) {
    $commands[$workload] = [
        $tool,
        'scripts/benchmark.php',
        '--workload',
        $workload,
        '--iterations',
        (string) $iterations,
    ];
}
if (isset($options['fpm'])) {
    $commands['fpm'] = [$tool, 'scripts/fpm-benchmark.php', '--requests', '20', '--iterations', '10000'];
}
if (isset($options['with-sampling'])) {
    foreach ($commands as $name => $command) {
        $commands[$name . '-sampling'] = [...$command, '--sampling'];
    }
}
if (isset($options['with-warm-disabled'])) {
    foreach (['calls', 'worker'] as $name) {
        $commands[$name . '-warm-disabled'] = [...$commands[$name], '--warm-disabled'];
    }
}
foreach ($commands as $name => $command) {
    // Preserve stderr and partial stdout when an actual benchmark fails.
    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', "{$output}/{$name}.json", 'w'],
        2 => ['file', "{$output}/{$name}.log", 'w'],
    ];
    $process = proc_open(
        [...$command, '--extension', Paths::resolve($options['extension']), '--repeats', (string) $repeats],
        $descriptors,
        $pipes,
        $root,
    );
    $status = $process === false ? 1 : proc_close($process);
    if ($status !== 0) {
        fwrite(STDERR, "{$name} benchmark failed with status {$status}; see {$output}/{$name}.log\n");
        exit(1);
    }
}
file_put_contents(
    "{$output}/environment.json",
    Json::encode(
        [
            'cpu_count' => $cpuCount(),
            'load_before' => $loadBefore,
            'load_after' => sys_getloadavg(),
            'scope' => 'comparative evidence, not proof of an idle host',
            'acceptance' => 'drivers require unchanged application results, complete exact instrumentation, valid CPU/wall measurements and internally consistent sampling; unserved stop-boundary wakes remain disclosed; no universal negligible-overhead threshold is asserted',
        ],
        indent: 2,
        sortKeys: false,
    )
        . "\n",
);
