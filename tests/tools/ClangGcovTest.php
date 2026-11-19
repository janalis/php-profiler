<?php

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use PhpProfiler\Tooling\Process;

/** Every actual C source and raw edge must satisfy the independent compiler gate. */
const CLANG_RAW = "file:src/bridge.c\nfunction:1,2,example\nlcount:1,2\nlcount:2,1\nbranch:1,taken\nbranch:1,taken\n";

function clangGcov(string $raw = CLANG_RAW, bool $extraSource = false, bool $ambiguous = false): array
{
    $root = temporaryDirectory();
    $source = "{$root}/crates/adapter/src/bridge.c";
    writeFile($source, "int example(int n) { return n ? 1 : 2; }\n");
    if ($extraSource) {
        writeFile("{$root}/crates/adapter/src/missing.c", "int missing(void) { return 1; }\n");
    }
    if ($ambiguous) {
        writeFile("{$root}/crates/other/src/bridge.c", (string) file_get_contents($source));
    }
    writeFile("{$root}/reports/bridge.gcov", $raw);
    $result = Process::run([
        ROOT . '/scripts/php-tool.sh',
        ROOT . '/scripts/check-clang-gcov.php',
        "{$root}/reports",
        '--workspace-root',
        $root,
    ]);
    $summary = "{$root}/reports/strict-summary.json";
    return [$result, is_file($summary) ? json_decode((string) file_get_contents($summary), true) : null];
}

$firstBranches = static fn(array $summary): array => array_values($summary['files'])[0]['branches'];

return [
    'all raw counters and unique crate relative path pass' => static function () use ($firstBranches): void {
        [$result, $summary] = clangGcov();
        same(0, $result['status'], $result['stderr']);
        same(true, $summary['passed']);
        same(2, count($firstBranches($summary)));
        same(['line' => 1, 'count' => 2, 'name' => 'example'], array_values($summary['files'])[0]['functions'][0]);
    },
    'an uncovered edge fails without discarding it' => static function () use ($firstBranches): void {
        [$result, $summary] = clangGcov(preg_replace('/branch:1,taken/', 'branch:1,nottaken', CLANG_RAW, 1));
        same(1, $result['status']);
        same(false, $summary['passed']);
        same(2, count($firstBranches($summary)));
    },
    'missing first party source is not an empty pass' => static function (): void {
        [$result, $summary] = clangGcov(extraSource: true);
        same(1, $result['status']);
        same(false, $summary['passed']);
    },
    'ambiguous compiler relative path fails closed' => static function (): void {
        [$result, $summary] = clangGcov(ambiguous: true);
        same(1, $result['status']);
        same(true, str_contains($result['stderr'], 'Ambiguous'), $result['stderr']);
        same(null, $summary);
    },
    'unknown compiler edge encoding fails closed' => static function (): void {
        [$result, $summary] = clangGcov(preg_replace('/branch:1,taken/', 'branch:1,unknown', CLANG_RAW, 1));
        same(1, $result['status']);
        same(true, str_contains($result['stderr'], 'Unknown'), $result['stderr']);
        same(null, $summary);
    },
    'a malformed counter fails closed' => static function (): void {
        [$result, $summary] = clangGcov(str_replace('lcount:2,1', 'lcount:2,x', CLANG_RAW));
        same(1, $result['status']);
        same(null, $summary);
    },
];
