<?php

declare(strict_types=1);

namespace PhpProfiler\Tooling;

use InvalidArgumentException;
use JsonException;

require_once __DIR__ . '/CoverageCohorts.php';
require_once __DIR__ . '/Json.php';
require_once __DIR__ . '/Paths.php';
require_once __DIR__ . '/Process.php';

/** Strict cohort exports avoid unmangled symbol collisions across test and native builds. */
final class NativeCoverageExport
{
    /** @return list<string> */
    public static function cargoObjects(string $run, string $record): array
    {
        $objects = [];
        $unitObjectFound = false;
        foreach (explode("\n", (string) file_get_contents("{$run}/{$record}")) as $line) {
            try {
                $message = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }
            if (
                !is_array($message)
                || ($message['reason'] ?? null) !== 'compiler-artifact'
                || !($message['executable'] ?? null)
                || !($message['profile']['test'] ?? false)
            ) {
                continue;
            }
            $artifact = Paths::resolve($message['executable']);
            if (!is_file($artifact)) {
                throw new InvalidArgumentException('Coverage test executable disappeared: ' . $artifact);
            }
            $objects[$artifact] = true;
            $kinds = $message['target']['kind'] ?? [];
            $unitObjectFound =
                $unitObjectFound
                || array_intersect(['lib', 'rlib', 'dylib', 'cdylib', 'staticlib', 'proc-macro'], $kinds) !== [];
        }
        if (!$unitObjectFound) {
            throw new InvalidArgumentException('Exact unit-test executable absent from Cargo records: ' . $record);
        }
        $names = array_map(strval(...), array_keys($objects));
        sort($names, SORT_STRING);
        return $names;
    }

