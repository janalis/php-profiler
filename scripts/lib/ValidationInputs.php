<?php

declare(strict_types=1);

namespace PhpProfiler\Tooling;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

require_once __DIR__ . '/Paths.php';

/** Fingerprints validation inputs without a Git dependency; must match scripts/validation_inputs.py. */
final class ValidationInputs
{
    private const array FILES = [
        'package.json',
        'package-lock.json',
        'Makefile',
        '.editorconfig',
        '.clang-format',
        '.gitattributes',
        '.gitignore',
        'mago.toml',
        'composer.json',
        'CHANGELOG.md',
        'Cargo.toml',
        'Cargo.lock',
        'rust-toolchain.toml',
        'rust-toolchain',
        '.cargo/config.toml',
        '.dockerignore',
        'requirements-dev.txt',
        'Dockerfile',
        'schema/report-v1.json',
        'vendor/ext-php-rs/src/exception.rs',
        'vendor/ext-php-rs/README.md',
    ];
    private const array DIRECTORIES = [
        'crates',
        'tests',
        'scripts',
        'vendor',
        '.github',
        'schema',
        'packaging',
        'php',
        '.githooks',
    ];
    private const array NAMES = ['Dockerfile', 'pre-commit', 'Makefile.frag'];
    private const array SUFFIXES = [
        'mjs',
        'm4',
        'frag',
        'rs',
        'c',
        'h',
        'php',
        'py',
        'sh',
        'toml',
        'lock',
        'yml',
        'yaml',
        'json',
    ];
    private const array EXCLUDED_PARTS = ['target', '__pycache__', 'mutants.out'];

    /** @return array<string, string> relative path => SHA-256, sorted by path */
    public static function snapshot(string $root): array
    {
        $root = rtrim($root, '/');
        $files = [];
        foreach (self::FILES as $name) {
            if (is_file($root . '/' . $name)) {
                $files[$name] = true;
            }
        }
        foreach (self::DIRECTORIES as $directory) {
            foreach (self::walk($root . '/' . $directory) as $file) {
                $relative = substr($file->getPathname(), strlen($root) + 1);
                if (self::included($relative, $file->getFilename())) {
                    $files[$relative] = true;
                }
            }
        }
        $hashes = [];
        foreach (array_keys($files) as $relative) {
            $hashes[(string) $relative] = hash_file('sha256', $root . '/' . $relative);
        }
        ksort($hashes, SORT_STRING);
        return $hashes;
    }

    /**
     * The snapshot in the order Python's dict kept it: sorted() over pathlib paths,
     * component by component.
     *
     * @param array<string, string> $snapshot
     * @return array<string, string>
     */
    public static function inPathOrder(array $snapshot): array
    {
        $ordered = [];
        foreach (Paths::sort(array_map(strval(...), array_keys($snapshot))) as $name) {
            $ordered[$name] = $snapshot[$name];
        }
        return $ordered;
    }

    /**
     * Regular files and symlinks to files; symlinked directories are not entered.
     *
     * @return iterable<SplFileInfo>
     */
    public static function walk(string $directory): iterable
    {
        if (!is_dir($directory) || is_link($directory)) {
            return [];
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $directory,
            FilesystemIterator::SKIP_DOTS,
        ));
        $files = [];
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                $files[] = $file;
            }
        }
        return $files;
    }

    private static function included(string $relative, string $name): bool
    {
        if (array_intersect(explode('/', $relative), self::EXCLUDED_PARTS) !== []) {
            return false;
        }
        if (in_array($name, self::NAMES, true) || str_ends_with($name, '.Dockerfile')) {
            return true;
        }
        $dot = strrpos($name, '.');
        // Python's Path.suffix ignores a leading dot: ".json" has no suffix.
        return $dot !== false && $dot > 0 && in_array(substr($name, $dot + 1), self::SUFFIXES, true);
    }
}
