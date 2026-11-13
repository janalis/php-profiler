<?php

declare(strict_types=1);

// Prime the largest normal fallback cleanup stack before measuring ownership.
try {
    profiler_fixture_throw(2);
} catch (Error $expected) {
}
$before = profiler_fixture_live_bytes();
$path = $_SERVER['PROFILE_OWNERSHIP_PATH'];
$kind = (int) $_SERVER['PROFILE_OWNERSHIP_KIND'];
register_shutdown_function(static function () use ($before, $path, $kind): void {
    $after = profiler_fixture_live_bytes();
    file_put_contents($path, json_encode([
        'pid' => getmypid(),
        'before' => $before,
        'after' => $after,
        'kind' => $kind,
    ], JSON_THROW_ON_ERROR));
});
echo 'WORKER_PID=' . getmypid() . "\n";
// Stay below PHP's hard allocation limit while forcing its recoverable soft OOM.
$softLimit = 16 * 1024 * 1024;
ini_set('memory_limit', (string) $softLimit);
$padding = [];
while (memory_get_usage() < ($softLimit * 0.7)) {
    $padding[] = str_repeat('p', 1024);
}
echo "BEFORE_EXCEPTION\n";
try {
    profiler_fixture_throw($kind);
    echo "AFTER_EXCEPTION\n";
} catch (Throwable $failure) {
    echo "CAUGHT_FATAL\n";
}
