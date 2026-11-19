<?php

declare(strict_types=1);

namespace PhpProfiler\Tooling;

use InvalidArgumentException;

/** Python's shlex.split() in POSIX mode, without comments. */
final class Shlex
{
    /** @return list<string> */
    public static function split(string $text): array
    {
        $words = [];
        $word = null;
        $quote = null;
        $length = strlen($text);
        for ($index = 0; $index < $length; $index++) {
            $character = $text[$index];
            if ($quote === "'") {
                if ($character === "'") {
                    $quote = null;
                } else {
                    $word .= $character;
                }
            } elseif ($quote === '"') {
                if ($character === '"') {
                    $quote = null;
                } elseif (
                    $character === '\\'
                    && ($index + 1) < $length
                    && in_array($text[$index + 1], ['"', '\\'], true)
                ) {
                    $word .= $text[++$index];
                } elseif ($character === '\\' && ($index + 1) >= $length) {
                    throw new InvalidArgumentException('No escaped character');
                } else {
                    $word .= $character;
                }
            } elseif ($character === "'" || $character === '"') {
                $quote = $character;
                $word ??= '';
            } elseif ($character === '\\') {
                if (($index + 1) >= $length) {
                    throw new InvalidArgumentException('No escaped character');
                }
                $word = ($word ?? '') . $text[++$index];
            } elseif (in_array($character, [' ', "\t", "\r", "\n"], true)) {
                if ($word !== null) {
                    $words[] = $word;
                    $word = null;
                }
            } else {
                $word = ($word ?? '') . $character;
            }
        }
        if ($quote !== null) {
            throw new InvalidArgumentException('No closing quotation');
        }
        if ($word !== null) {
            $words[] = $word;
        }
        return $words;
    }
}
