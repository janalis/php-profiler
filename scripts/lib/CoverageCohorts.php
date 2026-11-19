<?php

declare(strict_types=1);

namespace PhpProfiler\Tooling;

use Closure;
use InvalidArgumentException;

require_once __DIR__ . '/Paths.php';

/**
 * Unions measured source coverage from independently validated LLVM cohorts.
 *
 * No profile counters are invented. Raw exports remain authoritative; this computes
 * source coverage unions and preserves distinct generic instantiations. Every input's
 * source summaries are independently reconstructed and checked first.
 *
 * Python tuple keys become JSON-encoded strings; the tuple itself is kept beside the
 * value so results sort exactly like Python's tuple ordering.
 */
final class CoverageCohorts
{
    /**
     * @param list<array<string, mixed>> $reports
     * @param iterable<string> $expected
     * @param (Closure(string): string)|null $canonical
     * @return array<string, mixed>
     */
    public static function merge(array $reports, iterable $expected, ?Closure $canonical = null): array
    {
        $canonical ??= static fn(string $name): string => $name;
        $expectedFiles = [];
        foreach ($expected as $path) {
            $expectedFiles[Paths::resolve($path)] = true;
        }
        $unionLines = [];
        $unionBranches = [];
        $unionFunctions = [];
        $instances = [];
        $seen = [];
        foreach ($reports as $report) {
            if (($report['type'] ?? null) !== 'llvm.coverage.json.export' || count($report['data'] ?? []) !== 1) {
                throw new InvalidArgumentException('Expected one raw LLVM export dataset per cohort');
            }
            $dataset = $report['data'][0];
            $functions = [];
            $functionLines = [];
            foreach ($dataset['functions'] as $function) {
                $key = self::logicalFunction($function);
                $filename = $key[0];
                if (!isset($expectedFiles[$filename])) {
                    continue;
                }
                self::maximum($functions[$filename], $key, self::integer($function['count']));
                // LLVM file summaries sum logical-function line summaries, so separate
                // closures sharing a physical line remain distinct.
                foreach ($function['regions'] as $region) {
                    if (
                        $region[count($region) - 1] !== 0
                        || Paths::resolve($function['filenames'][$region[5]]) !== $filename
                    ) {
                        continue;
                    }
                    $last = $region[2] - ($region[3] === 1 ? 1 : 0);
                    for ($line = $region[0]; $line <= $last; $line++) {
                        self::maximum($functionLines[$filename], [$key, $line], self::integer($region[4]));
                    }
                }
                $name = $canonical($function['name']);
                $instanceKey = [$key, $name];
                $identity = self::identity($instanceKey);
                $shape = self::geometry($function);
                if (isset($instances[$identity])) {
                    $prior = &$instances[$identity][1];
                    if ($prior['geometry'] !== $shape) {
                        throw new InvalidArgumentException(sprintf(
                            'Incompatible coverage boundaries for %s at %s',
                            $name,
                            json_encode($key, JSON_UNESCAPED_SLASHES),
                        ));
                    }
                    $prior['count'] += self::integer($function['count']);
                    foreach ($function['regions'] as $index => $region) {
                        $prior['regions'][$index][4] += self::integer($region[4]);
                    }
                    foreach ($function['branches'] ?? [] as $index => $branch) {
                        $prior['branches'][$index][4] += self::integer($branch[4]);
                        $prior['branches'][$index][5] += self::integer($branch[5]);
                    }
                    $prior['raw_names'][$function['name']] = true;
                    unset($prior);
                } else {
                    $instances[$identity] = [
                        $instanceKey,
                        [
                            'geometry' => $shape,
                            'count' => self::integer($function['count']),
                            'regions' => $function['regions'],
                            'branches' => $function['branches'] ?? [],
                            'filenames' => $function['filenames'],
                            'raw_names' => [$function['name'] => true],
                        ],
                    ];
                }
            }
            $cohortFiles = [];
            foreach ($dataset['files'] as $entry) {
                $filename = Paths::resolve($entry['filename']);
                if (!isset($expectedFiles[$filename])) {
                    continue;
                }
                if (isset($cohortFiles[$filename])) {
                    throw new InvalidArgumentException('Duplicate cohort source file: ' . $filename);
                }
                $cohortFiles[$filename] = true;
                $seen[$filename] = true;
                $lines = $functionLines[$filename] ?? [];
                $branches = self::branchCounts($entry['branches'] ?? []);
                $counts = [
                    'lines' => $lines,
                    'functions' => $functions[$filename] ?? [],
                    'branches' => self::sides($branches),
                ];
                foreach ($counts as $metric => $values) {
                    $actual = self::summary($values);
                    $declared = $entry['summary'][$metric] ?? [];
                    foreach ($actual as $field => $value) {
                        if (($declared[$field] ?? null) !== $value) {
                            throw new InvalidArgumentException(sprintf(
                                'Cannot reconstruct LLVM %s summary for %s: %s != %s',
                                $metric,
                                $filename,
                                json_encode($actual),
                                json_encode($declared),
                            ));
                        }
                    }
                }
                foreach ($lines as $identity => [$line, $count]) {
                    self::maximum($unionLines[$filename], $line, $count, $identity);
                }
                foreach ($branches as $identity => [$branch, [$true, $false]]) {
                    $previous = $unionBranches[$filename][$identity][1] ?? [0, 0];
                    $unionBranches[$filename][$identity] = [$branch, [$previous[0] + $true, $previous[1] + $false]];
                }
                foreach ($functions[$filename] ?? [] as $identity => [$key, $count]) {
                    self::maximum($unionFunctions[$filename], $key, $count, $identity);
                }
            }
        }
        $missing = array_diff_key($expectedFiles, $seen);
        if ($missing !== []) {
            $names = array_map(strval(...), array_keys($missing));
            sort($names, SORT_STRING);
            throw new InvalidArgumentException(
                'First-party source absent from cohort exports: ' . implode(', ', $names),
            );
        }
        $files = [];
        $names = array_map(strval(...), array_keys($expectedFiles));
        sort($names, SORT_STRING);
        foreach ($names as $filename) {
            $branches = $unionBranches[$filename] ?? [];
            $lines = self::sorted($unionLines[$filename] ?? []);
            $files[] = [
                'filename' => $filename,
                'summary' => [
                    'lines' => self::summary($lines),
                    'functions' => self::summary($unionFunctions[$filename] ?? []),
                    'branches' => self::summary(self::sides($branches)),
                ],
                'measured_lines' => array_map(static fn(array $item): array => [
                    'function' => $item[0][0],
                    'line' => $item[0][1],
                    'count' => $item[1],
                ], $lines),
                'measured_branches' => array_map(static fn(array $item): array => [
                    'coordinates' => $item[0],
                    'counts' => $item[1],
                ], self::sorted($branches)),
            ];
        }
        $totals = [];
        foreach (['lines', 'functions', 'branches'] as $metric) {
            foreach (['count', 'covered'] as $field) {
                $totals[$metric][$field] = array_sum(array_map(
                    static fn(array $file): int => $file['summary'][$metric][$field],
                    $files,
                ));
            }
        }
        $instantiations = [];
        foreach (self::sorted($instances) as [[$key, $name], $value]) {
            $rawNames = array_map(strval(...), array_keys($value['raw_names']));
            sort($rawNames, SORT_STRING);
            $instantiations[] = [
                'source_identity' => $key,
                'name' => $name,
                'count' => $value['count'],
                'regions' => $value['regions'],
                'branches' => $value['branches'],
                'filenames' => $value['filenames'],
                'raw_names' => $rawNames,
            ];
        }
        return [
            'type' => 'php-profiler.coverage.cohort-union',
            'version' => 1,
            'method' => 'Source coverage OR across strict LLVM cohorts; per-instantiation measured counters summed',
            'data' => [['files' => $files, 'totals' => $totals]],
            'instantiations' => $instantiations,
        ];
    }

