<?php

declare(strict_types=1);

// Fatal requests must finalize safely and leave the same FPM worker usable.
require_once __DIR__ . '/lib/ExceptionFixture.php';
require_once __DIR__ . '/lib/FpmServer.php';
require_once __DIR__ . '/lib/Json.php';

use PhpProfiler\Tooling\ExceptionFixture;
use PhpProfiler\Tooling\FpmServer;
use PhpProfiler\Tooling\Json;

$directory = ExceptionFixture::temporaryDirectory('php-profiler-fpm-reports-');
// Private writable reports for the same www-data worker the pool uses.
if (posix_geteuid() === 0) {
    $worker = posix_getpwnam('www-data') ?: throw new RuntimeException('www-data user missing');
    chown($directory, $worker['uid']) && chgrp($directory, $worker['gid'])
        || throw new RuntimeException('chown ' . $directory);
}
$cases = (new FpmServer(
    (string) getenv('PROFILER_EXTENSION') ?: throw new RuntimeException('PROFILER_EXTENSION is required'),
))->serve(static function (FpmServer $server) use ($directory): array {
    $healthy = static function () use ($server): int {
        $value = $server->request('tests/fpm/request.php', ['PROFILE_TEST_INPUT' => 'request']);
        if (
            $value['id'] !== 1
            || $value['result'] !== 7
            || $value['report']['functions']['fpm_profiled_leaf']['calls'] !== 1
        ) {
            throw new RuntimeException('healthy request inherited state from a failed request');
        }
        return $value['pid'];
    };
    $pid = $healthy();
    $cases = [];
    foreach ([
        ...['exception', 'exit', 'oom-json', 'oom-array'],
        ...['exception', 'exit', 'oom-json', 'oom-array'],
    ] as $index => $mode) {
        $path = "{$directory}/{$index}-{$mode}.json";
        $response = $server->requestRaw('tests/fpm/fatal.php', [
            'PROFILE_FATAL_MODE' => $mode,
            'PROFILE_REPORT_PATH' => $path,
        ]);
        $text = $response['body'];
        if (
            !preg_match('/WORKER_PID=(\d+)/', $text, $match)
            || (int) $match[1] !== $pid
            || str_contains($text, 'UNEXPECTED_CONTINUATION')
        ) {
            throw new RuntimeException("{$mode}: fatal request worker identity/continuation changed: {$text}");
        }
        $diagnostic = $text . $response['stderr'];
        if (
            str_starts_with($mode, 'oom-')
            && (
                !str_contains($diagnostic, 'Allowed memory size')
                || !str_contains($text, 'BEFORE_REPORT')
                || str_contains($text, 'AFTER_REPORT')
                || str_contains($text, 'CAUGHT_FATAL')
            )
        ) {
            throw new RuntimeException("{$mode}: OOM request did not follow fatal control flow: {$text}");
        }
        $report = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (
            $report['status'] !== 'incomplete'
            || $report['termination_reason'] !== 'request_shutdown'
            || $report['functions']['fpm_fatal_leaf']['calls'] !== 1
        ) {
            throw new RuntimeException("{$mode}: unfinished session not persisted at request shutdown");
        }
        if ($healthy() !== $pid) {
            throw new RuntimeException("{$mode}: FPM replaced the worker instead of recovering its request state");
        }
        $cases[] = ['mode' => $mode, 'report_status' => $report['status'], 'worker_pid' => $pid];
    }
    return $cases;
});
echo
    Json::encode(['result' => 'same worker recovered after all fatal requests', 'cases' => $cases], sortKeys: false),
    "\n"
;
