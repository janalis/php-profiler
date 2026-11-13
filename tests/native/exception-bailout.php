<?php

declare(strict_types=1);

// Prime cleanup capacity using the normal real fallback, then take a stable baseline.
try {
    profiler_fixture_throw(2);
} catch (Error $expected) {
}
$before = profiler_fixture_live_bytes();
$path = $argv[1];
register_shutdown_function(static function () use ($before, $path): void {
    file_put_contents($path, json_encode([
        'before' => $before,
        'after' => profiler_fixture_live_bytes(),
    ], JSON_THROW_ON_ERROR));
});
fwrite(STDOUT, "BEFORE_EXCEPTION\n");
try {
    profiler_fixture_throw((int) $argv[2]);
    echo "AFTER_EXCEPTION\n";
} catch (Throwable $error) {
    echo "CAUGHT_FATAL\n";
}
