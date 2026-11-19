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