    /**
     * @param list<string> $objects
     * @return array<string, mixed>
     */
    public static function strictExport(
        string $llvmCov,
        array $objects,
        string $profile,
        string $output,
        string $diagnostics,
    ): array {
        $command = [$llvmCov, 'export', $objects[0], '-instr-profile=' . $profile];
        foreach (array_slice($objects, 1) as $artifact) {
            array_push($command, '-object', $artifact);
        }
        $result = Process::run($command);
        file_put_contents($output, $result['stdout']);
        file_put_contents(
            $diagnostics,
            Json::encode(
                [
                    'command' => $command,
                    'objects' => $objects,
                    'exit_code' => $result['status'],
                    'stderr' => $result['stderr'],
                ],
                indent: 2,
                sortKeys: false,
            )
                . "\n",
        );
        if ($result['status'] !== 0) {
            throw new ProcessFailedStatus($command, $result['status'], '');
        }
        if ($result['stderr'] !== '') {
            throw new InvalidArgumentException('LLVM cohort export failed or emitted diagnostics; raw evidence retained: '
            . $diagnostics);
        }
        return json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<array<string, mixed>> $reports
     * @return array<string, string>
     */
    public static function demangledNames(array $reports, ?string $path = null): array
    {
        $executable =
            Paths::which('llvm-cxxfilt', $path) ?? Paths::which('c++filt', $path) ?? throw new InvalidArgumentException(
                'A Rust-capable LLVM/GNU c++filt demangler is required',
            );
        $names = [];
        foreach ($reports as $report) {
            foreach ($report['data'] as $dataset) {
                foreach ($dataset['functions'] as $function) {
                    $names[$function['name']] = true;
                }
            }
        }
        $names = array_map(strval(...), array_keys($names));
        sort($names, SORT_STRING);
        foreach ($names as $name) {
            if (str_contains($name, "\n")) {
                throw new InvalidArgumentException('Unsupported newline in coverage function name');
            }
        }
        $version = Process::output([$executable, '--version']);
        $command = [$executable, '-n'];
        if (str_contains($version, 'GNU')) {
            $command[] = '-i'; // Drop crate disambiguators, retaining generic type arguments.
        }
        $output = explode("\n", Process::output($command, input: implode("\n", $names) . "\n"));
        if (end($output) === '') {
            array_pop($output);
        }
        if (count($output) !== count($names)) {
            throw new InvalidArgumentException('Demangler changed function record cardinality');
        }
        $result = array_combine($names, $output);
        foreach ($names as $name) {
            if (str_starts_with($name, '_R') && $result[$name] === $name) {
                throw new InvalidArgumentException(
                    'Demangler does not support Rust v0 names; install a current LLVM demangler',
                );
            }
        }
        return $result;
    }

    public static function main(string $llvmCov, string $run, string $extension, string $mergedProfile): void
    {
        $extension = Paths::resolve($extension);
        $build = dirname(Paths::resolve($mergedProfile));
        $objects = [
            'core' => self::cargoObjects($run, 'core-tests.jsonl'),
            'adapter' => self::cargoObjects($run, 'adapter-tests.jsonl'),
            'native' => [$extension],
        ];
        $directory = "{$run}/cohorts";
        if (!mkdir($directory)) {
            throw new InvalidArgumentException('create ' . $directory);
        }
        $reports = [];
        $receipts = [];
        foreach ($objects as $name => $artifacts) {
            $profiles = glob("{$build}/{$name}-*.profraw") ?: [];
            sort($profiles, SORT_STRING);
            if ($profiles === []) {
                throw new InvalidArgumentException('No actual raw profiles recorded for cohort: ' . $name);
            }
            foreach ($profiles as $path) {
                if (str_contains($path, "\n")) {
                    throw new InvalidArgumentException('Unsupported newline in raw profile path');
                }
            }
            $manifest = "{$directory}/{$name}-profiles.txt";
            file_put_contents($manifest, implode("\n", $profiles) . "\n");
            $profile = "{$directory}/{$name}.profdata";
            // Like Path.with_name(): a bare name stays a bare name, resolved through PATH.
            $profdata = str_contains($llvmCov, '/') ? dirname($llvmCov) . '/llvm-profdata' : 'llvm-profdata';
            $merge = [
                $profdata,
                'merge',
                '--sparse',
                '--failure-mode=any',
                '--input-files=' . $manifest,
                '-o',
                $profile,
            ];
            $merged = Process::run($merge);
            fwrite(STDOUT, $merged['stdout']);
            fwrite(STDERR, $merged['stderr']);
            if ($merged['status'] !== 0) {
                throw new ProcessFailedStatus($merge, $merged['status'], '');
            }
            $output = "{$directory}/{$name}.json";
            $reports[] = self::strictExport(
                $llvmCov,
                $artifacts,
                $profile,
                $output,
                "{$directory}/{$name}-diagnostics.json",
            );
            $receipts[] = [
                'cohort' => $name,
                'export' => $output,
                'sha256' => hash_file('sha256', $output),
                'objects' => (object) array_combine($artifacts, array_map(
                    static fn(string $path): string => (string) hash_file('sha256', $path),
                    $artifacts,
                )),
                'raw_profiles' => (object) array_combine($profiles, array_map(
                    static fn(string $path): string => (string) hash_file('sha256', $path),
                    $profiles,
                )),
            ];
        }
        $canonical = self::demangledNames($reports);
        $merged = CoverageCohorts::merge(
            $reports,
            Paths::runtimeSources((string) getcwd(), 'rust'),
            static fn(string $name): string => $canonical[$name],
        );
        $merged['cohorts'] = $receipts;
        file_put_contents("{$run}/coverage-native-first.json", Json::encode($merged, sortKeys: false) . "\n");
        file_put_contents(
            "{$run}/export-diagnostics.json",
            Json::encode(
                [
                    'exit_code' => 0,
                    'method' => $merged['method'],
                    'cohorts' => $receipts,
                    'stderr' => '',
                ],
                indent: 2,
                sortKeys: false,
            )
                . "\n",
        );
    }
}
