<?php

declare(strict_types=1);

// Exports each execution cohort against only its own profiles, then merges them strictly.
require_once __DIR__ . '/lib/NativeCoverageExport.php';

use PhpProfiler\Tooling\NativeCoverageExport;
use PhpProfiler\Tooling\ProcessFailedStatus;

if ($argc !== 5) {
    fwrite(STDERR, "usage: export-native-coverage.php <llvm-cov> <run> <extension> <merged.profdata>\n");
    exit(2);
}
try {
    NativeCoverageExport::main($argv[1], $argv[2], $argv[3], $argv[4]);
} catch (ProcessFailedStatus $failure) {
    fwrite(STDERR, $failure->getMessage() . "\n");
    exit($failure->status);
} catch (InvalidArgumentException|JsonException $failure) {
    fwrite(STDERR, $failure->getMessage() . "\n");
    exit(1);
}
