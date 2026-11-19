<?php

declare(strict_types=1);

// Requires every first-party C line, function and branch outcome in GCC's raw JSON report.
$files = [];
foreach (glob(($argv[1] ?? '.') . '/*.gcov.json.gz') ?: [] as $path) {
    $data = json_decode((string) gzdecode((string) file_get_contents($path)), true, flags: JSON_THROW_ON_ERROR);
    foreach ($data['files'] as $item) {
        if (str_ends_with(str_replace('\\', '/', $item['file']), 'src/bridge.c')) {
            $files[] = $item;
        }
    }
}
if (count($files) !== 1) {
    fwrite(STDERR, 'expected exactly one first-party bridge report, got ' . count($files) . "\n");
    exit(1);
}
$lines = $files[0]['lines'];
$functions = $files[0]['functions'];
$branches = array_merge(...array_map(static fn(array $line): array => $line['branches'] ?? [], $lines));
$positive = static fn(array $items, string $field): int => count(array_filter(
    $items,
    static fn(array $item): bool => $item[$field] > 0,
));
$counts = [
    'lines' => [$positive($lines, 'count'), count($lines)],
    'functions' => [$positive($functions, 'execution_count'), count($functions)],
    'branches' => [$positive($branches, 'count'), count($branches)],
];
$failed = false;
foreach ($counts as $name => [$covered, $total]) {
    echo "{$name}: {$covered}/{$total}\n";
    $failed = $failed || $total === 0 || $covered !== $total;
}
foreach ($lines as $line) {
    $uncoveredBranch = array_filter($line['branches'] ?? [], static fn(array $branch): bool => $branch['count'] == 0);
    if (!$line['count'] || $uncoveredBranch !== []) {
        echo "uncovered C line/branch at {$line['line_number']}: " . json_encode($line) . "\n";
    }
}
exit($failed ? 1 : 0);
