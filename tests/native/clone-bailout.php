<?php

declare(strict_types=1);

$object = profiler_fixture_uncloneable();
$before = profiler_fixture_live_bytes();
$path = $argv[1];
register_shutdown_function(static function () use ($before, $path): void {
    file_put_contents($path, json_encode([
        'before' => $before,
        'after' => profiler_fixture_live_bytes(),
    ], JSON_THROW_ON_ERROR));
});
fwrite(STDOUT, "BEFORE_CLONE\n");
try {
    $clone = clone $object;
    echo "AFTER_CLONE\n";
} catch (Throwable $error) {
    echo "CAUGHT_FATAL\n";
}