    /**
     * Branch outcomes keyed by source coordinates and kind; file identifiers are
     * function-local, while coordinates are already file-local in LLVM's per-file view.
     *
     * @param list<list<int>> $branches
     * @return array<string, array{list<int>, array{int, int}}>
     */
    private static function branchCounts(array $branches): array
    {
        $measured = [];
        foreach ($branches as $branch) {
            if (count($branch) !== 9) {
                throw new InvalidArgumentException('Unsupported LLVM branch record');
            }
            $key = [...array_slice($branch, 0, 4), $branch[8]];
            $identity = self::identity($key);
            $previous = $measured[$identity][1] ?? [0, 0];
            $measured[$identity] = [
                $key,
                [$previous[0] + self::integer($branch[4]), $previous[1] + self::integer($branch[5])],
            ];
        }
        return $measured;
    }

    /**
     * Each branch outcome as its own counter, keyed by (coordinates, side).
     *
     * @param array<string, array{list<int>, array{int, int}}> $branches
     * @return array<string, array{mixed, int}>
     */
    private static function sides(array $branches): array
    {
        $sides = [];
        foreach ($branches as [$key, $values]) {
            foreach ($values as $side => $value) {
                $sides[self::identity([$key, $side])] = [[$key, $side], $value];
            }
        }
        return $sides;
    }

