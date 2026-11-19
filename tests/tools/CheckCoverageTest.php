<?php

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use PhpProfiler\Tooling\Process;

/** Rust coverage must account for every expected runtime source file. */
function checkRustCoverage(
    bool $missing = false,
    bool $zero = false,
    bool $duplicate = false,
    bool $uncovered = false,
    bool $optionsFirst = false,
): array {
    $root = temporaryDirectory();
    $source = "{$root}/crates/engine/src/lib.rs";
    $cSource = "{$root}/crates/engine/src/bridge.c";
    writeFile($source, "pub fn example(n: bool) -> bool { !n }\n");
    writeFile($cSource, "int bridge(void) { return 1; }\n");
    if ($missing) {
        writeFile("{$root}/crates/engine/src/storage.rs", "pub fn storage() -> bool { true }\n");
    }
    $metrics = array_fill_keys(['lines', 'functions', 'branches'], ['count' => 2, 'covered' => 2]);
    if ($zero) {
        $metrics['branches'] = ['count' => 0, 'covered' => 0];
    }
    if ($uncovered) {
        $metrics['branches']['covered'] = 1;
    }
    $entry = ['filename' => $source, 'summary' => $metrics];
    $files = [$entry, ['filename' => $cSource, 'summary' => new stdClass()]];
    if ($duplicate) {
        $files[] = $entry;
    }
    writeFile("{$root}/report.json", json_encode(['data' => [['totals' => $metrics, 'files' => $files]]]));
    $options = ['--workspace-root', $root, '--language', 'rust'];
    $arguments = $optionsFirst ? [...$options, "{$root}/report.json"] : ["{$root}/report.json", ...$options];
    return Process::run([ROOT . '/scripts/php-tool.sh', ROOT . '/scripts/check-coverage.php', ...$arguments]);
}

return [
    'all rust files are gated while c remains a separate backend' => static function (): void {
        $result = checkRustCoverage();
        same(0, $result['status'], $result['stderr']);
        same(0, checkRustCoverage(optionsFirst: true)['status']);
    },
    'a missing runtime file is rejected' => static function (): void {
        $result = checkRustCoverage(missing: true);
        same(1, $result['status']);
        same(true, str_contains($result['stderr'], 'storage.rs'), $result['stderr']);
    },
    'zero counters are not a measured pass' => static function (): void {
        $result = checkRustCoverage(zero: true);
        same(1, $result['status']);
        same(true, str_contains($result['stderr'], 'not measured'), $result['stderr']);
    },
    'duplicate source records cannot inflate totals' => static function (): void {
        $result = checkRustCoverage(duplicate: true);
        same(1, $result['status']);
        same(true, str_contains($result['stderr'], 'duplicate'), $result['stderr']);
    },
    'one uncovered edge fails' => static function (): void {
        $result = checkRustCoverage(uncovered: true);
        same(1, $result['status']);
        same(true, str_contains($result['stderr'], 'branches 1/2'), $result['stderr']);
    },
    'an unknown option is refused instead of ignored' => static function (): void {
        $root = temporaryDirectory();
        $result = Process::run([
            ROOT . '/scripts/php-tool.sh',
            ROOT . '/scripts/check-coverage.php',
            "{$root}/report.json",
            '--workspace',
            $root,
        ]);
        same(2, $result['status']);
    },
];
