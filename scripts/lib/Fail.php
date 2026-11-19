<?php

declare(strict_types=1);

namespace PhpProfiler\Tooling;

/** Ends a test driver with a message on standard error and status 1. */
function fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

/** The value of a required environment variable. */
function requiredEnvironment(string $name): string
{
    $value = getenv($name);
    return is_string($value) && $value !== '' ? $value : fail("{$name} is required");
}

// An uncaught failure ends the driver with status 1 and its message, like an uncaught
// Python exception, instead of PHP's fatal-error status 255.
set_exception_handler(static function (\Throwable $failure): never {
    fwrite(STDERR, get_class($failure) . ': ' . $failure->getMessage() . "\n" . $failure->getTraceAsString() . "\n");
    exit(1);
});
