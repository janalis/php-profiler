<?php

declare(strict_types=1);

// Exercises private Rust adapter contracts inside real initialized Zend requests.
require_once __DIR__ . '/lib/Paths.php';
require_once __DIR__ . '/lib/Process.php';

use PhpProfiler\Tooling\Paths;
use PhpProfiler\Tooling\Process;

$root = Paths::resolve(dirname(__DIR__));
chdir($root);
$php = Paths::which((string) getenv('PHP') ?: 'php') ?? ((string) getenv('PHP') ?: 'php');
$configured = (string) getenv('PROFILER_CONTRACT_EXTENSION');
if ($configured !== '') {
    $extension = Paths::resolve($configured);
} else {
    $build = [...Process::environment(), 'PROFILER_ADAPTER_CONTRACT_TEST' => '1', 'PROFILER_SAMPLING_UNIT' => '1'];
    $target = ((string) getenv('CARGO_TARGET_DIR') ?: "{$root}/target") . '/adapter-contract';
    $cargo = [(string) getenv('CARGO') ?: 'cargo'];
    if ((string) getenv('PROFILER_COVERAGE_TOOLCHAIN') !== '') {
        $cargo[] = '+' . getenv('PROFILER_COVERAGE_TOOLCHAIN');
    }
    Process::check([...$cargo, 'build', '-p', 'php-profiler', '--target-dir', $target], $build);
    $extension = Paths::resolve("{$target}/debug/libphp_profiler." . (PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so'));
}
$environment = Process::childEnvironment();
$run = static fn(array $arguments, float $timeout = 30.0) => Process::check(
    [$php, '-n', '-d', 'extension=' . $extension, ...$arguments],
    $environment,
    timeout: $timeout,
);
if (getenv('PROFILER_C_UNIT_BOUNDARIES') === '1') {
    $run(['tests/native/fiber-observations.php']);
    $run(['tests/native/fiber-tracking.php']);
}
$probe = 'if (!profiler_adapter_contract_probe($argv[1])) { throw new RuntimeException("contract probe failed"); }';
foreach ([
    'disable-shared-boundary',
    'native-identity-failure',
    'reentrant-request-start',
    'identity-max',
    'nul-paths',
    'reentrant-callbacks',
    'unterminated-resolver',
    'metadata-omission',
    'capture-initialization-quota',
    'uninitialized-storage',
    'native-error-causes',
    'serialization-limit',
] as $mode) {
    $run(['-r', $probe, $mode]);
    echo "{$mode}: native private adapter contract passed\n";
}
$base = [$php, '-n'];
if (Process::run([...$base, '-r', "exit(extension_loaded('Zend OPcache') ? 0 : 1);"], $environment)['status'] !== 0) {
    array_push($base, '-d', 'zend_extension=opcache');
}
Process::check(
    [...$base, '-d', 'extension=' . $extension, 'tests/native/adapter-sealed.php'],
    $environment,
    timeout: 30,
);
Process::check(
    [
        ...$base,
        '-d',
        'extension=' . $extension,
        '-r',
        "function fresh_request_leaf(){return 41;} \$s=PhpProfiler\\Profiler::start(); if(fresh_request_leaf()!==41)throw new RuntimeException('semantics'); \$s->stop(); \$r=\$s->getReport(); if(\$r['status']!=='complete'||\$r['functions']['fresh_request_leaf']['calls']!==1)throw new RuntimeException('fresh request identity recovery failed'); echo 'fresh request identity recovery passed',PHP_EOL;",
    ],
    $environment,
    timeout: 30,
);
// Synthetic byte descriptors and counter failures inside real Zend startup. These are
// private unit contracts, never runtime performance samples or fake frames.
$samplingProbe = 'if (!profiler_sampling_contract_probe($argv[1])) { throw new RuntimeException("sampling contract probe failed"); }';
foreach ([
    'freeze-dependencies',
    'start-boundary',
    'renderer',
    'mapper-admission',
    'tracker-faults',
    'callback-guard',
    'observer-defense',
    'retry-metadata',
    'freeze-boundaries',
    'admission-rollback',
] as $mode) {
    $run(['-r', $samplingProbe, $mode], 60);
    echo "{$mode}: private sampling provider-boundary contract passed\n";
}
$run([
    '-r',
    'try { profiler_sampling_contract_probe("unsupported-config"); throw new RuntimeException("unsupported dependency was accepted"); } '
        . 'catch (PhpProfiler\ConfigurationException $expected) { '
        . 'if (!str_contains($expected->getMessage(), "Linux glibc 2.32")) { throw new RuntimeException("unexpected configuration failure", 0, $expected); } '
        . 'echo "unsupported sampler dependency preserves typed configuration failure", PHP_EOL; }',
]);
$run(['tests/native/sampling-map-lifetime.php']);
$run([
    '-r',
    'if (!profiler_adapter_contract_probe("automatic")) throw new RuntimeException("automatic lifecycle contract failed");',
]);
