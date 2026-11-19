<?php

declare(strict_types=1);

// Rejects incompatible Rust and C LLVM instrumentation before compiling a mixed DSO.
require __DIR__ . '/lib/CoverageToolchain.php';

use PhpProfiler\Tooling\CoverageToolchain;

if ($argc !== 3) {
    fwrite(STDERR, "usage: coverage-toolchain.php <compiler> <rust-toolchain>\n");
    exit(2);
}
try {
    $major = CoverageToolchain::check($argv[1], $argv[2]);
} catch (InvalidArgumentException|RuntimeException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
echo "Mixed coverage toolchain uses LLVM {$major}\n";
