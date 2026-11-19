<?php

declare(strict_types=1);

namespace PhpProfiler\Tooling;

/**
 * Python's random.Random(seed).shuffle(), bit for bit: MT19937 seeded through
 * init_by_array with the seed's 32-bit words, and shuffle through getrandbits.
 * Benchmarks keep the exact mode order their Python versions used.
 */
final class PythonRandom
{
    /** @var list<int> */
    private array $state = [];
    private int $index = 624;

    public function __construct(int $seed)
    {
        $value = abs($seed);
        $key = [];
        do {
            $key[] = $value & 0xffffffff;
            $value >>= 32;
        } while ($value > 0);
        $this->initByArray($key);
    }

    /**
     * @template T
     * @param list<T> $items
     * @return list<T>
     */
    public function shuffle(array $items): array
    {
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = $this->below($i + 1);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }
        return $items;
    }

    private function below(int $limit): int
    {
        $bits = strlen(decbin($limit));
        do {
            $value = $this->next() >> (32 - $bits);
        } while ($value >= $limit);
        return $value;
    }

    /** @param list<int> $key */
    private function initByArray(array $key): void
    {
        $this->state[0] = 19650218;
        for ($i = 1; $i < 624; $i++) {
            $previous = $this->state[$i - 1];
            $this->state[$i] = (self::multiply(1812433253, $previous ^ ($previous >> 30)) + $i) & 0xffffffff;
        }
        $i = 1;
        $j = 0;
        for ($k = max(624, count($key)); $k > 0; $k--) {
            $previous = $this->state[$i - 1];
            $this->state[$i] =
                (($this->state[$i] ^ self::multiply($previous ^ ($previous >> 30), 1664525)) + $key[$j] + $j)
                & 0xffffffff;
            $i++;
            $j++;
            if ($i >= 624) {
                $this->state[0] = $this->state[623];
                $i = 1;
            }
            if ($j >= count($key)) {
                $j = 0;
            }
        }
        for ($k = 623; $k > 0; $k--) {
            $previous = $this->state[$i - 1];
            $this->state[$i] =
                (($this->state[$i] ^ self::multiply($previous ^ ($previous >> 30), 1566083941)) - $i) & 0xffffffff;
            $i++;
            if ($i >= 624) {
                $this->state[0] = $this->state[623];
                $i = 1;
            }
        }
        $this->state[0] = 0x80000000;
        $this->index = 624;
    }

    private function next(): int
    {
        if ($this->index >= 624) {
            for ($k = 0; $k < 624; $k++) {
                $y = ($this->state[$k] & 0x80000000) | ($this->state[($k + 1) % 624] & 0x7fffffff);
                $this->state[$k] = $this->state[($k + 397) % 624] ^ ($y >> 1) ^ (($y & 1) !== 0 ? 0x9908b0df : 0);
            }
            $this->index = 0;
        }
        $y = $this->state[$this->index++];
        $y ^= $y >> 11;
        $y ^= ($y << 7) & 0x9d2c5680;
        $y ^= ($y << 15) & 0xefc60000;
        $y ^= $y >> 18;
        return $y & 0xffffffff;
    }

    /** 32-bit multiplication without overflowing PHP's signed 64-bit integers. */
    private static function multiply(int $left, int $right): int
    {
        $low = ($left & 0xffff) * $right;
        $high = (($left >> 16) * $right) & 0xffff;
        return ($low + ($high << 16)) & 0xffffffff;
    }
}
