<?php

declare(strict_types=1);

require_once __DIR__ . '/support.php';
require_once ROOT . '/scripts/lib/NativeCoverageExport.php';

use PhpProfiler\Tooling\NativeCoverageExport;
use PhpProfiler\Tooling\Paths;
use PhpProfiler\Tooling\Process;

/** Every exported cohort must retain exact objects and reject uncertain data. */
function runNativeExport(
    string $stderr = '',
    int $status = 0,
    bool $missing = false,
    bool $missingProfiles = false,
): array {
    $directory = temporaryDirectory();
    $artifacts = ["{$directory}/core-test", "{$directory}/adapter-test"];
    foreach ($artifacts as $index => $artifact) {
        if (!($missing && $index === 1)) {
            touch($artifact);
        }
        $message = [
            'reason' => 'compiler-artifact',
            'executable' => $artifact,
            'profile' => ['test' => true],
            'target' => ['kind' => [$index === 0 ? 'lib' : 'cdylib']],
        ];
        writeFile(
            $directory . '/' . ($index === 0 ? 'core-tests.jsonl' : 'adapter-tests.jsonl'),
            "test result: ok\n" . json_encode($message) . "\n",
        );
    }
    $export = ['type' => 'llvm.coverage.json.export', 'data' => [['functions' => [], 'files' => []]]];
    foreach (Paths::runtimeSources(ROOT, 'rust') as $source) {
        $export['data'][0]['functions'][] = [
            'name' => 'fixture_function',
            'count' => 1,
            'filenames' => [$source],
            'regions' => [[1, 1, 1, 9, 1, 0, 0, 0]],
            'branches' => [[1, 2, 1, 4, 1, 1, 0, 0, 4]],
        ];
        $export['data'][0]['files'][] = [
            'filename' => $source,
            'segments' => [[1, 1, 1, true, true, false], [1, 9, 0, false, false, false]],
            'branches' => [[1, 2, 1, 4, 1, 1, 0, 0, 4]],
            'summary' => [
                'lines' => ['count' => 1, 'covered' => 1],
                'functions' => ['count' => 1, 'covered' => 1],
                'branches' => ['count' => 2, 'covered' => 2],
            ],
        ];
    }
    writeFile("{$directory}/export.json", json_encode($export, JSON_UNESCAPED_SLASHES));
    writeFile(
        "{$directory}/llvm-cov",
        sprintf(
            "#!/bin/sh\ncat %s\nprintf '%%s' %s >&2\nexit %d\n",
            escapeshellarg("{$directory}/export.json"),
            escapeshellarg($stderr),
            $status,
        ),
        0o700,
    );
    writeFile(
        "{$directory}/llvm-profdata",
        "#!/bin/sh\nwhile [ \"\$1\" != -o ]; do shift; done\nprintf fixture > \"\$2\"\n",
        0o700,
    );
    foreach (['core', 'adapter', 'native'] as $cohort) {
        if (!($missingProfiles && $cohort === 'native')) {
            writeFile("{$directory}/{$cohort}-100-123.profraw", $cohort);
        }
    }
    touch("{$directory}/unrelated-100.profraw");
    touch("{$directory}/native.so");
    $result = Process::run([
        ROOT . '/scripts/php-tool.sh',
        ROOT . '/scripts/export-native-coverage.php',
        "{$directory}/llvm-cov",
        $directory,
        "{$directory}/native.so",
        "{$directory}/merged.profdata",
    ], directory: ROOT);
    $diagnostics = is_file("{$directory}/export-diagnostics.json")
        ? "{$directory}/export-diagnostics.json"
        : "{$directory}/cohorts/core-diagnostics.json";
    $receipt = is_file($diagnostics) ? json_decode((string) file_get_contents($diagnostics), true) : null;
    return [$result, $receipt, $directory];
}

function fakeDemangler(string $version): array
{
    $directory = temporaryDirectory();
    writeFile(
        "{$directory}/c++filt",
        sprintf(
            "#!/bin/sh\nif [ \"\$1\" = --version ]; then printf '%%s\\n' %s; exit 0; fi\nprintf '%%s\\n' \"\$*\" > %s\nsed 's/_Rvalid/crate::generic::<u64>/'\n",
            escapeshellarg($version),
            escapeshellarg("{$directory}/arguments"),
        ),
        0o755,
    );
    return [$directory, "{$directory}/arguments"];
}

return [
    'exact objects and disjoint actual profiles are recorded' => static function (): void {
        [$result, $diagnostics, $directory] = runNativeExport();
        same(0, $result['status'], $result['stderr']);
        same(['core', 'adapter', 'native'], array_column($diagnostics['cohorts'], 'cohort'));
        foreach ($diagnostics['cohorts'] as $entry) {
            same(1, count($entry['objects']));
            same(1, count($entry['raw_profiles']));
            same(true, str_ends_with(
                (string) array_key_first($entry['raw_profiles']),
                $entry['cohort'] . '-100-123.profraw',
            ));
        }
        same([realpath("{$directory}/native.so")], array_keys($diagnostics['cohorts'][2]['objects']));
        same(0, $diagnostics['exit_code']);
        $merged = json_decode((string) file_get_contents("{$directory}/coverage-native-first.json"), true);
        same('php-profiler.coverage.cohort-union', $merged['type']);
        same(count(Paths::runtimeSources(ROOT, 'rust')), count($merged['data'][0]['files']));
    },
    'demangler canonicalizes gnu and llvm without losing generics' => static function (): void {
        $exports = [['data' => [['functions' => [['name' => '_Rvalid']]]]]];
        foreach (['GNU c++filt 2.40' => '-n -i', 'LLVM cxxfilt 23.1' => '-n'] as $version => $flags) {
            [$path, $arguments] = fakeDemangler($version);
            same(['_Rvalid' => 'crate::generic::<u64>'], NativeCoverageExport::demangledNames($exports, $path));
            same($flags . "\n", file_get_contents($arguments));
        }
    },
    'a demangler that ignores rust v0 names is refused' => static function (): void {
        [$path] = fakeDemangler('GNU c++filt 2.40');
        throws(InvalidArgumentException::class, static fn() => NativeCoverageExport::demangledNames([[
            'data' => [['functions' => [['name' => '_Rother']]]],
        ]], $path));
    },
    'missing cargo artifact fails before export' => static function (): void {
        [$result, $diagnostics] = runNativeExport(missing: true);
        same(1, $result['status']);
        same(true, str_contains($result['stderr'], 'disappeared'), $result['stderr']);
        same(null, $diagnostics);
    },
    'missing actual cohort profiles fails' => static function (): void {
        [$result] = runNativeExport(missingProfiles: true);
        same(1, $result['status']);
        same(true, str_contains($result['stderr'], 'No actual raw profiles'), $result['stderr']);
    },
    'llvm failure is retained and rejected' => static function (): void {
        [$result, $diagnostics] = runNativeExport(stderr: 'malformed profile', status: 7);
        same(7, $result['status']);
        same('malformed profile', $diagnostics['stderr']);
    },
    'zero exit with mismatched map is still rejected' => static function (): void {
        [$result, $diagnostics] = runNativeExport(stderr: "warning: 1 functions have mismatched data\n");
        same(1, $result['status']);
        same(0, $diagnostics['exit_code']);
        same(true, str_contains($diagnostics['stderr'], 'mismatched'));
    },
];
