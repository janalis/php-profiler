<?php

declare(strict_types=1);

// Real pthread embed requests, closure lifetimes, and shared OPcache code proof.
require_once __DIR__ . '/lib/EmbedHarness.php';
require_once __DIR__ . '/lib/ExceptionFixture.php';
require_once __DIR__ . '/lib/Paths.php';

use PhpProfiler\Tooling\EmbedHarness;
use PhpProfiler\Tooling\ExceptionFixture;
use PhpProfiler\Tooling\Paths;

use function PhpProfiler\Tooling\fail;
use function PhpProfiler\Tooling\requiredEnvironment;

$root = Paths::resolve(dirname(__DIR__));
chdir($root);
$library = EmbedHarness::library();
is_file($library) || fail('ZTS embed library is required: ' . $library);
$extension = Paths::resolve(requiredEnvironment('PROFILER_EXTENSION'));
$fixture = "{$root}/tests/support/closure-worker-request.php";
$directory = ExceptionFixture::temporaryDirectory('profiler-zts-threads-');
$executable = "{$directory}/zts_threads";
EmbedHarness::build($library, $executable);
foreach (['disabled', 'opcache'] as $mode) {
    $work = "{$directory}/{$mode}";
    [$output, $receipts] = ["{$work}/reports", "{$work}/receipts"];
    mkdir($work);
    mkdir($output, 0o700);
    mkdir($receipts, 0o700);
    $settings =
        'extension="'
        . EmbedHarness::quoted($extension)
        . "\"\nhtml_errors=0\ndisplay_errors=stderr\n"
        . "log_errors=0\nmax_execution_time=0\nopcache.enable_cli=1\n"
        . "opcache.file_update_protection=0\nopcache.validate_timestamps=0\n"
        . 'opcache.enable='
        . ($mode === 'opcache' ? '1' : '0')
        . "\n";
    if ((string) getenv('PROFILER_EMBED_OPCACHE_EXTENSION') !== '') {
        $settings =
            'zend_extension="'
            . EmbedHarness::quoted((string) getenv('PROFILER_EMBED_OPCACHE_EXTENSION'))
            . "\"\n"
            . $settings;
    }
    file_put_contents("{$work}/php.ini", $settings);
    EmbedHarness::run([$executable, "{$work}/php.ini", $output, $fixture, $receipts]);
    $paths = glob("{$output}/*.json") ?: [];
    $evidence = array_map(
        static fn(string $path): array => json_decode(
            (string) file_get_contents($path),
            true,
            flags: JSON_THROW_ON_ERROR,
        ),
        glob("{$receipts}/*.json") ?: [],
    );
    if (
        count($paths) !== 80
        || count($evidence) !== 80
        || count(array_unique(array_column($evidence, 'token'))) !== 80
    ) {
        fail('expected 80 unique reports and request receipts');
    }
    $edges = [];
    foreach ($paths as $path) {
        $report = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (
            $report['status'] !== 'complete'
            || $report['functions']['zts_profiled_leaf']['calls'] !== 1
            || $report['runtime_metadata']['zts'] !== 'true'
            || $report['runtime_metadata']['sapi'] !== 'embed'
        ) {
            fail('thread/request contamination in ' . $path);
        }
        $rows = [];
        foreach ($report['functions'] as $key => $value) {
            if (str_contains((string) $key, '{closure#')) {
                $rows[] = $value['calls'];
            }
        }
        sort($rows);
        if ($rows !== [...array_fill(0, 20, 2), 24]) {
            fail('shared factory or recycled eval lifetime identities merged');
        }
        foreach ($report['timeline'] as $event) {
            if ($event['symbol'] === 'zts_profiled_leaf') {
                array_push($edges, [$event['start_ns'], 1], [$event['end_ns'], -1]);
                break;
            }
        }
    }
    sort($edges);
    $active = $maximum = 0;
    foreach ($edges as [, $delta]) {
        $active += $delta;
        $maximum = max($maximum, $active);
    }
    $maximum === 4 || fail("expected four actually overlapping captures, measured {$maximum}");
    foreach ($evidence as $item) {
        $item['storage'] === null && fail('test-only SAPI storage observer was not registered');
    }
    for ($request = 0; $request < 20; $request++) {
        $round = array_filter($evidence, static fn(array $item): bool => ($item['token'] % 1000) === $request);
        if (
            count(array_unique(array_map(
                static fn(array $item): string => (string) $item['storage']['runtime_cache'],
                $round,
            ))) !== 4
        ) {
            fail('concurrent threads unexpectedly share writable runtime caches');
        }
    }
    $cached = array_filter(
        $evidence,
        static fn(array $item): bool => !($item['opcache_enabled'] && $item['shared_file_cached']),
    ) === [];
    $opcodeAddresses = array_unique(array_map(
        static fn(array $item): string => (string) $item['storage']['opcodes'],
        $evidence,
    ));
    if ($mode === 'opcache' && $cached) {
        count($opcodeAddresses) === 1 || fail('cached common source did not share compiled closure opcodes');
        $scope = 'OPcache shared opcodes proved; runtime caches distinct across four concurrent threads';
    } elseif ($mode === 'opcache') {
        if (getenv('PROFILER_REQUIRE_EMBED_OPCACHE') === '1') {
            fail('this embed build did not enable shared OPcache; no shared-code proof claimed');
        }
        $scope = 'embed OPcache unavailable; closure/request isolation proved, shared opcodes not claimed';
    } else {
        $scope = 'OPcache disabled; independently compiled closure/request isolation proved';
    }
    echo "{$mode}: 80 exact reports; 21 closure lifetimes/64 calls each; maximum overlap {$maximum}; {$scope}\n";
}
