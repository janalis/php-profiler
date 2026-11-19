<?php

declare(strict_types=1);

// Fails closed on uncovered first-party source; raw external data stays in the artifact.
require_once __DIR__ . '/lib/Arguments.php';
require_once __DIR__ . '/lib/Paths.php';

use PhpProfiler\Tooling\Arguments;
use PhpProfiler\Tooling\Paths;

$usage = 'check-coverage.php <report> [--workspace-root <root>] [--language all|rust|c]';
['options' => $options, 'positionals' => $positionals] = Arguments::parse(
    $argv,
    ['workspace-root', 'language'],
    usage: $usage,
);
$language = $options['language'] ?? 'all';
if (count($positionals) !== 1 || !in_array($language, ['all', 'rust', 'c'], true)) {
    Arguments::fail('expected one report and a known language', $usage);
}
$report = $positionals[0];
$workspace = $options['workspace-root'] ?? null;
$data = json_decode((string) file_get_contents($report), true, flags: JSON_THROW_ON_ERROR);
$metrics = ['lines', 'functions', 'branches'];
$failed = [];
foreach ($data['data'] as $index => $dataset) {
    $totals = $dataset['totals'];
    if (is_string($workspace)) {
        $expected = array_fill_keys(Paths::runtimeSources($workspace, $language), true);
        $selected = [];
        $seen = [];
        $external = 0;
        foreach ($dataset['files'] as $entry) {
            $path = Paths::resolve($entry['filename']);
            if (!isset($expected[$path])) {
                $external++;
                continue;
            }
            if (isset($seen[$path])) {
                $failed[] = 'duplicate first-party source entry: ' . $path;
            }
            $seen[$path] = true;
            $selected[] = $entry;
            foreach ($metrics as $metric) {
                $summary = $entry['summary'][$metric] ?? [];
                $count = $summary['count'] ?? 0;
                $covered = $summary['covered'] ?? 0;
                if ($count == 0) {
                    $failed[] = "{$path}: {$metric} not measured";
                } elseif ($covered != $count) {
                    $failed[] = "{$path}: {$metric} {$covered}/{$count}";
                }
            }
        }
        $missing = array_map(strval(...), array_keys(array_diff_key($expected, $seen)));
        sort($missing, SORT_STRING);
        foreach ($missing as $path) {
            $failed[] = 'first-party source absent from coverage: ' . $path;
        }
        if ($expected === []) {
            $failed[] = 'no expected first-party production source files found';
        }
        if ($selected === []) {
            $failed[] = 'no first-party production source files found';
        }
        $totals = [];
        foreach ($metrics as $metric) {
            foreach (['count', 'covered'] as $field) {
                $totals[$metric][$field] = array_sum(array_map(
                    static fn(array $entry): int|float => $entry['summary'][$metric][$field] ?? 0,
                    $selected,
                ));
            }
        }
        printf(
            "dataset %d: %d first-party source files; %d other files retained in raw artifact\n",
            $index,
            count($selected),
            $external,
        );
    }
    foreach ($metrics as $metric) {
        $summary = $totals[$metric] ?? null;
        if (!$summary || ($summary['count'] ?? 0) == 0) {
            $failed[] = "{$index}: {$metric} not measured";
        } elseif ($summary['covered'] != $summary['count']) {
            $failed[] = "{$index}: {$metric} {$summary['covered']}/{$summary['count']}";
        } else {
            echo "{$index}: {$metric} {$summary['covered']}/{$summary['count']}\n";
        }
    }
}
if ($failed !== []) {
    fwrite(STDERR, implode("\n", $failed) . "\n");
    exit(1);
}
echo "100% measured first-party lines, functions, and branches\n";
