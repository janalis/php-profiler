<?php

declare(strict_types=1);

// Targets the local exception-ownership patch in an isolated source copy. This is a
// small, explicit mutation experiment, not an upstream MSI claim. The checked-out
// vendor source and PHP extension are never modified.
require_once __DIR__ . '/lib/Arguments.php';
require_once __DIR__ . '/lib/ExceptionFixture.php';
require_once __DIR__ . '/lib/Json.php';
require_once __DIR__ . '/lib/Paths.php';
require_once __DIR__ . '/lib/Process.php';
require_once __DIR__ . '/lib/VendorMutations.php';

use PhpProfiler\Tooling\Arguments;
use PhpProfiler\Tooling\ExceptionFixture;
use PhpProfiler\Tooling\Json;
use PhpProfiler\Tooling\Paths;
use PhpProfiler\Tooling\Process;
use PhpProfiler\Tooling\VendorMutations;

const LEAK_MARKER = 'Rust exception ownership leaked across Zend bailout';

const INJECTED_MARKER = 'Rust exception ownership survived injected bailout';

const CLONE_MARKER = 'owned Rust allocation survived clone bailout';

const ATTACHED_MARKER = 'ATTACHED_OBJECT_REGRESSION';

const ATTACHED_PROBE = <<<'PROBE'
    try {profiler_fixture_throw(4);fwrite(STDERR,"ATTACHED_OBJECT_REGRESSION: no throw\n");exit(1);}
    catch(Throwable $error) {if($error::class!==Exception::class || $error->getMessage()!=="attached exception") {fwrite(STDERR,"ATTACHED_OBJECT_REGRESSION: original object lost\n");exit(1);}}
    PROBE;

const IGNORED = ['target', '__pycache__', 'mutants.out'];

/** @return array{int|null, string} the exit status (null on timeout) and the merged output */
function execute(array $command, string $directory, array $environment, string $log, int $timeout): array
{
    $result = Process::run($command, $environment, $directory, timeout: (float) $timeout, mergeErrors: true);
    $output = $result['status'] === null ? $result['stdout'] . "\nMUTATION DRIVER TIMEOUT\n" : $result['stdout'];
    file_put_contents($log, $output);
    return [$result['status'], $output];
}

function unifiedDiff(string $original, string $mutated, string $name): string
{
    $directory = ExceptionFixture::temporaryDirectory('profiler-vendor-diff-');
    file_put_contents("{$directory}/a", $original);
    file_put_contents("{$directory}/b", $mutated);
    return Process::run([
        'diff',
        '-u',
        '--label',
        $name,
        '--label',
        "{$name} (mutant)",
        "{$directory}/a",
        "{$directory}/b",
    ])['stdout'];
}

function writeSummary(string $output, array $summary): void
{
    file_put_contents("{$output}/results.json", Json::encode($summary, indent: 2, sortKeys: false) . "\n");
}

