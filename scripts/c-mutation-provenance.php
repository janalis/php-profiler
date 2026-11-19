<?php

declare(strict_types=1);

// Provenance for scripts/c-mutation.sh: Mull's source registry, the source manifest,
// the tool and equivalence-context records, and the run's final manifest.
require_once __DIR__ . '/lib/Json.php';
require_once __DIR__ . '/lib/Paths.php';
require_once __DIR__ . '/lib/Process.php';
require_once __DIR__ . '/lib/PythonRegex.php';
require_once __DIR__ . '/lib/Shlex.php';

use PhpProfiler\Tooling\Json;
use PhpProfiler\Tooling\Paths;
use PhpProfiler\Tooling\Process;
use PhpProfiler\Tooling\PythonRegex;
use PhpProfiler\Tooling\Shlex;

function provenanceFail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function environment(string $name): string
{
    $value = getenv($name);
    return $value === false ? provenanceFail("'{$name}'") : $value;
}

function writeJson(string $path, mixed $value): void
{
    file_put_contents($path, Json::encode($value, indent: 2, sortKeys: false) . "\n") !== false
        || provenanceFail('write ' . $path);
}

$root = (string) getcwd();
$runtimeC = array_map(
    static fn(string $path): string => substr($path, strlen($root) + 1),
    Paths::runtimeSources($root, 'c'),
);
switch ($argv[1] ?? '') {
    case 'config':
        writeJson($argv[2], [
            'includePaths' => array_map(static fn(string $path): string => PythonRegex::escape($path) . '$', $runtimeC),
            'mutators' => ['cxx_all'],
        ]);
        break;

    case 'manifest':
        [, , $run, $extension] = $argv;
        $paths = [
            'crates/php-profiler/src/sampling.h',
            'tests/native/sampling-unit.c',
            'tests/native/sampling-controller.php',
            'scripts/sampling-controller-tests.py',
            'tests/native/bridge-unit.c',
            'tests/native/bailout-fault.c',
            'scripts/c-mutation-tests.sh',
            'scripts/native-bailout-tests.py',
            'scripts/native-fault-tests.sh',
            'tests/native/clock-fault.c',
            'tests/native/clock-boundary.php',
            'Cargo.lock',
            ...$runtimeC,
        ];
        $manifest = [];
        foreach ($paths as $path) {
            $manifest[$path] = hash_file('sha256', $path) ?: provenanceFail('read ' . $path);
        }
        $manifest['extension_binary'] = hash_file('sha256', $extension) ?: provenanceFail('read ' . $extension);
        writeJson("{$run}/provenance/source-manifest.json", $manifest);
        break;

    case 'context':
        [, , $run, $runner] = $argv;
        $compilerName = environment('CC');
        $record = [
            'compiler' => $compilerName,
            'compiler_version' => Process::output([$compilerName, '--version']),
            'mull_version' => Process::output([$runner, '--version']),
            'plugin_sha256' => hash_file('sha256', environment('MULL_PLUGIN')) ?: provenanceFail('read MULL_PLUGIN'),
            'compiler_flags' => environment('CFLAGS'),
            'instrumentation_registry' => json_decode(
                (string) file_get_contents(environment('MULL_CONFIG')),
                false,
                flags: JSON_THROW_ON_ERROR,
            ),
            'baseline_timeout_ms' => (int) (getenv('MULL_TIMEOUT_MS') ?: '120000'),
        ];
        writeJson("{$run}/provenance/tools.json", $record);
        // Configuration-sensitive equivalents must never silently transfer across PHP,
        // compiler, mutator, target or libc versions. Capture this before the runner.
        $php = json_decode(
            Process::output([
                getenv('PHP') ?: 'php',
                '-n',
                '-r',
                'echo json_encode(["php_zts"=>(bool)PHP_ZTS,"php_version_id"=>PHP_VERSION_ID,"php_int_size"=>PHP_INT_SIZE]);',
            ]),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $compilerPath = str_contains($compilerName, '/') ? $compilerName : Paths::which($compilerName);
        if ($compilerPath === null || !is_file($compilerPath)) {
            provenanceFail('compiler not found: ' . $compilerName);
        }
        $commands = [];
        $process = proc_open(
            ['readelf', '--debug-dump=info', environment('PROFILER_EXTENSION')],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => STDERR],
            $pipes,
        );
        if ($process === false) {
            provenanceFail('start readelf');
        }
        while (($line = fgets($pipes[1])) !== false) {
            if (!str_contains($line, 'DW_AT_producer') || !str_contains($line, ' -grecord-command-line ')) {
                continue;
            }
            $producer = trim(str_contains($line, '): ') ? explode('): ', $line, 2)[1] : $line);
            $tokens = Shlex::split($producer);
            foreach (['bridge-unit.c', 'sampling-unit.c'] as $unit) {
                if ($tokens !== [] && str_ends_with($tokens[count($tokens) - 1], '/' . $unit)) {
                    $output = array_search('-o', $tokens, true);
                    if ($output === false) {
                        provenanceFail("'-o' is not in list");
                    }
                    array_splice($tokens, $output, 2); // Object-output paths carry no codegen meaning.
                    if (isset($commands[$unit]) && $commands[$unit] !== $tokens) {
                        provenanceFail('C mutation gate: ambiguous native compile-command provenance');
                    }
                    $commands[$unit] = $tokens;
                }
            }
        }
        fclose($pipes[1]);
        if (proc_close($process) !== 0 || count($commands) !== 2) {
            provenanceFail('C mutation gate: native compile-command provenance missing');
        }
        $fixtureSymbols = [];
        foreach (explode("\n", Process::output(['nm', '--defined-only', environment('PROFILER_EXTENSION')])) as $line) {
            $fields = preg_split('/\s+/', trim($line), flags: PREG_SPLIT_NO_EMPTY);
            $symbol = $fields === [] ? '' : $fields[count($fields) - 1];
            if (str_starts_with($symbol, '__wrap_') || str_starts_with($symbol, '__cyg_profile_')) {
                $fixtureSymbols[$symbol] = true;
            }
        }
        $fixtureSymbols = array_map(strval(...), array_keys($fixtureSymbols));
        sort($fixtureSymbols, SORT_STRING);
        $context = [
            ...$php,
            'native_compile_commands' => (object) $commands,
            'native_fixture_symbols' => $fixtureSymbols,
            'rustc_version' => Process::output(['rustc', '--version', '--verbose']),
            'rustflags' => (string) getenv('RUSTFLAGS'),
            'cargo_encoded_rustflags' => (string) getenv('CARGO_ENCODED_RUSTFLAGS'),
            'system' => php_uname('s'),
            'machine' => php_uname('m'),
            'compiler_version' => $record['compiler_version'],
            'mull_version' => $record['mull_version'],
            'compiler_sha256' => hash_file('sha256', Paths::resolve($compilerPath)),
            'plugin_sha256' => $record['plugin_sha256'],
            'compiler_flags' => Shlex::split($record['compiler_flags']),
        ];
        // Read the already-loaded libc mapping; never execute an untrusted ldd target.
        if (is_file('/proc/self/maps')) {
            $libraries = [];
            foreach (explode("\n", (string) file_get_contents('/proc/self/maps')) as $line) {
                $fields = preg_split('/\s+/', trim($line), flags: PREG_SPLIT_NO_EMPTY);
                if (count($fields) >= 6 && basename($fields[count($fields) - 1]) === 'libc.so.6') {
                    $libraries[$fields[count($fields) - 1]] = true;
                }
            }
            if (count($libraries) === 1) {
                $context['libc_sha256'] = hash_file('sha256', (string) array_key_first($libraries));
            }
        }
        writeJson("{$run}/provenance/equivalence-context.json", $context);
        break;

    case 'finish':
        [, , $run, $status] = $argv;
        $provenance = "{$run}/provenance";
        $before = json_decode(
            (string) file_get_contents("{$provenance}/source-before.json"),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $after = json_decode(
            (string) file_get_contents("{$provenance}/source-after.json"),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $changed = [];
        foreach (array_keys($before + $after) as $path) {
            if (($before[$path] ?? null) !== ($after[$path] ?? null)) {
                $changed[] = (string) $path;
            }
        }
        sort($changed, SORT_STRING);
        $status = (int) $status ?: ($changed === [] ? 0 : 1);
        $reports = [];
        foreach (glob("{$run}/*.json") ?: [] as $path) {
            $reports[substr($path, strlen($run) + 1)] = hash_file('sha256', $path);
        }
        writeJson("{$provenance}/run.json", [
            'exit_code' => $status,
            'source_snapshot_matches' => $changed === [],
            'changed_inputs' => $changed,
            'elements_report_sha256' => (object) $reports,
        ]);
        exit($status);

    default:
        provenanceFail(
            'usage: c-mutation-provenance.php config <path> | manifest <run> <extension> | context <run> <runner> | finish <run> <status>',
        );
}
