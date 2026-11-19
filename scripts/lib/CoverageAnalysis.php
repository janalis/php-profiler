<?php

declare(strict_types=1);

namespace PhpProfiler\Tooling;

use InvalidArgumentException;

/** Explains GCC branch gaps without changing raw counts or relaxing the gate. */
final class CoverageAnalysis
{
    /** @return array<string, array{signature: list<string>, arcs: array<int, list<int>>, locations: array<int, list<array{string, int}>>}> */
    public static function notes(string $text): array
    {
        $functions = [];
        $current = null;
        foreach (self::lines($text) as $line) {
            if (preg_match(
                "/FUNCTION ident=(\\d+), lineno_checksum=(0x\\w+), cfg_checksum=(0x\\w+), `([^']+)'/",
                $line,
                $match,
            )) {
                $functions[$match[4]] = [
                    'signature' => [$match[1], $match[2], $match[3]],
                    'arcs' => [],
                    'locations' => [],
                ];
                $current = &$functions[$match[4]];
                continue;
            }
            if ($current === null) {
                continue;
            }
            if (preg_match("/block (\\d+):`([^']+)':([\\d, ]+)/", $line, $match)) {
                foreach (explode(',', $match[3]) as $number) {
                    $current['locations'][(int) $match[1]][] = [$match[2], self::integer($number)];
                }
                continue;
            }
            if (preg_match('/block (\d+):\s+(.+)/', $line, $match)) {
                preg_match_all('/(\d+):[0-9a-f]+(?:\(([^)]*)\))?/', $match[2], $arcs, PREG_SET_ORDER);
                $current['arcs'][(int) $match[1]] = array_values(array_map(
                    static fn(array $arc): int => (int) $arc[1],
                    array_filter($arcs, static fn(array $arc): bool => !str_contains($arc[2] ?? '', 'fake')),
                ));
            }
        }
        unset($current);
        return $functions;
    }

    /** @return array<string, array<int, string>> */
    public static function treeFunctions(string $text): array
    {
        $result = [];
        $parts = preg_split('/^;; Function ([\w.]+) .*$/m', $text, flags: PREG_SPLIT_DELIM_CAPTURE);
        for ($index = 1; $index < count($parts); $index += 2) {
            $blocks = preg_split('/^\s*<bb (\d+)>[^\n]*\n/m', $parts[$index + 1], flags: PREG_SPLIT_DELIM_CAPTURE);
            $result[$parts[$index]] = [];
            for ($block = 1; $block < count($blocks); $block += 2) {
                $result[$parts[$index]][(int) $blocks[$block]] = $blocks[$block + 1];
            }
        }
        return $result;
    }

    /** @return array<string, mixed>|null */
    public static function constantHeaderOutcome(
        ?string $function,
        int $line,
        int $outcome,
        int $branchCount,
        array $rawNotes,
        array $rebuiltNotes,
        array $tree,
        string $suffix,
    ): ?array {
        $original = $rawNotes[$function ?? ''] ?? null;
        $rebuilt = $rebuiltNotes[$function ?? ''] ?? null;
        if (!$original || !$rebuilt || $original['signature'] !== $rebuilt['signature']) {
            return null;
        }
        // GCC format 1 lacks branch block IDs. Require a unique matching source block;
        // never guess across several conditionals attributed to one source line.
        $candidates = [];
        foreach ($original['arcs'] as $block => $arcs) {
            if (count($arcs) !== $branchCount || count($arcs) !== 2) {
                continue;
            }
            foreach ($original['locations'][$block] ?? [] as [$path, $number]) {
                if (str_ends_with($path, $suffix) && $number === $line) {
                    $candidates[] = [$block, $arcs];
                    break;
                }
            }
        }
        if (count($candidates) !== 1) {
            return null;
        }
        [$block, $arcs] = $candidates[0];
        $body = $tree[$function ?? ''][$block] ?? '';
        $found = preg_match(
            '/\[([^\]]+):(\d+):(\d+)\] if \((\w+_\d+) (!=|==|<|>|<=|>=) (-?\d+)\)\s*\n\s*goto <bb (\d+)>;[^\n]*\n\s*else\s*\n\s*goto <bb (\d+)>;/',
            $body,
            $condition,
            PREG_OFFSET_CAPTURE,
        );
        if (!$found) {
            return null;
        }
        $group = static fn(int $index): string => $condition[$index][0];
        if (
            str_ends_with($group(1), $suffix)
            || !str_contains($group(1), '/include/php/')
            || !str_ends_with($group(1), '.h')
        ) {
            return null;
        }
        $variable = $group(4);
        preg_match_all('/\b(\w+_\d+) = (-?\d+|\w+_\d+);/', substr($body, 0, $condition[0][1]), $pairs, PREG_SET_ORDER);
        $assignments = [];
        foreach ($pairs as $pair) {
            $assignments[$pair[1]] = $pair[2];
        }
        $value = $variable;
        $chain = [];
        while (!preg_match('/\A-?\d+\z/', $value)) {
            if (in_array($value, $chain, true) || !array_key_exists($value, $assignments)) {
                return null;
            }
            $chain[] = $value;
            $value = $assignments[$value];
        }
        [$left, $right] = [(int) $value, (int) $group(6)];
        $holds = match ($group(5)) {
            '!=' => $left !== $right,
            '==' => $left === $right,
            '<' => $left < $right,
            '>' => $left > $right,
            '<=' => $left <= $right,
            '>=' => $left >= $right,
        };
        $taken = (int) ($holds ? $group(7) : $group(8));
        $destinations = array_values(array_unique($arcs));
        $expected = array_values(array_unique([(int) $group(7), (int) $group(8)]));
        sort($destinations);
        sort($expected);
        if ($destinations !== $expected || $arcs[$outcome] === $taken) {
            return null;
        }
        return [
            'kind' => 'inlined_header_constant_parameter',
            'function_signature' => $original['signature'],
            'source_block' => $block,
            'untaken_destination' => $arcs[$outcome],
            'constant_assignment' => "{$variable}={$left}",
            'ssa_alias_chain' => $chain,
            'condition' => "{$variable} {$group(5)} {$right}",
            'condition_file' => $group(1),
            'condition_line' => (int) $group(2),
            'note' => 'Proof explains this instantiated header outcome; raw branch coverage remains incomplete.',
        ];
    }

