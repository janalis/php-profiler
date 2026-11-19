<?php

declare(strict_types=1);

// Records immutable coverage inputs and artifacts, and fails closed on source drift.
require_once __DIR__ . '/lib/Arguments.php';
require_once __DIR__ . '/lib/Json.php';
require_once __DIR__ . '/lib/Paths.php';

use PhpProfiler\Tooling\Arguments;
use PhpProfiler\Tooling\Json;
use PhpProfiler\Tooling\Paths;

$usage = 'coverage-run-manifest.php <run> <parent> <status> [--artifact <name>]... [--artifact-directory <name>]...';
['options' => $options, 'positionals' => $positional] = Arguments::parse(
    $argv,
    ['artifact', 'artifact-directory'],
    usage: $usage,
);
$artifactNames = (array) ($options['artifact'] ?? []);
$artifactDirectories = (array) ($options['artifact-directory'] ?? []);
if (count($positional) !== 3 || !preg_match('/\A[+-]?\d+\z/', $positional[2])) {
    Arguments::fail('expected run, parent and an integer status', $usage);
}
$run = Paths::resolve($positional[0]);
$parent = Paths::resolve($positional[1]);
$status = (int) $positional[2];
$before = json_decode((string) file_get_contents("{$run}/source-before.json"), true, flags: JSON_THROW_ON_ERROR);
$after = json_decode((string) file_get_contents("{$run}/source-after.json"), true, flags: JSON_THROW_ON_ERROR);
$changed = [];
foreach (array_keys($before + $after) as $key) {
    if (!array_key_exists($key, $before) || !array_key_exists($key, $after) || $before[$key] !== $after[$key]) {
        $changed[] = (string) $key;
    }
}
sort($changed, SORT_STRING);
$missing = [];
$artifacts = [];
foreach ($artifactNames as $name) {
    is_file("{$run}/{$name}") ? ($artifacts["{$run}/{$name}"] = true) : ($missing[] = $name);
}
foreach ($artifactDirectories as $name) {
    if (!is_dir("{$run}/{$name}")) {
        $missing[] = $name;
        continue;
    }
    foreach (Paths::files("{$run}/{$name}") as $path) {
        $artifacts[$path] = true;
    }
}
if (($changed !== [] || $missing !== []) && $status === 0) {
    $status = 1;
}
$hashes = [];
foreach (Paths::sort(array_map(strval(...), array_keys($artifacts))) as $path) {
    $hashes[substr($path, strlen($run) + 1)] = hash_file('sha256', $path);
}
$manifest = [
    'schema_version' => 1,
    'run_directory' => $run,
    'exit_code' => $status,
    'source_snapshot_matches' => $changed === [],
    'changed_inputs' => $changed,
    'missing_artifacts' => $missing,
    'artifact_sha256' => (object) $hashes,
    'fresh_object_directory' => is_dir("{$run}/build") ? "{$run}/build" : $run,
];
$encoded = Json::encode($manifest, indent: 2, sortKeys: false) . "\n";
file_put_contents("{$run}/run.json", $encoded);
$temporary = tempnam($parent, '.latest-');
if (
    $temporary === false
    || file_put_contents($temporary, $encoded) === false
    || !rename($temporary, "{$parent}/latest.json")
) {
    fwrite(STDERR, "write {$parent}/latest.json\n");
    exit(1);
}
if ($changed !== []) {
    fwrite(STDERR, 'Coverage inputs changed: ' . implode(', ', $changed) . "\n");
}
if ($missing !== []) {
    fwrite(STDERR, 'Coverage artifacts missing: ' . implode(', ', $missing) . "\n");
}
echo "Coverage run manifest: {$run}/run.json\n";
exit($status);
