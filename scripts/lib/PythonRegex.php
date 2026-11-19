<?php

declare(strict_types=1);

namespace PhpProfiler\Tooling;

/** Python regular-expression helpers whose output other tools consume verbatim. */
final class PythonRegex
{
    /** Python's re.escape(): a backslash before each of its special characters. */
    public static function escape(string $text): string
    {
        return (string) preg_replace_callback(
            "/[()\\[\\]{}?*+\\-|^$\\\\.&~# \t\n\r\x0b\x0c]/",
            static fn(array $match): string => '\\' . $match[0],
            $text,
        );
    }
}
