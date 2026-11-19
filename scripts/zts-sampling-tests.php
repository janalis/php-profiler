<?php

declare(strict_types=1);

// Genuine Linux ZTS embed sampler requests, native retirement, and DSO lifetime.
require_once __DIR__ . '/lib/Arguments.php';
require_once __DIR__ . '/lib/AtforkWrapper.php';
require_once __DIR__ . '/lib/Fail.php';
require_once __DIR__ . '/lib/Json.php';
require_once __DIR__ . '/lib/Paths.php';
require_once __DIR__ . '/lib/ProcessGroups.php';
require_once __DIR__ . '/lib/Shlex.php';
require_once __DIR__ . '/lib/ValidationInputs.php';

use PhpProfiler\Tooling\Arguments;
use PhpProfiler\Tooling\AtforkWrapper;
use PhpProfiler\Tooling\Json;
use PhpProfiler\Tooling\Paths;
use PhpProfiler\Tooling\ProcessGroups;
use PhpProfiler\Tooling\Shlex;
use PhpProfiler\Tooling\ValidationInputs;

$root = Paths::resolve(dirname(__DIR__));
$usage = 'zts-sampling-tests.php [--output <directory>] [--extension <path>]';
['options' => $options, 'positionals' => $positionals] = Arguments::parse(
    $argv,
    ['output', 'extension'],
    usage: $usage,
);
if ($positionals !== []) {
    Arguments::fail('unexpected positional arguments', $usage);
}
$libc = PHP_OS_FAMILY === 'Linux'
    ? preg_split('/\s+/', trim((string) shell_exec('getconf GNU_LIBC_VERSION 2>/dev/null')))
    : [];
