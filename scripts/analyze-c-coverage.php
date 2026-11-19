<?php

declare(strict_types=1);

// Explains GCC branch gaps without changing raw counts or relaxing the gate.
require __DIR__ . '/lib/Arguments.php';
require __DIR__ . '/lib/CoverageAnalysis.php';
require __DIR__ . '/lib/Json.php';

use PhpProfiler\Tooling\Arguments;
use PhpProfiler\Tooling\CoverageAnalysis;
use PhpProfiler\Tooling\Json;

$usage = 'analyze-c-coverage.php <report> --output <path> [--file-suffix <suffix>] [--notes <path> --rebuilt-notes <path> --optimized-tree <path>]';
['options' => $options, 'positionals' => $positionals] = Arguments::parse(
    $argv,
    ['file-suffix', 'notes', 'rebuilt-notes', 'optimized-tree', 'output'],
    usage: $usage,
);
if (count($positionals) !== 1 || !is_string($options['output'] ?? null)) {
    Arguments::fail('expected one report and --output', $usage);
}
$report = $positionals[0];
$evidence = [$options['notes'] ?? null, $options['rebuilt-notes'] ?? null, $options['optimized-tree'] ?? null];
$present = array_values(array_filter($evidence, static fn(mixed $path): bool => $path !== null));
if ($present !== [] && count($present) !== 3) {
    Arguments::fail('provide all three evidence files, or none', $usage);
}
$contents = (string) file_get_contents($report);
$data = json_decode(
    str_ends_with($report, '.gz') ? (string) gzdecode($contents) : $contents,
    true,
    flags: JSON_THROW_ON_ERROR,
);
$read = static fn(?string $path): ?string => $path === null ? null : (string) file_get_contents($path);
$result = CoverageAnalysis::analyze(
    $data,
    $options['file-suffix'] ?? 'src/bridge.c',
    $evidence[0] === null ? null : CoverageAnalysis::notes($read($evidence[0])),
    $evidence[1] === null ? null : CoverageAnalysis::notes($read($evidence[1])),
    $evidence[2] === null ? null : CoverageAnalysis::treeFunctions($read($evidence[2])),
);
$result['input_sha256'] = [];
foreach ([$report, ...$present] as $path) {
    $result['input_sha256'][$path] = hash_file('sha256', $path);
}
$output = $options['output'];
if (!is_dir(dirname($output))) {
    mkdir(dirname($output), 0o777, true);
}
file_put_contents($output, Json::encode($result, indent: 2, sortKeys: false) . "\n");
$keys = ['raw_counts', 'raw_gate_pass', 'explained_constant_header_outcomes', 'unresolved_outcomes'];
echo Json::encode(array_intersect_key($result, array_flip($keys))), "\n";
exit($result['raw_gate_pass'] ? 0 : 1);
