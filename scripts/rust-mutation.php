<?php

declare(strict_types=1);

// Mutates every runtime Rust source while executing all real native tests.
require_once __DIR__ . '/lib/Arguments.php';
require_once __DIR__ . '/lib/Json.php';
require_once __DIR__ . '/lib/MutationUnviable.php';
require_once __DIR__ . '/lib/Paths.php';
require_once __DIR__ . '/lib/Process.php';
require_once __DIR__ . '/lib/ValidationInputs.php';

use PhpProfiler\Tooling\Arguments;
use PhpProfiler\Tooling\Json;
use PhpProfiler\Tooling\MutationUnviable;
use PhpProfiler\Tooling\Paths;
use PhpProfiler\Tooling\Process;
use PhpProfiler\Tooling\ValidationInputs;

$root = Paths::resolve(dirname(__DIR__));
$usage = 'rust-mutation.php [--package <name>]... [--output <directory>] [--timeout <seconds>] [--jobs <count>] [--shard <k/n>] [--list-only]';
['options' => $options, 'positionals' => $positionals] = Arguments::parse(
    $argv,
    ['package', 'output', 'timeout', 'jobs', 'shard'],
    ['list-only'],
    $usage,
    ['package'],
);
$integer = static fn(string $name, int $default): int => (
    isset($options[$name])
        ? (
            preg_match('/\A\s*[+-]?\d+\s*\z/', (string) $options[$name])
                ? (int) trim((string) $options[$name])
                : Arguments::fail("--{$name}: invalid int value", $usage)
        )
        : $default
);
$packages = array_values((array) ($options['package'] ?? []));
$timeout = $integer('timeout', 120);
$jobs = $integer('jobs', 2);
$listOnly = isset($options['list-only']);
// Round-robin shards balance slow mutants across CI runners; every shard still
// verifies the full source registry before running its own share.
$shard = null;
if (isset($options['shard'])) {
    if (
        !preg_match('~\A(\d+)/(\d+)\z~', (string) $options['shard'], $parts)
        || (int) $parts[2] < 1
        || (int) $parts[1] >= (int) $parts[2]
    ) {
        Arguments::fail('--shard: expected k/n with 0 <= k < n', $usage);
    }
    $shard = (int) $parts[1] . '/' . (int) $parts[2];
}
$shardArguments = $shard === null ? [] : ['--shard', $shard, '--sharding', 'round-robin'];
if ($positionals !== []) {
    Arguments::fail('unrecognized arguments: ' . implode(' ', $positionals), $usage);
}
if ($jobs > 1 && (string) getenv('CARGO_TARGET_DIR') !== '') {
    Arguments::fail(
        'Parallel native mutation workers require separate target directories; use --jobs 1 with an inherited CARGO_TARGET_DIR',
        $usage,
    );
}
chdir($root);
// Standalone Rust test executables do not host Zend. Only the native test
// driver's separate cdylib build enables private Zend-linked contracts.
$privateFlags = ['PROFILER_ADAPTER_CONTRACT_TEST', 'PROFILER_C_UNIT_BOUNDARIES', 'PROFILER_SAMPLING_UNIT'];
$environment = array_diff_key(Process::environment(), array_flip($privateFlags));
$environment['CARGO_INCREMENTAL'] = '0';
$parent = Paths::resolve((string) ($options['output'] ?? "{$root}/target/rust-mutation"));
if (!is_dir($parent) && !mkdir($parent, 0o777, true)) {
    fwrite(STDERR, "cannot create {$parent}\n");
    exit(1);
}
do {
    $run = $parent . '/run-' . bin2hex(random_bytes(4));
} while (!@mkdir($run, 0o700));
$before = ValidationInputs::inPathOrder(ValidationInputs::snapshot($root));
file_put_contents("{$run}/source-before.json", Json::encode((object) $before, indent: 2, sortKeys: false) . "\n");
$metadata = json_decode(
    Process::output(['cargo', 'metadata', '--format-version', '1', '--no-deps'], $environment),
    true,
    flags: JSON_THROW_ON_ERROR,
);
$members = [];
foreach ($metadata['packages'] as $package) {
    if (in_array($package['id'], $metadata['workspace_members'], true)) {
        $members[$package['name']] = Paths::resolve(dirname($package['manifest_path']));
    }
}
$unknown = array_diff($packages, array_keys($members));
if ($unknown !== []) {
    Arguments::fail('Unknown workspace package: ' . implode(', ', $unknown), $usage);
}
$within = static fn(string $path, string $directory): bool => $path === $directory
|| str_starts_with($path, rtrim($directory, '/') . '/');
$allSources = Paths::runtimeSources($root, 'rust');
$expected = $packages === []
    ? $allSources
    : array_values(array_filter($allSources, static function (string $source) use ($packages, $members, $within): bool {
        foreach ($packages as $name) {
            if ($within($source, $members[$name] . '/src')) {
                return true;
            }
        }
        return false;
    }));