    /** @return array<string, mixed> */
    public static function analyze(
        array $data,
        string $suffix,
        ?array $original = null,
        ?array $rebuilt = null,
        ?array $tree = null,
    ): array {
        $selected = array_values(array_filter($data['files'], static fn(array $entry): bool => str_ends_with(
            str_replace('\\', '/', $entry['file']),
            $suffix,
        )));
        if (count($selected) !== 1) {
            throw new InvalidArgumentException("expected one {$suffix} report, found " . count($selected));
        }
        $entry = $selected[0];
        $lines = $entry['lines'];
        $functions = $entry['functions'];
        $branches = array_merge(...array_map(static fn(array $line): array => $line['branches'] ?? [], $lines));
        $covered = static fn(array $items, string $field): int => count(array_filter(
            $items,
            static fn(array $item): bool => $item[$field] > 0,
        ));
        $totals = [
            'lines' => ['covered' => $covered($lines, 'count'), 'count' => count($lines)],
            'functions' => ['covered' => $covered($functions, 'execution_count'), 'count' => count($functions)],
            'branches' => ['covered' => $covered($branches, 'count'), 'count' => count($branches)],
        ];
        $gaps = [];
        foreach ($lines as $line) {
            foreach ($line['branches'] ?? [] as $index => $branch) {
                if ($branch['count'] != 0) {
                    continue;
                }
                $proof = self::constantHeaderOutcome(
                    $line['function_name'] ?? null,
                    $line['line_number'],
                    $index,
                    count($line['branches']),
                    $original ?? [],
                    $rebuilt ?? [],
                    $tree ?? [],
                    $suffix,
                );
                $gaps[] = [
                    'function' => $line['function_name'] ?? null,
                    'line' => $line['line_number'],
                    'branch_index' => $index,
                    'raw_count' => 0,
                    'evidence' => $proof,
                    'classification' => $proof['kind'] ?? 'unresolved',
                ];
            }
        }
        $passes = array_filter(
            $totals,
            static fn(array $value): bool => $value['count'] > 0 && $value['covered'] === $value['count'],
        );
        $explained = count(array_filter($gaps, static fn(array $gap): bool => $gap['evidence'] !== null));
        return [
            'schema_version' => 1,
            'compiler' => $data['gcc_version'] ?? null,
            'source_file' => $entry['file'],
            'raw_counts' => $totals,
            'raw_gate_pass' => count($passes) === count($totals),
            'uncovered_outcomes' => $gaps,
            'explained_constant_header_outcomes' => $explained,
            'unresolved_outcomes' => count($gaps) - $explained,
            'adjusted_coverage_percentage' => null,
            'scope' => 'GCC raw report, with optional checksum-matched instantiated-header evidence; no outcomes are excluded.',
        ];
    }

    /** @return list<string> */
    private static function lines(string $text): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $text);
        if (end($lines) === '') {
            array_pop($lines);
        }
        return $lines;
    }

    private static function integer(string $number): int
    {
        if (!preg_match('/\A\s*[+-]?\d+\s*\z/', $number)) {
            throw new InvalidArgumentException('Invalid gcov-dump line number: ' . $number);
        }
        return (int) trim($number);
    }
}