$root = Paths::resolve(dirname(__DIR__));
$usage = 'vendor-patch-mutation.php [--php <php>] [--php-config <php-config>] [--toolchain <name>] [--output-dir <directory>] [--timeout <seconds>]';
['options' => $options, 'positionals' => $positionals] = Arguments::parse(
    $argv,
    ['php', 'php-config', 'toolchain', 'output-dir', 'timeout'],
    usage: $usage,
);
if ($positionals !== []) {
    Arguments::fail('unrecognized arguments: ' . implode(' ', $positionals), $usage);
}
$php = (string) ($options['php'] ?? ((string) getenv('PHP') ?: 'php'));
$phpConfig = (string) ($options['php-config'] ?? ((string) getenv('PHP_CONFIG') ?: 'php-config'));
$php = Paths::which($php) ?? $php;
$phpConfig = Paths::which($phpConfig) ?? $phpConfig;
$toolchain = $options['toolchain'] ?? ((string) getenv('PROFILER_VENDOR_TOOLCHAIN') ?: null);
$timeout = (int) ($options['timeout'] ?? 600);
$output = Paths::resolve((string) ($options['output-dir'] ?? "{$root}/target/vendor-patch-mutation"));
if (!is_dir($output) && !mkdir($output, 0o777, true)) {
    ExceptionFixture::fail('create ' . $output);
}
$environment = [...Process::environment(), 'PHP' => $php, 'PHP_CONFIG' => $phpConfig];
// Always rebuild our copied fixture; never accidentally reuse a caller's DSO.
unset(
    $environment['PROFILER_FIXTURE_EXTENSION'],
    $environment['PROFILER_TEST_CLONE_BAILOUT'],
    $environment['PROFILER_TEST_EXCEPTION_BAILOUT'],
);
$cargo = [(string) getenv('CARGO') ?: 'cargo'];
if ($toolchain !== null) {
    $cargo[] = '+' . $toolchain;
}
$summary = [
    'scope' => 'targeted local exception ownership, attached-object semantics and clone-error ownership',
    'not_full_upstream_msi' => true,
    'trials' => [],
    'php' => $php,
    'php_config' => $phpConfig,
];
$isolated = ExceptionFixture::temporaryDirectory('profiler-vendor-mutation-');
Paths::copyTree("{$root}/vendor/ext-php-rs", "{$isolated}/vendor/ext-php-rs", IGNORED);
Paths::copyTree("{$root}/tests/support/exception-fixture", "{$isolated}/tests/support/exception-fixture", IGNORED);
foreach ([
    'php-tool.sh',
    'test-vendored-exception.php',
    'clone-bailout-tests.php',
    'exception-bailout-tests.php',
] as $name) {
    Paths::copyFile("{$root}/scripts/{$name}", "{$isolated}/scripts/{$name}");
}
Paths::copyTree("{$root}/scripts/lib", "{$isolated}/scripts/lib", IGNORED);
foreach ([
    ...(glob("{$root}/tests/native/vendored-exception*.php") ?: []),
    ...array_map(static fn(string $name): string => "{$root}/tests/native/{$name}", [
        'clone-bailout.c',
        'clone-bailout.php',
        'exception-bailout.c',
        'exception-bailout.php',
    ]),
] as $fixture) {
    Paths::copyFile($fixture, "{$isolated}/tests/native/" . basename($fixture));
}
$tool = "{$isolated}/scripts/php-tool.sh";
$sourcePath = "{$isolated}/vendor/ext-php-rs/src/exception.rs";
$handlerPath = "{$isolated}/vendor/ext-php-rs/src/zend/handlers.rs";
$handlerOriginal = (string) file_get_contents($handlerPath);
$original = (string) file_get_contents($sourcePath);
$summary['source_sha256'] = hash('sha256', $original);
$summary['handler_source_sha256'] = hash('sha256', $handlerOriginal);
$mutants = [
    'exception' => static fn(string $text): string => VendorMutations::withoutGuard($text, 'exception'),
    'message' => static fn(string $text): string => VendorMutations::withoutGuard($text, 'message'),
    'fallback' => VendorMutations::withoutFallbackGuard(...),
    'attached_object' => VendorMutations::withoutAttachedObject(...),
    'clone_message' => VendorMutations::withOwnedCloneMessage(...),
];
$absent = [];
$insert = static function (string $name, Closure $mutation) use (&$mutants): void {
    $mutants = array_slice($mutants, 0, 1, true) + [$name => $mutation] + array_slice($mutants, 1, null, true);
};
if (str_contains($original, 'let class = BailoutGuard::new(')) {
    $insert('class', static fn(string $text): string => VendorMutations::withoutGuard($text, 'class'));
} else {
    $absent[] = 'borrowed class-name guard';
}
if (str_contains($original, 'let err = BailoutGuard::new(err);')) {
    $insert('error', static fn(string $text): string => VendorMutations::withoutGuard($text, 'err'));
} else {
    $absent[] = 'heap-free Error guard';
}
$summary['absent_nonowning_guards'] = $absent;
$compiled = "{$output}/compiled-fixture";
$build = [
    ...$cargo,
    'build',
    '--locked',
    '--manifest-path',
    'tests/support/exception-fixture/Cargo.toml',
    '--target-dir',
    $compiled,
];
$suffix = PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so';
$runtimeEnvironment = [
    ...$environment,
    'PROFILER_FIXTURE_EXTENSION' => "{$compiled}/debug/libprofiler_exception_fixture.{$suffix}",
];
$cloneEnvironment = [...$environment, 'CARGO_TARGET_DIR' => "{$output}/compiled-clone-fixture"];
$injectedEnvironment = [...$environment, 'CARGO_TARGET_DIR' => "{$output}/compiled-exception-bailout-fixture"];
if ($toolchain !== null) {
    $cloneEnvironment['PROFILER_VENDOR_TOOLCHAIN'] = $toolchain;
    $injectedEnvironment['PROFILER_VENDOR_TOOLCHAIN'] = $toolchain;
}
[$cloneCode] = execute(
    [$tool, 'scripts/clone-bailout-tests.php'],
    $isolated,
    $cloneEnvironment,
    "{$output}/baseline.clone.log",
    $timeout,
);
$summary['baseline_proofs'] = ['clone' => $cloneCode];
writeSummary($output, $summary);
if ($cloneCode !== 0) {
    ExceptionFixture::fail('Unmutated clone ownership baseline failed; stopping.');
}
[$injectedCode] = execute(
    [$tool, 'scripts/exception-bailout-tests.php'],
    $isolated,
    $injectedEnvironment,
    "{$output}/baseline.injected.log",
    $timeout,
);
$summary['baseline_proofs']['injected_exception'] = $injectedCode;
writeSummary($output, $summary);
if ($injectedCode !== 0) {
    ExceptionFixture::fail('Unmutated injected exception ownership baseline failed; stopping.');
}
foreach (['baseline' => null, ...$mutants] as $name => $mutation) {
    $started = hrtime(true);
    file_put_contents($sourcePath, $original);
    file_put_contents($handlerPath, $handlerOriginal);
    $selectedPath = $name === 'clone_message' ? $handlerPath : $sourcePath;
    $selectedOriginal = $name === 'clone_message' ? $handlerOriginal : $original;
    $mutatedSource = $mutation === null ? $selectedOriginal : $mutation($selectedOriginal);
    file_put_contents($selectedPath, $mutatedSource);
    if ($mutation !== null) {
        file_put_contents(
            "{$output}/{$name}.diff",
            unifiedDiff($selectedOriginal, $mutatedSource, basename($selectedPath)),
        );
    }
    [$code] = execute($build, $isolated, $environment, "{$output}/{$name}.build.log", $timeout);
    if ($code !== 0) {
        $status = $code === null ? 'timeout' : 'unviable';
    } else {
        $testCommand = match ($name) {
            'clone_message' => [$tool, 'scripts/clone-bailout-tests.php'],
            'attached_object' => [
                $php,
                '-n',
                '-d',
                'extension=' . $runtimeEnvironment['PROFILER_FIXTURE_EXTENSION'],
                '-r',
                ATTACHED_PROBE,
            ],
            default => [$tool, 'scripts/test-vendored-exception.php'],
        };
        [$code, $text] = execute(
            $testCommand,
            $isolated,
            $name === 'clone_message' ? $cloneEnvironment : $runtimeEnvironment,
            "{$output}/{$name}.test.log",
            $timeout,
        );
        if (!in_array($name, ['baseline', 'clone_message', 'attached_object'], true)) {
            [$naturalCode, $naturalText] = [$code, $text];
            [$injectedCode, $injectedText] = execute(
                [$tool, 'scripts/exception-bailout-tests.php'],
                $isolated,
                $injectedEnvironment,
                "{$output}/{$name}.injected.log",
                $timeout,
            );
            if (
                !in_array($naturalCode, [0, null], true) && !str_contains($naturalText, LEAK_MARKER)
                || !in_array($injectedCode, [0, null], true) && !str_contains($injectedText, INJECTED_MARKER)
            ) {
                [$code, $text] = [1, 'unexpected natural/injected ownership test failure'];
            } elseif ($naturalCode === null || $injectedCode === null) {
                [$code, $text] = [null, $naturalText . $injectedText];
            } else {
                [$code, $text] = [$naturalCode || $injectedCode ? 1 : 0, $naturalText . $injectedText];
            }
        }
        $status = match (true) {
            $code === null => 'timeout',
            $code === 0 => $mutation === null ? 'passed' : 'missed',
            $name === 'clone_message' && str_contains($text, CLONE_MARKER) => 'clone_ownership_rejected',
            str_contains($text, LEAK_MARKER) || str_contains($text, INJECTED_MARKER) => 'ownership_rejected',
            $name === 'attached_object' && str_contains($text, ATTACHED_MARKER) => 'attached_semantics_rejected',
            default => 'other_failure',
        };
    }
    $summary['trials'][] = [
        'name' => $name,
        'status' => $status,
        'exit_code' => $code,
        'changed_file' => substr($selectedPath, strlen($isolated) + 1),
        'source_sha256' => hash('sha256', $mutatedSource),
        'seconds' => round((hrtime(true) - $started) / 1e9, 3),
    ];
    writeSummary($output, $summary);
    echo "{$name}: {$status}\n";
    if ($mutation === null && $status !== 'passed') {
        ExceptionFixture::fail('Unmutated native ownership baseline failed; stopping.');
    }
}
$trials = array_slice($summary['trials'], 1);
$summary['raw_counts'] = [];
foreach ([
    'ownership_rejected',
    'attached_semantics_rejected',
    'clone_ownership_rejected',
    'missed',
    'unviable',
    'timeout',
    'other_failure',
] as $status) {
    $summary['raw_counts'][$status] = count(array_filter(
        $trials,
        static fn(array $trial): bool => $trial['status'] === $status,
    ));
}
$summary['generated'] = count($trials);
writeSummary($output, $summary);
echo Json::encode($summary['raw_counts']), "\n";
// Survivors are never relabeled or silently excluded; equivalence needs review.
$expected = static fn(array $trial): string => match ($trial['name']) {
    'attached_object' => 'attached_semantics_rejected',
    'clone_message' => 'clone_ownership_rejected',
    default => 'ownership_rejected',
};
exit(array_filter($trials, static fn(array $trial): bool => $trial['status'] !== $expected($trial)) === [] ? 0 : 1);
