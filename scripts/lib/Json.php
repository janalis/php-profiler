<?php

declare(strict_types=1);

namespace PhpProfiler\Tooling;

use InvalidArgumentException;
use stdClass;

/**
 * Encodes receipts byte-for-byte like Python's json.dumps(sort_keys=True), with
 * an optional indent: ", " and ": " separators inline, "," and ": " when indented.
 * Lists are PHP lists, including the empty array; objects are associative arrays or stdClass.
 */
final class Json
{
    public static function encode(mixed $value, ?int $indent = null, bool $sortKeys = true): string
    {
        return self::value($value, $indent, $sortKeys, 0);
    }

    private static function value(mixed $value, ?int $indent, bool $sortKeys, int $depth): string
    {
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }
        if ($value instanceof stdClass) {
            return self::entries(get_object_vars($value), false, $indent, $sortKeys, $depth);
        }
        if (is_array($value)) {
            return self::entries($value, array_is_list($value), $indent, $sortKeys, $depth);
        }
        throw new InvalidArgumentException('unsupported JSON value: ' . get_debug_type($value));
    }

    /** @param array<array-key, mixed> $entries */
    private static function entries(array $entries, bool $list, ?int $indent, bool $sortKeys, int $depth): string
    {
        [$open, $close] = $list ? ['[', ']'] : ['{', '}'];
        if ($entries === []) {
            return $open . $close;
        }
        if (!$list && $sortKeys) {
            ksort($entries, SORT_STRING);
        }
        $items = [];
        foreach ($entries as $key => $item) {
            $encoded = self::value($item, $indent, $sortKeys, $depth + 1);
            $items[] = $list ? $encoded : self::value((string) $key, $indent, $sortKeys, $depth) . ': ' . $encoded;
        }
        if ($indent === null) {
            return $open . implode(', ', $items) . $close;
        }
        $inner = "\n" . str_repeat(' ', $indent * ($depth + 1));
        return $open . $inner . implode(',' . $inner, $items) . "\n" . str_repeat(' ', $indent * $depth) . $close;
    }
}
