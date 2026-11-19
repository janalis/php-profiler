<?php

declare(strict_types=1);

namespace PhpProfiler\Tooling;

use InvalidArgumentException;

/** Reads ustar, pax and GNU long-name tar archives, verifying each header checksum. */
final class Tar
{
    /**
     * @return list<array{name: string, type: string, data: string, link: string, mode: int}>
     *         type is file, directory, symlink, hardlink or other
     */
    public static function read(string $archive): array
    {
        $entries = [];
        $offset = 0;
        $pax = [];
        $global = [];
        $longName = null;
        $longLink = null;
        $length = strlen($archive);
        while (($offset + 512) <= $length) {
            $header = substr($archive, $offset, 512);
            if ($header === str_repeat("\0", 512)) {
                break;
            }
            self::verifyChecksum($header);
            $size = self::number(substr($header, 124, 12));
            $type = $header[156];
            $data = substr($archive, $offset + 512, $size);
            if (strlen($data) !== $size) {
                throw new InvalidArgumentException('truncated tar entry');
            }
            $offset += 512 + (int) (ceil($size / 512) * 512);
            switch ($type) {
                case 'x':
                    $pax = self::pax($data);
                    continue 2;
                case 'g':
                    $global = [...$global, ...self::pax($data)];
                    continue 2;
                case 'L':
                    $longName = rtrim($data, "\0");
                    continue 2;
                case 'K':
                    $longLink = rtrim($data, "\0");
                    continue 2;
            }
            $name = self::string(substr($header, 0, 100));
            if (substr($header, 257, 5) === 'ustar') {
                $prefix = self::string(substr($header, 345, 155));
                if ($prefix !== '') {
                    $name = $prefix . '/' . $name;
                }
            }
            $attributes = [...$global, ...$pax];
            $name = $attributes['path'] ?? $longName ?? $name;
            $link = $attributes['linkpath'] ?? $longLink ?? self::string(substr($header, 157, 100));
            $entries[] = [
                'name' => rtrim($name, '/'),
                'type' => match ($type) {
                    '0', "\0", '7' => 'file',
                    '5' => 'directory',
                    '2' => 'symlink',
                    '1' => 'hardlink',
                    default => 'other',
                },
                'data' => in_array($type, ['0', "\0", '7'], true) ? $data : '',
                'link' => $link,
                'mode' => self::number(substr($header, 100, 8)),
            ];
            $pax = [];
            $longName = $longLink = null;
        }
        return $entries;
    }

    /**
     * Writes a ustar archive; used to build test fixtures.
     *
     * @param list<array{name: string, data?: string, type?: string, link?: string, mode?: int}> $entries
     */
    public static function write(array $entries): string
    {
        $archive = '';
        foreach ($entries as $entry) {
            $type = $entry['type'] ?? 'file';
            $data = $type === 'file' ? $entry['data'] ?? '' : '';
            if (strlen($entry['name']) > 100) {
                throw new InvalidArgumentException('ustar names are limited to 100 bytes');
            }
            $header =
                str_pad($entry['name'], 100, "\0") . sprintf('%07o', $entry['mode'] ?? 0o644) . "\0" . sprintf(
                    '%07o',
                    0,
                ) . "\0" . sprintf('%07o', 0) . "\0" . sprintf('%011o', strlen($data)) . "\0" . sprintf(
                    '%011o',
                    0,
                ) . "\0" . '        ' . match ($type) {
                    'file' => '0',
                    'directory' => '5',
                    'symlink' => '2',
                    'hardlink' => '1',
                    default => '3',
                }
                . str_pad($entry['link'] ?? '', 100, "\0")
                . "ustar\00"
                . '00'
                . str_repeat("\0", 247);
            $checksum = array_sum(unpack('C*', $header));
            $header = substr_replace($header, sprintf('%06o', $checksum) . "\0 ", 148, 8);
            $archive .= $header . $data . str_repeat("\0", (512 - (strlen($data) % 512)) % 512);
        }
        return $archive . str_repeat("\0", 1024);
    }

    private static function verifyChecksum(string $header): void
    {
        $stored = self::number(substr($header, 148, 8));
        $unsigned = array_sum(unpack('C*', substr_replace($header, '        ', 148, 8)));
        $signed = array_sum(unpack('c*', substr_replace($header, '        ', 148, 8)));
        if ($stored !== $unsigned && $stored !== $signed) {
            throw new InvalidArgumentException('tar header checksum mismatch');
        }
    }

    private static function number(string $field): int
    {
        if ($field !== '' && (ord($field[0]) & 0x80) !== 0) {
            // GNU base-256: big-endian two's complement, the first byte's top bit as the flag.
            $value = ord($field[0]) & 0x7f;
            for ($index = 1; $index < strlen($field); $index++) {
                $value = ($value << 8) | ord($field[$index]);
            }
            return $value;
        }
        $digits = trim($field, " \0");
        if ($digits === '') {
            return 0;
        }
        if (!preg_match('/\A[0-7]+\z/', $digits)) {
            throw new InvalidArgumentException('invalid tar number');
        }
        return (int) octdec($digits);
    }

    private static function string(string $field): string
    {
        $end = strpos($field, "\0");
        return $end === false ? $field : substr($field, 0, $end);
    }

    /** @return array<string, string> */
    private static function pax(string $data): array
    {
        $records = [];
        while ($data !== '') {
            if (!preg_match('/\A(\d+) /', $data, $match)) {
                throw new InvalidArgumentException('invalid pax record');
            }
            $length = (int) $match[1];
            $record = substr($data, strlen($match[0]), $length - strlen($match[0]) - 1);
            [$key, $value] = array_pad(explode('=', $record, 2), 2, '');
            $records[$key] = $value;
            $data = substr($data, $length);
        }
        return $records;
    }
}
