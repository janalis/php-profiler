<?php

declare(strict_types=1);

namespace PhpProfiler\Tooling;

use Closure;
use InvalidArgumentException;

require_once __DIR__ . '/Process.php';
require_once __DIR__ . '/Shlex.php';

/** Rejects incompatible Rust and C LLVM instrumentation before compiling a mixed DSO. */
final class CoverageToolchain
{
    public static function requireMatchingLlvm(string $rustVersion, string $compilerVersion): int
    {
        if (!preg_match('/^LLVM version:\s*(\d+)\./m', $rustVersion, $rust)) {
            throw new InvalidArgumentException('rustc did not report its LLVM version');
        }
        if (!preg_match('/\bclang version\s+(\d+)\./', $compilerVersion, $compiler)) {
            throw new InvalidArgumentException(
                'Mixed Rust/C coverage requires a Clang compiler with a reported LLVM version',
            );
        }
        [$rustMajor, $compilerMajor] = [(int) $rust[1], (int) $compiler[1]];
        if ($rustMajor !== $compilerMajor) {
            throw new InvalidArgumentException(
                "Coverage LLVM major mismatch: rustc uses {$rustMajor}, Clang uses {$compilerMajor}; "
                . 'set CC, CLANG_PATH and LIBCLANG_PATH to the matching LLVM installation',
            );
        }
        return $rustMajor;
    }

    /** @param (Closure(list<string>): string)|null $run */
    public static function check(string $compiler, string $toolchain, ?Closure $run = null): int
    {
        $run ??= static fn(array $command): string => Process::output($command);
        $command = is_file($compiler) ? [$compiler] : Shlex::split($compiler);
        if ($command === []) {
            throw new InvalidArgumentException('CC must name a Clang compiler');
        }
        $rust = $run(['rustc', '+' . $toolchain, '-vV']);
        $clang = $run([...$command, '--version']);
        return self::requireMatchingLlvm($rust, $clang);
    }
}
