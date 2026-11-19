<?php

declare(strict_types=1);

namespace PhpProfiler\Tooling;

use InvalidArgumentException;

/**
 * A strict TOML reader for the constructs Cargo.toml and Cargo.lock use: comments,
 * tables, arrays of tables, dotted keys, basic and literal strings, integers, booleans,
 * arrays and inline tables. Anything else, such as floats or dates, is refused.
 * Tables decode as associative arrays and arrays as lists.
 */
final class Toml
{
    private string $text;
    private int $position = 0;
    /** @var array<string, true> explicit [table] headers, keyed by their resolved location */
    private array $headers = [];

    private function __construct(string $text)
    {
        $this->text = str_replace("\r\n", "\n", $text);
    }

    /** @return array<string, mixed> */
    public static function parse(string $text): array
    {
        return (new self($text))->document();
    }

    /**
     * Deep equality of decoded documents: tables ignore key order, lists keep theirs. An
     * empty table and an empty array both decode to [] and compare equal; the only caller
     * compares Cargo.lock files, where Cargo never writes an empty table.
     */
    public static function equal(mixed $left, mixed $right): bool
    {
        if (is_array($left) && is_array($right)) {
            if (array_is_list($left) !== array_is_list($right) || count($left) !== count($right)) {
                return false;
            }
            foreach ($left as $key => $value) {
                if (!array_key_exists($key, $right) || !self::equal($value, $right[$key])) {
                    return false;
                }
            }
            return true;
        }
        return $left === $right;
    }

    /** @return array<string, mixed> */
    private function document(): array
    {
        $root = [];
        $current = &$root;
        while (true) {
            $this->skipBlankLines();
            if ($this->position >= strlen($this->text)) {
                return $root;
            }
            if ($this->text[$this->position] === '[') {
                $array = str_starts_with(substr($this->text, $this->position), '[[');
                $this->position += $array ? 2 : 1;
                $path = $this->keyPath();
                $this->expect($array ? ']]' : ']');
                $this->endOfLine();
                unset($current);
                $current = &$this->table($root, $path, $array);
                continue;
            }
            $path = $this->keyPath();
            $this->spaces();
            $this->expect('=');
            $this->spaces();
            $value = $this->value();
            $this->endOfLine();
            $this->assign($current, $path, $value);
        }
    }

    /** @return array<string, mixed> */
    private function &table(array &$root, array $path, bool $array): array
    {
        $node = &$root;
        // Each [[array]] element is its own scope, so its index is part of the location.
        $location = '';
        foreach (array_slice($path, 0, -1) as $key) {
            $node[$key] ??= [];
            $location .= "\0" . $key;
            if (is_array($node[$key]) && array_is_list($node[$key]) && $node[$key] !== []) {
                $location .= "\0#" . (count($node[$key]) - 1);
                $node = &$node[$key][count($node[$key]) - 1];
            } else {
                $node = &$node[$key];
            }
        }
        $last = $path[count($path) - 1];
        if (!$array) {
            // Like tomllib: a table may be defined by one [header] only.
            $location .= "\0" . $last;
            if (isset($this->headers[$location])) {
                throw new InvalidArgumentException("TOML table {$last} is defined twice");
            }
            $this->headers[$location] = true;
        }
        if ($array) {
            $node[$last] ??= [];
            if (!array_is_list($node[$last])) {
                throw new InvalidArgumentException("TOML key {$last} is not an array of tables");
            }
            $node[$last][] = [];
            return $node[$last][count($node[$last]) - 1];
        }
        if (isset($node[$last]) && (!is_array($node[$last]) || array_is_list($node[$last]) && $node[$last] !== [])) {
            throw new InvalidArgumentException("TOML table {$last} redefines a value");
        }
        $node[$last] ??= [];
        return $node[$last];
    }

    private function assign(array &$table, array $path, mixed $value): void
    {
        $node = &$table;
        foreach (array_slice($path, 0, -1) as $key) {
            $node[$key] ??= [];
            if (!is_array($node[$key])) {
                throw new InvalidArgumentException("TOML key {$key} is not a table");
            }
            $node = &$node[$key];
        }
        $last = $path[count($path) - 1];
        if (array_key_exists($last, $node)) {
            throw new InvalidArgumentException("TOML key {$last} is defined twice");
        }
        $node[$last] = $value;
    }

    /** @return list<string> */
    private function keyPath(): array
    {
        $path = [];
        do {
            $this->spaces();
            $character = $this->text[$this->position] ?? '';
            if ($character === '"' || $character === "'") {
                $path[] = $this->string();
            } elseif (preg_match('/\G[A-Za-z0-9_-]+/', $this->text, $match, 0, $this->position)) {
                $path[] = $match[0];
                $this->position += strlen($match[0]);
            } else {
                throw $this->error('expected a key');
            }
            $this->spaces();
        } while ($this->consume('.'));
        return $path;
    }

