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
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        if (is_float($value)) {
            return self::float($value);
        }
        if ($value instanceof stdClass) {
            return self::entries(get_object_vars($value), false, $indent, $sortKeys, $depth);
        }
        if (is_array($value)) {
            return self::entries($value, array_is_list($value), $indent, $sortKeys, $depth);
        }
        throw new InvalidArgumentException('unsupported JSON value: ' . get_debug_type($value));
    }

    /**
     * Python's float repr: the shortest round-trip digits, positional when the decimal
     * exponent is in [-4, 16), otherwise scientific with a signed two-digit exponent.
     */
    private static function float(float $value): string
    {
        if (!is_finite($value)) {
            throw new InvalidArgumentException('JSON cannot encode a non-finite float');
        }
        // serialize_precision -1 yields the shortest round-trip digits, as Python does.
        $text = strtolower(json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        $sign = str_starts_with($text, '-') ? '-' : '';
        [$mantissa, $exponent] = str_contains($text, 'e') ? explode('e', ltrim($text, '-')) : [ltrim($text, '-'), '0'];
        [$whole, $fraction] = str_contains($mantissa, '.') ? explode('.', $mantissa) : [$mantissa, ''];
        $digits = ltrim($whole . $fraction, '0');
        if ($digits === '') {
            return $sign . '0.0';
        }
        $leading = strlen($whole . $fraction) - strlen($digits);
        $point = strlen($whole) + (int) $exponent - $leading - 1;
        $digits = rtrim($digits, '0');
        if ($point >= -4 && $point < 16) {
            if ($point < 0) {
                return $sign . '0.' . str_repeat('0', -$point - 1) . $digits;
            }
            $integer = str_pad(substr($digits, 0, $point + 1), $point + 1, '0');
            $rest = substr($digits, $point + 1);
            return $sign . $integer . '.' . ($rest === '' ? '0' : $rest);
        }
        $rest = substr($digits, 1);
        return (
            $sign
            . $digits[0]
            . ($rest === '' ? '' : '.' . $rest)
            . 'e'
            . ($point < 0 ? '-' : '+')
            . str_pad((string) abs($point), 2, '0', STR_PAD_LEFT)
        );
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
