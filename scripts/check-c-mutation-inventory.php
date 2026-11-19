<?php

declare(strict_types=1);

// Requires test transports to preserve the ordinary production C mutation inventory.
require_once __DIR__ . '/lib/Arguments.php';
require_once __DIR__ . '/lib/Json.php';
require_once __DIR__ . '/lib/MutationInventory.php';
require_once __DIR__ . '/lib/Process.php';

use PhpProfiler\Tooling\Arguments;
use PhpProfiler\Tooling\Json;
use PhpProfiler\Tooling\MutationInventory;
use PhpProfiler\Tooling\Paths;
use PhpProfiler\Tooling\Process;

$usage = 'check-c-mutation-inventory.php --workspace-root <root> --extension <path> --context <path> --output <directory>';
['options' => $options, 'positionals' => $positionals] = Arguments::parse(
    $argv,
    ['workspace-root', 'extension', 'context', 'output'],
    usage: $usage,
);
foreach (['workspace-root', 'extension', 'context', 'output'] as $name) {
    if (!is_string($options[$name] ?? null)) {
        Arguments::fail("--{$name} is required", $usage);
    }
}
if ($positionals !== []) {
    Arguments::fail('unexpected positional arguments', $usage);
}

function inventoryFail(string $message): never
{
    fwrite(STDERR, 'C mutation inventory gate: ' . $message . "\n");
    exit(1);
}

try {
    $root = Paths::resolve($options['workspace-root']);
    $expected = array_fill_keys(Paths::runtimeSources($root, 'c'), true);
    $context = json_decode((string) file_get_contents($options['context']), true, flags: JSON_THROW_ON_ERROR);
    $output = $options['output'];
    if (!is_dir($output) && !mkdir($output, 0o777, true)) {
        inventoryFail('cannot create ' . $output);
    }
    $ordinary = [];
    $objects = [];
    foreach (array_keys($expected) as $source) {
        $stem = pathinfo($source, PATHINFO_FILENAME);
        $unit = $stem . '-unit.c';
        $recorded = $context['native_compile_commands'][$unit] ?? inventoryFail("'{$unit}'");
        // DW_AT_producer text starts with version words followed by the absolute
        // compiler executable. Never execute the version preamble.
        $start = null;
        foreach ($recorded as $index => $value) {
            if (
                str_starts_with($value, '/')
                && is_file($value)
                && hash_file('sha256', $value) === ($context['compiler_sha256'] ?? null)
            ) {
                $start = $index;
                break;
            }
        }
        if ($start === null) {
            inventoryFail('');
        }
        $command = array_values(array_slice($recorded, $start));
        $inputPosition = array_search('-c', $command, true);
        if ($inputPosition === false) {
            inventoryFail("'-c' is not in list");
        }
        $inputPosition++;
        if (!str_ends_with($command[$inputPosition] ?? '', '/' . $unit)) {
            inventoryFail('Native input provenance does not identify ' . $unit);
        }
        $command[$inputPosition] = $source;
        $objectPath = Paths::resolve($output) . '/' . $stem . '.o';
        array_push($command, '-o', $objectPath);
        $compiled = Process::run($command, directory: $root);
        file_put_contents("{$output}/{$stem}-compile.log", $compiled['stdout'] . $compiled['stderr']);
        if ($compiled['status'] !== 0) {
            inventoryFail('Ordinary C inventory compilation failed: ' . $source);
        }
        $symbols = Process::output(['nm', '--defined-only', $objectPath]);
        file_put_contents("{$output}/{$stem}.nm", $symbols);
        $candidates = MutationInventory::identities($symbols, $root, $expected);
        if ($candidates === []) {
            inventoryFail('Ordinary runtime C source emitted no mutations: ' . $source);
        }
        $ordinary += $candidates;
        $objects[] = [
            'source' => substr($source, strlen($root) + 1),
            'command' => $command,
            'source_sha256' => hash_file('sha256', $source),
            'object_sha256' => hash_file('sha256', $objectPath),
            'generated' => count($candidates),
        ];
    }
    $symbols = Process::output(['nm', '--defined-only', $options['extension']]);
    file_put_contents("{$output}/extension.nm", $symbols);
    $instrumented = MutationInventory::identities($symbols, $root, $expected);
    $sorted = static function (array $set): array {
        $keys = array_map(strval(...), array_keys($set));
        sort($keys, SORT_STRING);
        return $keys;
    };
    $result = [
        'ordinary_generated' => count($ordinary),
        'test_generated' => count($instrumented),
        'missing_from_test_transport' => $sorted(array_diff_key($ordinary, $instrumented)),
        'extra_from_test_transport' => $sorted(array_diff_key($instrumented, $ordinary)),
        'ordinary_identities' => $sorted($ordinary),
        'test_identities' => $sorted($instrumented),
        'objects' => $objects,
        'extension_sha256' => hash_file('sha256', $options['extension']),
    ];
    $result['passed'] =
        $ordinary !== []
        && $result['missing_from_test_transport'] === []
        && $result['extra_from_test_transport'] === [];
    file_put_contents("{$output}/comparison.json", Json::encode($result, indent: 2, sortKeys: false) . "\n");
    if (!$result['passed']) {
        inventoryFail('Test transport changes the ordinary production mutation inventory');
    }
    echo 'Ordinary/test C mutation inventories match: ', count($ordinary), "\n";
} catch (InvalidArgumentException|RuntimeException|JsonException $failure) {
    inventoryFail($failure->getMessage());
}