    private function value(): mixed
    {
        $character = $this->text[$this->position] ?? '';
        if ($character === '"' || $character === "'") {
            return $this->string();
        }
        if ($character === '[') {
            $this->position++;
            $items = [];
            while (true) {
                $this->skipArraySpace();
                if ($this->consume(']')) {
                    return $items;
                }
                $items[] = $this->value();
                $this->skipArraySpace();
                if ($this->consume(']')) {
                    return $items;
                }
                $this->expect(',');
            }
        }
        if ($character === '{') {
            $this->position++;
            $table = [];
            $this->spaces();
            if ($this->consume('}')) {
                return $table;
            }
            while (true) {
                $path = $this->keyPath();
                $this->expect('=');
                $this->spaces();
                $this->assign($table, $path, $this->value());
                $this->spaces();
                if ($this->consume('}')) {
                    return $table;
                }
                $this->expect(',');
            }
        }
        if (preg_match('/\G(true|false)(?![A-Za-z0-9_])/', $this->text, $match, 0, $this->position)) {
            $this->position += strlen($match[0]);
            return $match[1] === 'true';
        }
        if (preg_match('/\G[+-]?(0|[1-9](_?[0-9])*)(?![0-9A-Za-z_.:+-])/', $this->text, $match, 0, $this->position)) {
            $this->position += strlen($match[0]);
            return (int) str_replace('_', '', $match[0]);
        }
        throw $this->error('unsupported TOML value');
    }

    private function string(): string
    {
        $quote = $this->text[$this->position];
        if (substr($this->text, $this->position, 3) === str_repeat($quote, 3)) {
            throw $this->error('multi-line TOML strings are not supported');
        }
        $this->position++;
        $value = '';
        while (true) {
            $character = $this->text[$this->position] ?? throw $this->error('unterminated string');
            $this->position++;
            if ($character === $quote) {
                return $value;
            }
            if ($character === "\n") {
                throw $this->error('newline in a string');
            }
            if ($character === '\\' && $quote === '"') {
                $escape = $this->text[$this->position++] ?? '';
                $value .= match ($escape) {
                    '"' => '"',
                    '\\' => '\\',
                    'b' => "\x08",
                    't' => "\t",
                    'n' => "\n",
                    'f' => "\f",
                    'r' => "\r",
                    'u', 'U' => $this->unicode($escape === 'u' ? 4 : 8),
                    default => throw $this->error('invalid escape'),
                };
                continue;
            }
            $value .= $character;
        }
    }

    private function unicode(int $digits): string
    {
        $hex = substr($this->text, $this->position, $digits);
        if (!preg_match('/\A[0-9A-Fa-f]{' . $digits . '}\z/', $hex)) {
            throw $this->error('invalid unicode escape');
        }
        $this->position += $digits;
        return (string) json_decode('"' . ($digits === 4 ? "\\u{$hex}" : self::surrogates((int) hexdec($hex))) . '"');
    }

    private static function surrogates(int $codepoint): string
    {
        if ($codepoint < 0x10000) {
            return sprintf('\\u%04x', $codepoint);
        }
        $codepoint -= 0x10000;
        return sprintf('\\u%04x\\u%04x', 0xd800 | ($codepoint >> 10), 0xdc00 | ($codepoint & 0x3ff));
    }

    private function spaces(): void
    {
        while (in_array($this->text[$this->position] ?? '', [' ', "\t"], true)) {
            $this->position++;
        }
    }

    private function skipBlankLines(): void
    {
        while ($this->position < strlen($this->text)) {
            $this->spaces();
            $character = $this->text[$this->position] ?? '';
            if ($character === '#') {
                $end = strpos($this->text, "\n", $this->position);
                $this->position = $end === false ? strlen($this->text) : $end;
            } elseif ($character === "\n") {
                $this->position++;
            } else {
                return;
            }
        }
    }

    private function skipArraySpace(): void
    {
        while (true) {
            $character = $this->text[$this->position] ?? '';
            if ($character === ' ' || $character === "\t" || $character === "\n") {
                $this->position++;
            } elseif ($character === '#') {
                $end = strpos($this->text, "\n", $this->position);
                $this->position = $end === false ? strlen($this->text) : $end;
            } else {
                return;
            }
        }
    }

    private function endOfLine(): void
    {
        $this->spaces();
        if (($this->text[$this->position] ?? '') === '#') {
            $end = strpos($this->text, "\n", $this->position);
            $this->position = $end === false ? strlen($this->text) : $end;
        }
        if ($this->position < strlen($this->text) && !$this->consume("\n")) {
            throw $this->error('expected the end of the line');
        }
    }

    private function consume(string $token): bool
    {
        if (substr($this->text, $this->position, strlen($token)) === $token) {
            $this->position += strlen($token);
            return true;
        }
        return false;
    }

    private function expect(string $token): void
    {
        if (!$this->consume($token)) {
            throw $this->error("expected {$token}");
        }
    }

    private function error(string $message): InvalidArgumentException
    {
        $line = substr_count(substr($this->text, 0, $this->position), "\n") + 1;
        return new InvalidArgumentException("TOML line {$line}: {$message}");
    }
}
