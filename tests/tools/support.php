<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/scripts/lib/Json.php';
require_once dirname(__DIR__, 2) . '/scripts/lib/Process.php';

const ROOT = __DIR__ . '/../..';

/** Creates an empty temporary directory that is removed at shutdown. */
function temporaryDirectory(): string
{
    $path = sys_get_temp_dir() . '/php-profiler-tools-' . bin2hex(random_bytes(8));
    mkdir($path, 0o700);
    register_shutdown_function(static function () use ($path): void {
        if (str_contains($path, '/php-profiler-tools-') && is_dir($path)) {
            exec('rm -rf ' . escapeshellarg($path));
        }
    });
    return (string) realpath($path);
}

function writeFile(string $path, string $contents, int $mode = 0o644): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0o777, true);
    }
    file_put_contents($path, $contents);
    chmod($path, $mode);
}

/** @param array<string, string> $environment */
function withEnvironment(array $environment): array
{
    return array_merge(getenv(), $environment);
}
