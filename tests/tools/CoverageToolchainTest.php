<?php

declare(strict_types=1);

require_once __DIR__ . '/support.php';
require_once ROOT . '/scripts/lib/CoverageToolchain.php';

use PhpProfiler\Tooling\CoverageToolchain;
use PhpProfiler\Tooling\ProcessFailedStatus;

/** Mixed C and Rust profile instrumentation must use the same LLVM major. */
return [
    'matching upstream and vendor versions' => static function (): void {
        $rust = "rustc 1.102.0-nightly\nLLVM version: 23.1.5\n";
        foreach ([
            'clang version 23.1.3',
            'Ubuntu clang version 23.1.5',
            'Apple clang version 23.0.0 (clang-2300)',
        ] as $clang) {
            same(23, CoverageToolchain::requireMatchingLlvm($rust, $clang));
        }
    },
    'actual mismatched apple compiler is rejected' => static function (): void {
        $error = throws(InvalidArgumentException::class, static fn() => CoverageToolchain::requireMatchingLlvm(
            'LLVM version: 23.1.5',
            'Apple clang version 21.0.0 (clang-2100.4.1.1)',
        ));
        same(true, str_contains($error->getMessage(), 'rustc uses 23, Clang uses 21'));
    },
    'unknown compilers and missing llvm version are rejected' => static function (): void {
        foreach ([['rustc 1.99.1', 'clang version 23.1.3'], ['LLVM version: 23.1.5', 'gcc 15.1.0']] as [$rust, $cc]) {
            throws(InvalidArgumentException::class, static fn() => CoverageToolchain::requireMatchingLlvm($rust, $cc));
        }
    },
    'command arguments are preserved and failures propagate' => static function (): void {
        $calls = [];
        $run = static function (array $command) use (&$calls): string {
            $calls[] = $command;
            return $command[0] === 'rustc' ? 'LLVM version: 23.1.5' : 'clang version 23.1.3';
        };
        same(23, CoverageToolchain::check(
            '"/path with spaces/clang" --target=aarch64-apple-darwin',
            'nightly-2026-11-11',
            $run,
        ));
        same([
            ['rustc', '+nightly-2026-11-11', '-vV'],
            ['/path with spaces/clang', '--target=aarch64-apple-darwin', '--version'],
        ], $calls);
        $error = throws(InvalidArgumentException::class, static fn() => CoverageToolchain::check(' ', 'nightly', $run));
        same(true, str_contains($error->getMessage(), 'CC must name'));
        $failure = static fn(array $command): string => throw new ProcessFailedStatus($command, 2, '');
        throws(ProcessFailedStatus::class, static fn() => CoverageToolchain::check('clang', 'nightly', $failure));
    },
    'an existing compiler path with spaces is one command' => static function (): void {
        $directory = temporaryDirectory();
        writeFile("{$directory}/my clang", '', 0o755);
        $calls = [];
        $run = static function (array $command) use (&$calls): string {
            $calls[] = $command;
            return $command[0] === 'rustc' ? 'LLVM version: 23.1.5' : 'clang version 23.1.3';
        };
        CoverageToolchain::check("{$directory}/my clang", 'nightly', $run);
        same(["{$directory}/my clang", '--version'], $calls[1]);
    },
];
