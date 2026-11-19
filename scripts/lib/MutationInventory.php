<?php

declare(strict_types=1);

namespace PhpProfiler\Tooling;

use InvalidArgumentException;

require_once __DIR__ . '/Paths.php';

/** Native mutation identities: every ordinary source callsite Mull instruments. */
final class MutationInventory
{
    /**
     * @param array<string, true> $expected resolved runtime sources
     * @return array<string, true> "mutator:relative-source:line:column:line:column"
     */
    public static function identities(string $nmOutput, string $root, array $expected): array
    {
        $result = [];
        foreach (preg_split('/\r\n|\r|\n/', $nmOutput) as $line) {
            $fields = preg_split('/\s+/', trim($line), 3);
            if (
                count($fields) !== 3
                || !in_array($fields[1], ['t', 'T'], true)
                || !str_starts_with($fields[2], 'cxx_')
            ) {
                continue;
            }
            if (!str_contains($fields[2], ':')) {
                throw new InvalidArgumentException('Malformed native mutation identity');
            }
            [$mutator, $location] = explode(':', $fields[2], 2);
            $parts = explode(':', $location);
            $coordinates = array_slice($parts, -4);
            $filename = implode(':', array_slice($parts, 0, -4));
            if (
                count($parts) < 5
                || array_filter(
                    $coordinates,
                    static fn(string $value): bool => !preg_match('/\A\d+\z/', $value) || (int) $value <= 0,
                ) !== []
            ) {
                throw new InvalidArgumentException('Malformed native mutation identity');
            }
            $source = Paths::resolve($filename);
            if (!isset($expected[$source])) {
                throw new InvalidArgumentException('Mutation outside exhaustive runtime registry: ' . $filename);
            }
            $result[$mutator . ':' . substr($source, strlen($root) + 1) . ':' . implode(':', $coordinates)] = true;
        }
        return $result;
    }
}
