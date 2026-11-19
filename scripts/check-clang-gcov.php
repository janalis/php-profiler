<?php

declare(strict_types=1);

// Strict Clang gcov intermediate coverage: every raw first-party C counter.
require_once __DIR__ . '/lib/Arguments.php';
require_once __DIR__ . '/lib/Json.php';
require_once __DIR__ . '/lib/Paths.php';

use PhpProfiler\Tooling\Arguments;
use PhpProfiler\Tooling\Json;
use PhpProfiler\Tooling\Paths;

$usage = 'check-clang-gcov.php <reports> --workspace-root <root>';
['options' => $options, 'positionals' => $positionals] = Arguments::parse($argv, ['workspace-root'], usage: $usage);
if (count($positionals) !== 1 || !is_string($options['workspace-root'] ?? null)) {
    Arguments::fail('expected one reports directory and --workspace-root', $usage);
}
$reports = $positionals[0];
$fail = static function (string $message): never {
    fwrite(STDERR, $message . "\n");
    exit(1);
};
$integer = static function (string $value) use ($fail): int {
    // Python's int() accepts surrounding whitespace and a sign, nothing else.
    preg_match('/\A\s*[+-]?\d+\s*\z/', $value) || $fail('Invalid raw Clang counter: ' . $value);
    return (int) trim($value);
};
$root = Paths::resolve($options['workspace-root']);
$expected = array_fill_keys(Paths::runtimeSources($root, 'c'), true);
$crates = array_values(array_filter(
    array_map(
        static fn(string $name): string => "{$root}/crates/{$name}",
        array_diff(scandir("{$root}/crates") ?: [], ['.', '..']),
    ),
    is_dir(...),
));
$files = [];
$external = [];
foreach (glob($reports . '/*.gcov') ?: [] as $raw) {
    $current = null;
    foreach (preg_split('/\r\n|\r|\n/', (string) file_get_contents($raw)) as $line) {
        [$kind, $value] = str_contains($line, ':') ? explode(':', $line, 2) : [$line, null];
        if ($kind === 'file' && $value !== null) {
            if (str_starts_with($value, '/')) {
                $path = Paths::resolve($value);
            } else {
                $path = Paths::resolve("{$root}/{$value}");
                if (!isset($expected[$path])) {
                    // cc::Build records filenames relative to the crate build directory.
                    // Require an unambiguous full relative suffix.
                    $candidates = array_map(static fn(string $crate): string => Paths::resolve(
                        "{$crate}/{$value}",
                    ), $crates);
                    $matches = array_values(array_intersect(array_keys($expected), $candidates));
                    if (count($matches) > 1) {
                        $fail('Ambiguous compiler source filename: ' . $value);
                    }
                    $path = $matches[0] ?? $path;
                }
            }
            if (isset($expected[$path])) {
                $files[$path] ??= ['lines' => [], 'functions' => [], 'branches' => []];
                $current = $path;
            } else {
                $current = null;
                $external[$path] = true;
            }
        } elseif ($current !== null && $value !== null) {
            $parts = explode(',', $value);
            if ($kind === 'function') {
                $files[$current]['functions'][] = [
                    'line' => $integer($parts[0]),
                    'count' => $integer($parts[1] ?? ''),
                    'name' => implode(',', array_slice($parts, 2)),
                ];
            } elseif ($kind === 'lcount') {
                $files[$current]['lines'][] = ['line' => $integer($parts[0]), 'count' => $integer($parts[1] ?? '')];
            } elseif ($kind === 'branch') {
                $state = $parts[1] ?? '';
                if (!in_array($state, ['taken', 'nottaken', 'notexec'], true)) {
                    $fail('Unknown raw Clang branch state: ' . $line);
                }
                $files[$current]['branches'][] = [
                    'line' => $integer($parts[0]),
                    'covered' => $state === 'taken',
                    'raw_state' => $state,
                ];
            }
        }
    }
}
$expectedNames = array_keys($expected);
$measuredNames = array_keys($files);
sort($expectedNames, SORT_STRING);
sort($measuredNames, SORT_STRING);
$failed = $expectedNames !== $measuredNames || $expected === [];
foreach ($files as $name => $counters) {
    foreach (['lines', 'functions', 'branches'] as $metric) {
        $entries = $counters[$metric];
        $covered = count(array_filter($entries, static fn(array $entry): bool => $metric === 'branches'
            ? $entry['covered']
            : $entry['count'] > 0));
        echo "{$name}: {$metric} {$covered}/" . count($entries) . "\n";
        $failed = $failed || $entries === [] || $covered !== count($entries);
    }
}
$summary = ['files' => (object) $files, 'external_files_retained' => count($external), 'passed' => !$failed];
file_put_contents($reports . '/strict-summary.json', Json::encode($summary, indent: 2, sortKeys: false) . "\n");
if ($failed) {
    $fail('Clang gcov strict first-party coverage gate failed; raw reports retained');
}
echo "100% raw Clang-gcov first-party C lines, functions and branches\n";