    /** @return array{string, int, int} */
    private static function logicalFunction(array $function): array
    {
        if (($function['regions'] ?? []) === []) {
            throw new InvalidArgumentException('Function has no source regions');
        }
        $first = $function['regions'][0];
        return [Paths::resolve($function['filenames'][$first[5]]), $first[0], $first[1]];
    }

    /** @return array{list<list<mixed>>, list<list<mixed>>} */
    private static function geometry(array $function): array
    {
        $files = $function['filenames'];
        $region = static function (array $record, bool $branch) use ($files): array {
            if (count($record) !== ($branch ? 9 : 8)) {
                throw new InvalidArgumentException('Unsupported LLVM function region');
            }
            [$file, $expanded] = $branch ? [6, 7] : [5, 6];
            return [
                ...array_slice($record, 0, 4),
                Paths::resolve($files[$record[$file]]),
                Paths::resolve($files[$record[$expanded]]),
                $record[count($record) - 1],
            ];
        };
        return [
            array_map(static fn(array $item): array => $region($item, false), $function['regions']),
            array_map(static fn(array $item): array => $region($item, true), $function['branches'] ?? []),
        ];
    }

    /**
     * @param array<string, array{mixed, int}> $counts
     * @return array{count: int, covered: int}
     */
    private static function summary(array $counts): array
    {
        return [
            'count' => count($counts),
            'covered' => count(array_filter($counts, static fn(array $item): bool => $item[1] > 0)),
        ];
    }

    /** @param array<string, array{mixed, int}>|null $map */
    private static function maximum(?array &$map, mixed $key, int $value, ?string $identity = null): void
    {
        $identity ??= self::identity($key);
        $map[$identity] = [$key, max($map[$identity][1] ?? 0, $value)];
    }

    private static function identity(mixed $key): string
    {
        return json_encode($key, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Values ordered by their key tuples, like sorted(mapping.items()).
     *
     * @template T
     * @param array<string, array{mixed, T}> $map
     * @return list<array{mixed, T}>
     */
    private static function sorted(array $map): array
    {
        $items = array_values($map);
        usort($items, static fn(array $left, array $right): int => self::compareTuples($left[0], $right[0]));
        return $items;
    }

    private static function compareTuples(mixed $left, mixed $right): int
    {
        if (is_array($left) && is_array($right)) {
            foreach ($left as $index => $item) {
                if (!array_key_exists($index, $right)) {
                    return 1;
                }
                $order = self::compareTuples($item, $right[$index]);
                if ($order !== 0) {
                    return $order;
                }
            }
            return count($left) <=> count($right);
        }
        if (is_string($left) && is_string($right)) {
            return strcmp($left, $right) <=> 0;
        }
        if (is_int($left) && is_int($right)) {
            return $left <=> $right;
        }
        throw new InvalidArgumentException('Unordered coverage key types');
    }

    private static function integer(mixed $value): int
    {
        if (!is_int($value)) {
            throw new InvalidArgumentException('Unsupported LLVM counter value: ' . json_encode($value));
        }
        return $value;
    }
}
