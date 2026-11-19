<?php

declare(strict_types=1);

// Same-worker closure cache lifetimes with actual FPM OPcache, JIT and preload receipts.
require_once __DIR__ . '/lib/Arguments.php';
require_once __DIR__ . '/lib/FpmServer.php';
require_once __DIR__ . '/lib/Json.php';
require_once __DIR__ . '/lib/Process.php';

use PhpProfiler\Tooling\Arguments;
use PhpProfiler\Tooling\FpmServer;
use PhpProfiler\Tooling\Json;
use PhpProfiler\Tooling\Process;

$usage = 'fpm-closure-tests.php [--mode disabled|opcache|jit|preload|preload-jit] [--workers 1|4] [--require-jit] [--require-uid-probe] [--expected-php <major.minor>] [--expected-zts nts|ts]';
['options' => $options, 'positionals' => $positionals] = Arguments::parse(
    $argv,
    ['mode', 'workers', 'expected-php', 'expected-zts'],
    ['require-jit', 'require-uid-probe'],
    $usage,
);
$mode = $options['mode'] ?? 'opcache';
$workers = $options['workers'] ?? '1';
$expectedPhp = $options['expected-php'] ?? null;
$expectedZts = $options['expected-zts'] ?? null;
if (
    $positionals !== []
    || !in_array($mode, ['disabled', 'opcache', 'jit', 'preload', 'preload-jit'], true)
    || !in_array($workers, ['1', '4'], true)
    || !in_array($expectedZts, [null, 'nts', 'ts'], true)
) {
    Arguments::fail('invalid arguments', $usage);
}
$workers = (int) $workers;
$companionVersion = $headerVersion = null;
if ($expectedPhp !== null || $expectedZts !== null) {
    $companionVersion = trim(Process::output([(string) getenv('PHP') ?: 'php', '-n', '-r', 'echo PHP_VERSION;']));
    $headerVersion = trim(Process::output([(string) getenv('PHP_CONFIG') ?: 'php-config', '--version']));
    if ($companionVersion !== $headerVersion) {
        throw new RuntimeException('PHP companion and SDK versions differ before FPM startup');
    }
}
$root = dirname(__DIR__);
chdir($root);
$settings = [
    'opcache.enable' => (int) ($mode !== 'disabled'),
    'opcache.enable_cli' => 1,
    'opcache.file_update_protection' => 0,
    'opcache.validate_timestamps' => 0,
];
if ((string) getenv('PROFILER_FPM_OPCACHE_EXTENSION') !== '') {
    $settings['zend_extension'] = getenv('PROFILER_FPM_OPCACHE_EXTENSION');
}
if (in_array($mode, ['jit', 'preload-jit'], true)) {
    $settings += [
        'opcache.jit' => 'tracing',
        'opcache.jit_buffer_size' => '16M',
        'opcache.jit_hot_func' => 1,
        'opcache.jit_hot_loop' => 1,
    ];
}
if (in_array($mode, ['preload', 'preload-jit'], true)) {
    $settings['opcache.preload'] = realpath($root) . '/tests/support/closure-worker-functions.php';
    if (posix_geteuid() === 0) {
        $settings['opcache.preload_user'] = (string) getenv('PROFILER_FPM_PRELOAD_USER') ?: 'www-data';
    }
}
$requests = array_map(
    static fn(int $index): array => ['tests/fpm/closure-request.php', ['PROFILE_TEST_TOKEN' => (string) $index]],
    range(0, 31),
);
$raw = (new FpmServer(
    (string) getenv('PROFILER_EXTENSION') ?: throw new RuntimeException('PROFILER_EXTENSION is required'),
    $settings,
    $workers,
))->serve(static fn(FpmServer $server): array => $server->concurrent($requests, 8));
$results = [];
foreach ($raw as $index => $response) {
    $payload = FpmServer::decode($response);
    if (
        $payload['id'] !== 1
        || $payload['result'] !== ($index + 1)
        || $payload['checksum'] !== ((4025 * $index) + 549)
    ) {
        throw new RuntimeException('request identity or PHP result leaked');
    }
    $report = $payload['report'];
    $runtime = $report['runtime_metadata'];
    if ($runtime['sapi'] !== 'fpm-fcgi') {
        throw new RuntimeException('FPM request did not report the actual FPM SAPI');
    }
    if ($companionVersion !== null && $runtime['php_version'] !== $companionVersion) {
        throw new RuntimeException('actual FPM version differs from the PHP companion and SDK');
    }
    if (
        $expectedPhp !== null
        && implode('.', array_slice(explode('.', $runtime['php_version']), 0, 2)) !== $expectedPhp
    ) {
        throw new RuntimeException('actual FPM PHP minor differs from its matrix label');
    }
    if ($expectedZts !== null && $runtime['zts'] !== ($expectedZts === 'ts' ? 'true' : 'false')) {
        throw new RuntimeException('actual FPM ZTS mode differs from its matrix label');
    }
    $rows = [];
    foreach ($report['functions'] as $symbol => $metric) {
        if (str_contains((string) $symbol, '{closure#')) {
            $rows[] = $metric['calls'];
        }
    }
    sort($rows);
    if ($report['status'] !== 'complete' || $rows !== [...array_fill(0, 20, 2), 24]) {
        throw new RuntimeException('closure rows/call counts merged across FPM requests');
    }
    if ($mode !== 'disabled' && !($payload['opcache_enabled'] && $payload['shared_file_cached'])) {
        throw new RuntimeException('requested FPM shared OPcache was not actually active/cached');
    }
    if (in_array($mode, ['preload', 'preload-jit'], true) && !$payload['preloaded']) {
        throw new RuntimeException('preload configuration did not provide persistent functions before include');
    }
    $results[] = $payload;
}
$pids = array_values(array_unique(array_column($results, 'pid')));
sort($pids);
$runtimes = array_unique(array_map(static fn(array $item): string => json_encode([
    $item['report']['runtime_metadata']['php_version'],
    $item['report']['runtime_metadata']['sapi'],
    $item['report']['runtime_metadata']['zts'],
]), $results));
if (count($runtimes) !== 1) {
    throw new RuntimeException('FPM worker pool mixed PHP runtime identities');
}
[$phpVersion, $sapi, $zts] = json_decode((string) reset($runtimes), true);
if (count($pids) !== $workers) {
    throw new RuntimeException('FPM did not preserve the expected worker pool during repeated request proof');
}
$perWorker = [];
foreach ($results as $item) {
    $perWorker[$item['pid']] = ($perWorker[$item['pid']] ?? 0) + 1;
}
if (min($perWorker) < 2) {
    throw new RuntimeException('every FPM worker must prove request-state reuse');
}
$edges = [];
foreach ($results as $item) {
    $event = null;
    foreach ($item['report']['timeline'] as $candidate) {
        if ($candidate['symbol'] === 'zts_profiled_leaf') {
            $event = $candidate;
            break;
        }
    }
    if ($event === null) {
        throw new RuntimeException('profiled leaf event missing');
    }
    if ($event['end_ns'] <= $event['start_ns']) {
        throw new RuntimeException('profiled leaf lacks a positive measured wall interval');
    }
    array_push($edges, [$event['start_ns'], 1], [$event['end_ns'], -1]);
}
sort($edges);
$active = $maximum = 0;
foreach ($edges as [, $delta]) {
    $active += $delta;
    $maximum = max($maximum, $active);
}
if ($active !== 0 || $maximum !== $workers) {
    throw new RuntimeException('measured application intervals did not prove actual FPM pool concurrency');
}
$jit = array_values(array_filter(
    $results,
    static fn(array $item): bool => $item['jit'] && ($item['jit']['enabled'] ?? false) && ($item['jit']['on'] ?? false),
));
$consumed = 0;
foreach ($jit as $item) {
    if ($item['jit_before']) {
        $consumed += max(0, $item['jit_before']['buffer_free'] - $item['jit']['buffer_free']);
    }
}
if (isset($options['require-jit']) && (count($jit) !== 32 || $consumed === 0)) {
    throw new RuntimeException('requested proof requires actual FPM JIT on plus generated-code buffer consumption');
}
$probe = array_values(array_filter(
    array_column($results, 'storage'),
    static fn(mixed $storage): bool => is_array($storage) && $storage !== [] && array_key_exists('uid', $storage),
));
if (isset($options['require-uid-probe']) && count($probe) !== 32) {
    throw new RuntimeException('CUNIT read-only closure UID probe is required for raw cache sequence proof');
}
if ($probe !== []) {
    foreach ($probe as $item) {
        if ((int) $item['uid'] === 0) {
            throw new RuntimeException('a called shared closure had no issued UID');
        }
    }
    // Either per-request caches get fresh IDs, or a retained cache keeps its own
    // existing ID; both must preserve all independent eval row counts.
    $distinct = static fn(string $field): int => count(array_unique(array_map(
        static fn(array $item): string => json_encode($item[$field]),
        $probe,
    )));
    $uidScope = [
        'observations' => count($probe),
        'distinct_uids' => $distinct('uid'),
        'distinct_runtime_cache_addresses' => $distinct('runtime_cache'),
        'distinct_opcode_addresses' => $distinct('opcodes'),
        'scope' => 'UID identity is process/cache-local; no cross-process uniqueness claim',
    ];
} else {
    $uidScope = 'normal runtime exposes no raw cache IDs; exact row/count/preload assertions only';
}
$sum = static fn(string $field): int => array_sum(array_map(
    static fn(array $item): int => (int) (bool) $item[$field],
    $results,
));
echo
    Json::encode(
        [
            'mode' => $mode,
            'requests' => 32,
            'workers' => $workers,
            'worker_pids' => $pids,
            'requests_per_worker' => (object) array_combine(
                array_map(strval(...), array_keys($perWorker)),
                array_values($perWorker),
            ),
            'maximum_overlapping_leaf_intervals' => $maximum,
            'same_worker_pid' => $workers === 1 ? $pids[0] : null,
            'php_version' => $phpVersion,
            'sapi' => $sapi,
            'zts' => $zts === 'true',
            'php_companion_version' => $companionVersion,
            'php_config_version' => $headerVersion,
            'closure_rows_per_report' => 21,
            'closure_calls_per_report' => 64,
            'opcache_active' => $sum('opcache_enabled'),
            'shared_file_cached' => $sum('shared_file_cached'),
            'functions_preloaded' => $sum('preloaded'),
            'jit_on_requests' => count($jit),
            'jit_generated_bytes_observed' => $consumed,
            'uid_cache_scope' => $uidScope,
        ],
        indent: 2,
        sortKeys: false,
    ),
    "\n"
;