if (PHP_OS_FAMILY !== 'Linux' || ($libc[0] ?? '') !== 'glibc') {
    throw new RuntimeException('this mandatory sampler lifecycle gate requires Linux glibc');
}
$output = Paths::resolve((string) ($options['output'] ?? "{$root}/target/zts-sampling"));
if (!is_dir($output)) {
    mkdir($output, 0o777, true);
}
$extension = Paths::resolve(
    (string) (
        $options['extension'] ?? ((string) getenv('PROFILER_EXTENSION') ?: "{$root}/target/release/libphp_profiler.so")
    ),
);
if (!is_file($extension) || basename($extension) !== 'libphp_profiler.so') {
    throw new RuntimeException('the unrenamed Cargo libphp_profiler.so artifact is required');
}
$processes = new ProcessGroups();
$commands = [];
$run = static function (array $command, ?string $log = null, ?array $environment = null, float $timeout = 60) use (
    $processes,
    $root,
    $output,
    &$commands,
): string {
    $commands[] = $command;
    $result = $processes->run($command, $timeout, $environment, $root, capture: true);
    $combined = $result->stdout . $result->stderr;
    if ($log !== null) {
        file_put_contents("{$output}/{$log}", $combined);
    }
    if ($result->status !== 0) {
        throw new RuntimeException(
            "command exited {$result->status}: "
            . implode(' ', array_map(escapeshellarg(...), $command))
            . "\n{$combined}",
        );
    }
    return (string) $result->stdout;
};
$before = ValidationInputs::snapshot($root);
$receipt = [
    'passed' => false,
    'source_before' => (object) ValidationInputs::inPathOrder($before),
    'extension_sha256' => hash_file('sha256', $extension),
    'commands' => &$commands,
    'jit_or_opcache_exercised' => false,
];
try {
    $config = (string) getenv('PHP_CONFIG') ?: 'php-config';
    $php = (string) getenv('PHP') ?: 'php';
    $metadata = json_decode(
        $run([$php, '-n', '-r', 'echo json_encode(["version"=>PHP_VERSION,"zts"=>PHP_ZTS]);']),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $sdkVersion = trim($run([$config, '--version']));
    if (!$metadata['zts'] || $metadata['version'] !== $sdkVersion) {
        throw new RuntimeException('PHP ZTS executable and SDK versions must match');
    }
    $prefix = trim($run([$config, '--prefix']));
    $library = Paths::resolve((string) getenv('PROFILER_EMBED_LIBRARY') ?: "{$prefix}/lib/libphp.so");
    if (!is_file($library)) {
        throw new RuntimeException('matching SDK embed library is required: ' . $library);
    }
    $includes = Shlex::split($run([$config, '--includes']));
    $cc = Shlex::split((string) getenv('CC') ?: 'cc');
    [$executable, $observer] = ["{$output}/sampling_zts_threads", "{$output}/sampling_pthreads.so"];
    $run([
        ...$cc,
        '-std=c11',
        ...$includes,
        "{$root}/tests/native/sampling_zts_threads.c",
        $library,
        '-lpthread',
        '-ldl',
        '-Wl,-rpath,' . dirname($library),
        '-o',
        $executable,
    ], 'embed-build.log');
    $run([
        ...$cc,
        '-shared',
        '-fPIC',
        '-std=c11',
        "{$root}/tests/native/sampling_pthreads.c",
        '-lpthread',
        '-ldl',
        '-o',
        $observer,
    ], 'observer-build.log');
    $reports = "{$output}/request-receipts";
    if (!is_dir($reports)) {
        mkdir($reports);
    }
    foreach (glob("{$reports}/*.json") ?: [] as $old) {
        unlink($old);
    }
    $ini = "{$output}/php.ini";
    $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $extension);
    file_put_contents(
        $ini,
        "extension=\"{$escaped}\"\nhtml_errors=0\ndisplay_errors=stderr\nlog_errors=0\nmax_execution_time=0\n",
    );
    $environment = getenv();
    $environment['PHP_INI_SCAN_DIR'] = '';
    $environment['LD_PRELOAD'] =
        $observer . (($environment['LD_PRELOAD'] ?? '') !== '' ? ':' . $environment['LD_PRELOAD'] : '');
    $text = $run(
        [$executable, $ini, $extension, "{$root}/tests/support/sampling-worker-request.php", $reports],
        'lifecycle.log',
        $environment,
        120,
    );
    $evidence = array_map(
        static fn(string $path): array => json_decode(
            (string) file_get_contents($path),
            true,
            flags: JSON_THROW_ON_ERROR,
        ),
        glob("{$reports}/*.json") ?: [],
    );
    $invalid = array_filter(
        $evidence,
        static fn(array $row): bool => (
            $row['id'] !== 1
            || $row['calls'] !== 2
            || $row['samples'] < 2
            || count($row['contexts']) < 2
        ),
    );
    if (count($evidence) !== 20 || $invalid !== []) {
        throw new RuntimeException('expected twenty isolated real sampled main/Fiber captures');
    }
    foreach ([
        'created=20 actual_joins=20 live=0 peak=4 invalid_attrs=0',
        'created=22 actual_joins=22 live=0 peak=4 invalid_attrs=0',
        'created=23 actual_joins=23 live=0 peak=4 invalid_attrs=0',
        'actual DSO unload/postshutdown fork',
    ] as $expected) {
        if (!str_contains($text, $expected)) {
            throw new RuntimeException('native retirement evidence missing: ' . $expected);
        }
    }
    $cycles = "{$output}/sampling_module_cycles";
    $run([
        ...$cc,
        '-std=c11',
        ...$includes,
        "{$root}/tests/native/sampling_module_cycles.c",
        $library,
        '-lpthread',
        '-Wl,-rpath,' . dirname($library),
        '-o',
        $cycles,
    ], 'module-cycles-build.log');
    $cycleLog = $run([$cycles, $ini], 'module-cycles.log', $environment, 30);
    if (
        !str_contains($cycleLog, 'module cycle 1 + retained-DSO fork passed')
        || !str_contains($cycleLog, 'module cycle 2 + retained-DSO fork passed')
    ) {
        throw new RuntimeException('same-owner repeated module lifecycle/fork evidence missing');
    }
    // The actual Cargo-linked DSO must use glibc's DSO-local atfork wrapper.
    AtforkWrapper::verify(
        $run(['nm', '-a', $extension], 'symbols.log'),
        $run(['objdump', '-d', $extension], 'disassembly.log'),
    );
    $receipt += [
        'php' => $metadata,
        'sdk_version' => $sdkVersion,
        'php_source_sha256' => getenv('PHP_SHA256') === false ? null : getenv('PHP_SHA256'),
        'configure_options' => trim($run([$config, '--configure-options'])),
        'libc' => $libc,
        'compiler' => explode("\n", $run([...$cc, '--version']))[0],
        'rustc' => trim($run(['rustc', '--version'])),
        'embed_library_sha256' => hash_file('sha256', $library),
        'reports' => count($evidence),
        'actual_samples' => array_sum(array_column($evidence, 'samples')),
        'created_workers' => 23,
        'actual_joins' => 23,
        'peak_overlap' => 4,
        'live_workers' => 0,
        'invalid_attrs' => 0,
        'rshutdown_recovery' => true,
        'actual_tsrm_fallback' => true,
        'normal_tls_retirement_then_actual_unload' => true,
        'post_unload_forks' => 3,
        'same_owner_module_cycles' => 2,
        'retained_dso_forks_between_cycles' => 2,
    ];
    $after = ValidationInputs::snapshot($root);
    $receipt['source_after'] = (object) ValidationInputs::inPathOrder($after);
    $receipt['extension_after_sha256'] = hash_file('sha256', $extension);
    if ($after !== $before || $receipt['extension_after_sha256'] !== $receipt['extension_sha256']) {
        throw new RuntimeException('validation input or extension changed during sampler lifecycle proof');
    }
    $receipt['passed'] = true;
    echo "Linux ZTS sampler: 20 isolated reports, 23 actual worker joins, peak4, TLS/DSO/fork passed\n";
} finally {
    file_put_contents("{$output}/receipt.json", Json::encode($receipt, indent: 2, sortKeys: false) . "\n");
    $processes->close();
}