if ($expected === []) {
    fwrite(STDERR, "No runtime Rust sources registered\n");
    exit(1);
}
$relative = static fn(string $path): string => substr($path, strlen($root) + 1);
$command = ['cargo', 'mutants'];
if ($packages !== []) {
    foreach ($packages as $name) {
        array_push($command, '-p', $name);
    }
} else {
    $command[] = '--workspace';
}
$unfilteredCommand = $command;
foreach ($expected as $source) {
    array_push($command, '--file', $relative($source));
}
$memberNames = array_keys($members);
sort($memberNames, SORT_STRING);
$status = 1;
$manifest = [
    'mode' => $listOnly ? 'discovery-only' : 'full-mutation',
    'scope' => $packages === [] ? 'all runtime Rust sources' : 'targeted workspace packages',
    'packages' => $packages ?: $memberNames,
    'runtime_sources' => (object) array_combine(
        array_map($relative, $expected),
        array_map(static fn(string $path): string => (string) hash_file('sha256', $path), $expected),
    ),
    'all_workspace_runtime_sources' => array_map($relative, $allSources),
    'test_infrastructure_is_executed_not_mutated' => true,
    'private_contract_environment' => [
        'standalone_unit_build' => array_combine($privateFlags, array_map(
            static fn(string $key): ?string => $environment[$key] ?? null,
            $privateFlags,
        )),
        'native_cdylib_build' => array_fill_keys($privateFlags, '1'),
        'driver' => 'crates/php-profiler/tests/native.rs',
    ],
    'discovery_command' => [...$command, '--list', '--json'],
    'raw_counts' => null,
    'compiler_cache_policy' => ['CARGO_INCREMENTAL' => $environment['CARGO_INCREMENTAL']],
    'effective_compiler_flags' => array_combine(
        $flags = ['RUSTFLAGS', 'CARGO_ENCODED_RUSTFLAGS', 'CC', 'CFLAGS', 'CLANG_PATH', 'LIBCLANG_PATH'],
        array_map(static fn(string $key): ?string => $environment[$key] ?? null, $flags),
    ),
];
$sortedPaths = static function (array $paths) use ($relative): array {
    $names = array_map($relative, $paths);
    sort($names, SORT_STRING);
    return $names;
};
try {
    $unfiltered = Process::run([...$unfilteredCommand, '--list', '--json'], $environment);
    file_put_contents("{$run}/discovery-all-sources.json", $unfiltered['stdout']);
    file_put_contents("{$run}/discovery-all-sources.stderr", $unfiltered['stderr']);
    if ($unfiltered['status'] !== 0) {
        throw new RuntimeException('Unfiltered Cargo mutation discovery failed');
    }
    $expectedSet = array_fill_keys($expected, true);
    $outside = [];
    foreach (json_decode($unfiltered['stdout'], true, flags: JSON_THROW_ON_ERROR) as $candidate) {
        $path = Paths::resolve("{$root}/{$candidate['file']}");
        if (!isset($expectedSet[$path])) {
            $outside[$path] = true;
        }
    }
    $testRoots = [
        "{$root}/tests",
        ...array_map(static fn(string $directory): string => "{$directory}/tests", array_values($members)),
    ];
    $unexpected = array_filter(
        array_keys($outside),
        static fn(string $path): bool => !array_filter($testRoots, static fn(string $directory): bool => $within(
            $path,
            $directory,
        )),
    );
    if ($unexpected !== []) {
        sort($unexpected, SORT_STRING);
        throw new RuntimeException(
            'Unregistered runtime source outside exhaustive registry: '
                . json_encode(array_values($unexpected), JSON_UNESCAPED_SLASHES),
        );
    }
    $manifest['discovered_test_infrastructure'] = $sortedPaths(array_keys($outside));
    $discovery = Process::run([...$command, '--list', '--json'], $environment);
    file_put_contents("{$run}/discovery.json", $discovery['stdout']);
    file_put_contents("{$run}/discovery.stderr", $discovery['stderr']);
    if ($discovery['status'] !== 0) {
        throw new RuntimeException('Cargo mutation discovery failed');
    }
    $mutants = json_decode($discovery['stdout'], true, flags: JSON_THROW_ON_ERROR);
    $discovered = [];
    foreach ($mutants as $candidate) {
        $discovered[Paths::resolve("{$root}/{$candidate['file']}")] = true;
    }
    if (array_diff_key($discovered, $expectedSet) !== [] || array_diff_key($expectedSet, $discovered) !== []) {
        throw new RuntimeException(
            'Runtime mutation source mismatch; absent='
                . json_encode(
                    $sortedPaths(array_keys(array_diff_key($expectedSet, $discovered))),
                    JSON_UNESCAPED_SLASHES,
                )
                . '; unexpected='
                . json_encode(
                    $sortedPaths(array_keys(array_diff_key($discovered, $expectedSet))),
                    JSON_UNESCAPED_SLASHES,
                ),
        );
    }
    $manifest['generated'] = count($mutants);
    $manifest['shard'] = $shard;
    $manifest['shard_generated'] = count($mutants);
    if ($shard !== null) {
        // Ask cargo-mutants for this shard's own list rather than re-deriving its partition.
        $sharded = Process::run([...$command, ...$shardArguments, '--list', '--json'], $environment);
        file_put_contents("{$run}/discovery-shard.json", $sharded['stdout']);
        file_put_contents("{$run}/discovery-shard.stderr", $sharded['stderr']);
        if ($sharded['status'] !== 0) {
            throw new RuntimeException('Cargo mutation shard discovery failed');
        }
        $manifest['shard_generated'] = count(json_decode($sharded['stdout'], true, flags: JSON_THROW_ON_ERROR));
    }
    if ($listOnly) {
        $status = 0;
    } else {
        $testing = [
            ...$command,
            '--test-workspace',
            'true',
            '--test-tool',
            'nextest',
            '--cargo-test-arg=--max-fail=1:immediate',
            '--cargo-test-arg=--config-file=tests/nextest.toml',
            '--timeout',
            (string) $timeout,
            '--jobs',
            (string) $jobs,
            '--output',
            $run,
            ...$shardArguments,
        ];
        $manifest['test_command'] = $testing;
        // Inherit the descriptors themselves, so a redirected log keeps every line.
        $process = proc_open($testing, [], $pipes, null, $environment);
        $status = $process === false ? 1 : proc_close($process);
        $outcomes = json_decode(
            (string) file_get_contents("{$run}/mutants.out/outcomes.json"),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $manifest['raw_counts'] = [];
        foreach (['total_mutants', 'caught', 'missed', 'timeout', 'unviable'] as $key) {
            $manifest['raw_counts'][$key] = array_key_exists($key, $outcomes)
                ? $outcomes[$key]
                : throw new RuntimeException("'{$key}'");
        }
        $unviable = MutationUnviable::audit($outcomes, "{$run}/mutants.out");
        file_put_contents("{$run}/unviable-audit.json", Json::encode($unviable, indent: 2, sortKeys: false) . "\n");
        $manifest['unviable_classification'] = [
            'compiler_rejected' => $unviable['compiler_rejected'],
            'infrastructure_or_unknown' => $unviable['infrastructure_or_unknown'],
        ];
        if (
            $unviable['infrastructure_or_unknown']
            || $outcomes['total_mutants'] !== $manifest['shard_generated']
            || $outcomes['missed']
            || $outcomes['timeout']
        ) {
            $status = 1;
        }
    }
} catch (Throwable $failure) {
    $manifest['error'] = $failure->getMessage();
    fwrite(STDERR, $failure->getMessage() . "\n");
    $status = 1;
} finally {
    $after = ValidationInputs::inPathOrder(ValidationInputs::snapshot($root));
    file_put_contents("{$run}/source-after.json", Json::encode((object) $after, indent: 2, sortKeys: false) . "\n");
    $changed = [];
    foreach (array_keys($before + $after) as $key) {
        if (($before[$key] ?? null) !== ($after[$key] ?? null)) {
            $changed[] = (string) $key;
        }
    }
    sort($changed, SORT_STRING);
    $manifest['exit_code'] = $changed === [] ? $status : 1;
    $manifest['source_snapshot_matches'] = $changed === [];
    $manifest['changed_inputs'] = $changed;
    $encoded = Json::encode($manifest, indent: 2, sortKeys: false) . "\n";
    file_put_contents("{$run}/run.json", $encoded);
    $temporary = tempnam($parent, '.latest-');
    if (
        $temporary === false
        || file_put_contents($temporary, $encoded) === false
        || !rename($temporary, "{$parent}/latest.json")
    ) {
        fwrite(STDERR, "write {$parent}/latest.json\n");
        $manifest['exit_code'] = 1;
    }
}
echo "Rust mutation manifest: {$run}/run.json\n";
exit($manifest['exit_code']);
