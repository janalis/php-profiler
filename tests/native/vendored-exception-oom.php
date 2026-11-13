<?php

declare(strict_types=1);

// Prime the maximum normal fallback cleanup stack capacity before the baseline.
try {
    profiler_fixture_throw(2);
} catch (Error $expected) {
}
$before = profiler_fixture_live_bytes();
$path = $argv[1];
register_shutdown_function(static function () use ($before, $path): void {
    $after = profiler_fixture_live_bytes();
    file_put_contents($path, json_encode(['before' => $before, 'after' => $after], JSON_THROW_ON_ERROR));
});
// PHP 8.5 adds an independent hard kill; retain its soft-limit shutdown path.
if (ini_get('memory_hard_limit_percent') !== false) {
    ini_set('memory_hard_limit_percent', '1000');
}
ini_set('memory_limit', (string) memory_get_usage(true));
fwrite(STDOUT, "BEFORE_EXCEPTION\n");
try {
    profiler_fixture_throw((int) ($argv[2] ?? 6));
    echo "AFTER_EXCEPTION\n";
} catch (Throwable $error) {
    echo "CAUGHT_FATAL\n";
}
