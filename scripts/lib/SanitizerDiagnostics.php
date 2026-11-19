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
        if (preg_match($failure, (string) preg_replace(self::ANSI_CSI, '', $output))) {
            throw new RuntimeException("Sanitizer failure during {$context}:\n{$output}");
        }
    }
}
