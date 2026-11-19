<?php

declare(strict_types=1);

// Exact Rust allocation counts across repeated FPM Zend OOM and worker reuse.
require_once __DIR__ . '/lib/ExceptionFixture.php';
require_once __DIR__ . '/lib/FpmServer.php';
require_once __DIR__ . '/lib/Json.php';

use PhpProfiler\Tooling\ExceptionFixture;
use PhpProfiler\Tooling\FpmServer;
use PhpProfiler\Tooling\Json;

$fixture = ExceptionFixture::path();
$directory = ExceptionFixture::temporaryDirectory('php-profiler-fpm-ownership-');
if (posix_geteuid() === 0) {
    $worker = posix_getpwnam('www-data') ?: throw new RuntimeException('www-data user missing');
    chown($directory, $worker['uid']) && chgrp($directory, $worker['gid'])
        || throw new RuntimeException('chown ' . $directory);
}
[$initial, $stable, $evidence] = (new FpmServer($fixture))->serve(static function (FpmServer $server) use (
    $directory,
): array {
    $healthy = static function () use ($server): array {
        $value = $server->request('tests/fpm/vendor-healthy.php');
        if ($value['result'] !== 7) {
            throw new RuntimeException('healthy request semantics changed');
        }
        return $value;
    };
    $initial = $healthy();
    $pid = $initial['pid'];
    $stable = null;
    $evidence = [];
    foreach ([6, 7, 6, 7, 6, 7, 6, 7] as $index => $kind) {
        $path = "{$directory}/{$index}-{$kind}.json";
        try {
            $response = $server->requestRaw('tests/fpm/vendor-oom.php', [
                'PROFILE_OWNERSHIP_PATH' => $path,
                'PROFILE_OWNERSHIP_KIND' => (string) $kind,
            ]);
        } catch (Throwable $failure) {
            throw new RuntimeException(
                $failure->getMessage() . "\nOwnership request index={$index}, kind={$kind}, expected PID={$pid}",
                0,
                $failure,
            );
        }
        $text = $response['body'] . $response['stderr'];
        if (
            !preg_match('/WORKER_PID=(\d+)/', $text, $match)
            || (int) $match[1] !== $pid
            || !str_contains($text, 'BEFORE_EXCEPTION')
            || !str_contains($text, 'Allowed memory size')
            || str_contains($text, 'AFTER_EXCEPTION')
            || str_contains($text, 'CAUGHT_FATAL')
        ) {
            throw new RuntimeException("kind {$kind}: expected native OOM/worker control flow absent: {$text}");
        }
        $counts = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if ($counts['pid'] !== $pid || $counts['after'] !== $counts['before']) {
            throw new RuntimeException(
                'Rust-owned exception allocation leaked: ' . Json::encode($counts, sortKeys: false),
            );
        }
        $next = $healthy();
        if ($next['pid'] !== $pid || $next['live_bytes'] !== $counts['after']) {
            throw new RuntimeException('worker restart or allocation accounting changed after fatal request');
        }
        if ($stable !== null && ($counts['before'] !== $stable || $counts['after'] !== $stable)) {
            throw new RuntimeException(
                'steady-state Rust allocation count grew across requests: ' . Json::encode($counts, sortKeys: false),
            );
        }
        $stable = $counts['after'];
        $evidence[] = $counts;
    }
    return [$initial, $stable, $evidence];
});
echo
    Json::encode([
        'result' => 'same FPM worker reclaimed direct/fallback exception owners',
        'scope' => 'test-only extension global Rust allocator; interpreter/native allocators not counted',
        'initial_live_bytes' => $initial['live_bytes'],
        'stable_live_bytes' => $stable,
        'requests' => $evidence,
    ], sortKeys: false),
    "\n"
;
