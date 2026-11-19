<?php

declare(strict_types=1);

namespace PhpProfiler\Tooling;

use RuntimeException;

/** Rejects captured sanitizer failures even in intentionally non-zero PHP cases. */
final class SanitizerDiagnostics
{
    private const string ANSI_CSI = '/\x1b\[[0-?]*[ -\/]*[@-~]/';
    private const string PREFIX = '^[ \t]*(?:==[0-9]+==[ \t]*)?';

    /** No environment switch or expected PHP fatal may hide a sanitizer report. */
    public static function reject(string $output, string $context): void
    {
        $failure =
            '/'
            . self::PREFIX
            . '(?:ERROR|SUMMARY|WARNING):[ \t]*(?:AddressSanitizer|LeakSanitizer)\b'
            . '|'
            . self::PREFIX
            . '(?:AddressSanitizer|LeakSanitizer)[ \t]*:[ \t]*DEADLYSIGNAL\b'
            . '|'
            . self::PREFIX
            . '(?:AddressSanitizer|LeakSanitizer)[ \t]+CHECK failed\b'
            . '|'
            . self::PREFIX
            . 'LeakSanitizer has encountered a fatal error\b'
            . '|'
            . self::PREFIX
            . 'ASan runtime does not come first\b/im';
        // A PCRE failure, such as an exhausted backtrack limit on a huge log, is never clean.
        $plain = preg_replace(self::ANSI_CSI, '', $output);
        $matched = $plain === null ? false : preg_match($failure, $plain);
        if ($matched === false) {
            throw new RuntimeException(
                "cannot scan {$context} output for sanitizer failures: " . preg_last_error_msg(),
            );
        }
        if ($matched === 1) {
            throw new RuntimeException("Sanitizer failure during {$context}:\n{$output}");
        }
    }
}
