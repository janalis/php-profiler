<?php

declare(strict_types=1);

namespace PhpProfiler\Tooling;

use RuntimeException;

/** Proves the AMD64 atfork wrapper passes this DSO's own handle as its fourth argument. */
final class AtforkWrapper
{
    /**
     * objdump may label an address with an unrelated alias such as __TMC_END__, so the
     * effective address is compared with nm instead of trusting the displayed name.
     */
    public static function verify(string $symbols, string $assembly): void
    {
        $marker = '<__pthread_atfork>:';
        if (!preg_match('/^([0-9a-fA-F]+) d __dso_handle$/m', $symbols, $handle) || !str_contains($assembly, $marker)) {
            throw new RuntimeException('Cargo DSO local atfork wrapper/handle not found');
        }
        $wrapper = explode("\n\n", explode($marker, $assembly, 2)[1], 2)[0];
        if (
            !preg_match('/\bmov\s+[^\n]*\(%rip\),%rcx\s*#\s*([0-9a-fA-F]+)\b/', $wrapper, $load)
            || hexdec($load[1]) !== hexdec($handle[1])
            || !preg_match('/\bjmp\s+[^\n]*<__register_atfork@plt>/', $wrapper)
        ) {
            throw new RuntimeException('atfork wrapper does not pass its local DSO identity');
        }
    }
}
