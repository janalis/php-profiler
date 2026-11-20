<?php

declare(strict_types=1);

namespace PhpProfiler\Tooling;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/** Path helpers that match Python's pathlib, so ported gates select the same files. */
final class Paths
{
    /** Like Path.resolve(): absolute, symlinks resolved where they exist, the rest normalized. */
    public static function resolve(string $path): string
    {
        if (!str_starts_with($path, '/')) {
            $path = getcwd() . '/' . $path;
        }
        $current = '/';
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                $current = dirname($current);
                continue;
            }
            $candidate = ($current === '/' ? '' : $current) . '/' . $part;
            $real = realpath($candidate);
            $current = $real === false ? $candidate : $real;
        }
        return $current;
    }

    /** Like Path.suffix: the last dot-separated part of the name, ignoring a leading dot. */
    public static function suffix(string $path): string
    {
        $name = basename($path);
        $dot = strrpos($name, '.');
        return $dot === false || $dot === 0 || $dot === (strlen($name) - 1) ? '' : substr($name, $dot);
    }

    /**
     * Orders paths like sorted() over pathlib paths: parts compared as byte strings, one
     * by one, and a path that is a prefix of another sorts first. PHP's array <=> cannot be
     * used: it compares element counts first and numeric strings as numbers.
     */
    public static function compare(string $left, string $right): int
    {
        $parts = static fn(string $path): array => (
            str_starts_with($path, '/') ? ['/', ...array_slice(explode('/', $path), 1)] : explode('/', $path)
        );
        $leftParts = $parts($left);
        $rightParts = $parts($right);
        foreach ($leftParts as $index => $part) {
            if (!isset($rightParts[$index])) {
                return 1;
            }
            $order = strcmp($part, $rightParts[$index]);
            if ($order !== 0) {
                return $order <=> 0;
            }
        }
        return count($leftParts) <=> count($rightParts);
    }

    /** @param list<string> $paths */
    public static function sort(array $paths): array
    {
        usort($paths, self::compare(...));
        return $paths;
    }

    /**
     * Files below a directory, like Path.rglob('*') filtered on is_file(): symlinked
     * directories are not entered, symlinks to files are included.
     *
     * @return list<string>
     */
    public static function files(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $directory,
            FilesystemIterator::SKIP_DOTS,
        ));
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                $files[] = $file->getPathname();
            }
        }
        return $files;
    }

    /**
     * The exhaustive runtime source registry shared by coverage and mutation gates.
     *
     * @return list<string> resolved paths, sorted
     */
    public static function runtimeSources(string $root, string $language = 'all'): array
    {
        $suffixes = match ($language) {
            'all' => ['.rs', '.c'],
            'rust' => ['.rs'],
            default => ['.c'],
        };
        $sources = [];
        foreach (scandir($crates = self::resolve($root) . '/crates') ?: [] as $crate) {
            if ($crate === '.' || $crate === '..' || !is_dir("{$crates}/{$crate}")) {
                continue;
            }
            foreach (self::files("{$crates}/{$crate}/src") as $path) {
                if (in_array(self::suffix($path), $suffixes, true)) {
                    $sources[self::resolve($path)] = true;
                }
            }
        }
        return self::sort(array_map(strval(...), array_keys($sources)));
    }

    /**
     * Like shutil.copytree(ignore=ignore_patterns(...), dirs_exist_ok=True): follows
     * symlinks, skips the ignored names at any depth and keeps modification times.
     *
     * @param list<string> $ignored
     */
    public static function copyTree(string $source, string $target, array $ignored = []): void
    {
        // @: another process may create it first, which Strict.php would otherwise make fatal.
        if (!is_dir($target) && !@mkdir($target, 0o777, true) && !is_dir($target)) {
            throw new \RuntimeException('create ' . $target);
        }
        foreach (scandir($source) ?: throw new \RuntimeException('read ' . $source) as $name) {
            if ($name === '.' || $name === '..' || in_array($name, $ignored, true)) {
                continue;
            }
            $path = "{$source}/{$name}";
            if (is_dir($path)) {
                self::copyTree($path, "{$target}/{$name}", $ignored);
            } else {
                self::copyFile($path, "{$target}/{$name}");
            }
        }
    }

    /**
     * Removes a directory tree without following symlinks out of it. Anything that is not a
     * directory, such as a socket or a FIFO, is unlinked: is_file() is false for both.
     */
    public static function removeTree(string $path): void
    {
        if (is_link($path) || file_exists($path) && !is_dir($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                self::removeTree("{$path}/{$name}");
            }
        }
        rmdir($path);
    }

    /** Like shutil.copy2(): contents, permissions and modification time. */
    public static function copyFile(string $source, string $target): void
    {
        if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0o777, true) && !is_dir(dirname($target))) {
            throw new \RuntimeException('create ' . dirname($target));
        }
        if (
            !copy($source, $target)
            || !chmod($target, fileperms($source) & 0o7777)
            || !touch($target, (int) filemtime($source))
        ) {
            throw new \RuntimeException('copy ' . $source);
        }
    }

    /** Like shutil.which(): the first executable file of that name on PATH. */
    public static function which(string $program, ?string $path = null): ?string
    {
        foreach (explode(':', $path ?? (string) getenv('PATH')) as $directory) {
            $candidate = ($directory === '' ? '.' : $directory) . '/' . $program;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }
}
