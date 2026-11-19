<?php

declare(strict_types=1);

// Ordinary sampled CPU workload under actual shared OPcache and tracing JIT. CLI and
// genuine reused FPM workers are distinct receipts. The workload uses no private
// controller exports, fake observations or profiler-specific fault transports.
require_once __DIR__ . '/lib/Arguments.php';
require_once __DIR__ . '/lib/Benchmark.php';
require_once __DIR__ . '/lib/FpmServer.php';
require_once __DIR__ . '/lib/Json.php';
require_once __DIR__ . '/lib/Paths.php';
require_once __DIR__ . '/lib/Process.php';

use PhpProfiler\Tooling\Arguments;
use PhpProfiler\Tooling\Benchmark;
use PhpProfiler\Tooling\FpmServer;
use PhpProfiler\Tooling\Json;
use PhpProfiler\Tooling\Paths;
use PhpProfiler\Tooling\Process;

$usage = 'sampling-jit-tests.php [--extension <path>] [--fpm] [--expected-php <major.minor>] [--expected-zts nts|ts] [--out <path>]';
['options' => $options, 'positionals' => $positionals] = Arguments::parse(
    $argv,
    ['extension', 'expected-php', 'expected-zts', 'out'],
    ['fpm'],
    $usage,
);
$extensionOption = $options['extension'] ?? ((string) getenv('PROFILER_EXTENSION') ?: null);
$expectedPhp = $options['expected-php'] ?? null;
$expectedZts = $options['expected-zts'] ?? null;
$fpm = isset($options['fpm']);
if ($positionals !== [] || $extensionOption === null || !in_array($expectedZts, [null, 'nts', 'ts'], true)) {
    Arguments::fail('--extension (or PROFILER_EXTENSION) is required', $usage);
}
$root = Paths::resolve(dirname(__DIR__));
chdir($root);
$php = (string) getenv('PHP') ?: 'php';
$extension = Paths::resolve($extensionOption);
$version = trim(Process::output([$php, '-n', '-r', 'echo PHP_VERSION;']));
$headers = trim(Process::output([(string) getenv('PHP_CONFIG') ?: 'php-config', '--version']));
if ($version !== $headers) {
    throw new RuntimeException('PHP companion and SDK differ');
}
$loadOpcache = Process::run([$php, '-n', '-r', "exit(function_exists('opcache_get_status') ? 0 : 1);"])['status'] !== 0;
$inputs = [];
foreach ([
    'scripts/sampling-jit-tests.php',
    'scripts/lib/Benchmark.php',
    'tests/support/sampling-workload.php',
    'tests/native/sampling-jit.php',
    'tests/fpm/sampling-request.php',
] as $name) {
    $inputs[$name] = hash_file('sha256', "{$root}/{$name}");
}
$result = [
    'scope' => $fpm ? 'actual FPM worker reuse' : 'CLI companion',
    'extension_sha256' => hash_file('sha256', $extension),
    'php_config_version' => $headers,
    'inputs' => $inputs,
    'cases' => [],
];
$validate = static function (array $receipt, int $token) use ($version, $expectedPhp, $expectedZts, $fpm): void {
    $runtime = $receipt['runtime'];
    if ($runtime['php_version'] !== $version) {
        throw new RuntimeException('actual SAPI version differs from companion/SDK');
    }
    if (
        $expectedPhp !== null
        && implode('.', array_slice(explode('.', $runtime['php_version']), 0, 2)) !== $expectedPhp
    ) {
        throw new RuntimeException('actual PHP minor differs from requested label');
    }
    if ($expectedZts !== null && $runtime['zts'] !== ($expectedZts === 'ts' ? 'true' : 'false')) {
        throw new RuntimeException('actual PHP ZTS mode differs from requested label');
    }
    if ($runtime['sapi'] !== ($fpm ? 'fpm-fcgi' : 'cli')) {
        throw new RuntimeException('actual SAPI differs from receipt scope');
    }
    if (
        $receipt['token'] !== $token
        || $receipt['session_id'] !== 1
        || $receipt['calls'] < 1
        || $receipt['checksum'] !== ($receipt['calls'] * (3500000 + $token))
        || $receipt['samples'] < 1
        || !$receipt['cached']
    ) {
        throw new RuntimeException('sampling ownership, workload, or cached execution invalid');
    }
    Benchmark::validateSampling(
        [
            'sampling_statuses' => [$receipt['sampling_status'] => 1],
            'sampling_samples' => $receipt['samples'],
            'sampling_reports' => [$receipt['sampling_report']],
        ],
        'enabled',
        true,
        1,
        'calls',
    );
};
foreach (['opcache', 'jit'] as $mode) {
    $settings = [
        'opcache.enable' => 1,
        'opcache.enable_cli' => 1,
        'opcache.file_update_protection' => 0,
        'opcache.validate_timestamps' => 0,
        'opcache.jit' => $mode === 'jit' ? 'tracing' : 'off',
        'opcache.jit_buffer_size' => $mode === 'jit' ? '32M' : '0',
        'opcache.jit_hot_func' => 1,
        'opcache.jit_hot_loop' => 1,
    ];
    if ($loadOpcache) {
        $settings['zend_extension'] = 'opcache';
    }
    $receipts = [];
    if ($fpm) {
        $receipts = (new FpmServer($extension, $settings))->serve(static function (FpmServer $server) use (
            $mode,
            $validate,
        ): array {
            $receipts = [];
            for ($token = 0; $token < 12; $token++) {
                $receipts[] = $server->request('tests/fpm/sampling-request.php', [
                    'PROFILE_TEST_TOKEN' => (string) $token,
                    'PROFILE_TEST_JIT' => $mode === 'jit' ? '1' : '0',
                ]);
                $validate($receipts[count($receipts) - 1], $token);
            }
            return $receipts;
        });
        if (count(array_unique(array_column($receipts, 'pid'))) !== 1) {
            throw new RuntimeException('FPM failed to reuse one worker across sampled requests');
        }
    } else {
        $command = [$php, '-n'];
        foreach ($settings as $key => $value) {
            array_push($command, '-d', "{$key}={$value}");
        }
        array_push($command, '-d', 'extension=' . $extension, 'tests/native/sampling-jit.php', $mode);
        $completed = Process::run($command, timeout: 120);
        if ($completed['status'] !== 0) {
            throw new RuntimeException('sampled CLI subprocess failed: ' . $completed['stdout'] . $completed['stderr']);
        }
        $receipts[] = json_decode($completed['stdout'], true, flags: JSON_THROW_ON_ERROR);
        $validate($receipts[0], 17);
    }
    $consumed = 0;
    foreach ($receipts as $item) {
        [$before, $after] = [$item['jit_before'], $item['jit_after']];
        if ($mode === 'jit') {
            if (!is_array($before) || !is_array($after) || !($after['on'] ?? false) || ($after['kind'] ?? null) !== 5) {
                throw new RuntimeException('actual tracing JIT is not active');
            }
            [$old, $new] = [$before['buffer_free'] ?? null, $after['buffer_free'] ?? null];
            if (!is_int($old) || !is_int($new) || $new < 0 || $old < $new) {
                throw new RuntimeException('invalid or regressing JIT code-buffer counters');
            }
            $consumed += $old - $new;
        } elseif ($after && ($after['on'] ?? false)) {
            throw new RuntimeException('OPcache-only workload unexpectedly enabled JIT');
        }
    }
    if ($mode === 'jit' && $consumed === 0) {
        throw new RuntimeException('sampled workload did not prove actual tracing-JIT code generation');
    }
    $result['cases'][] = [
        'mode' => $mode,
        'requests' => count($receipts),
        'jit_generated_bytes_observed' => $consumed,
        'receipts' => $receipts,
    ];
}
if (hash_file('sha256', $extension) !== $result['extension_sha256']) {
    throw new RuntimeException('extension changed during ordinary sampling compatibility proof');
}
foreach ($result['inputs'] as $name => $digest) {
    if (hash_file('sha256', "{$root}/{$name}") !== $digest) {
        throw new RuntimeException('sampling compatibility inputs changed during proof');
    }
}
$result['sampling_acceptance'] = 'exact complete call graph, positive actual samples, valid stopped counter snapshots; only explicitly measured unserved boundary wakes may truncate sampling, with all raw statuses/counters retained';
$result['complete'] = true;
$encoded = Json::encode($result, indent: 2, sortKeys: false);
if (isset($options['out'])) {
    file_put_contents($options['out'], $encoded . "\n");
}
echo $encoded, "\n";
