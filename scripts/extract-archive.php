<?php

declare(strict_types=1);

// Extracts a .tar.gz into a new directory, refusing what Python's tarfile 'data' filter
// refuses: absolute or parent paths, links leaving the destination, and special files.
require_once __DIR__ . '/lib/Fail.php';
require_once __DIR__ . '/lib/Paths.php';
require_once __DIR__ . '/lib/Tar.php';

use PhpProfiler\Tooling\Paths;
use PhpProfiler\Tooling\Tar;

use function PhpProfiler\Tooling\fail;

if ($argc !== 3) {
    fwrite(STDERR, "usage: extract-archive.php <archive.tar.gz> <new-directory>\n");
    exit(2);
}
[, $archive, $destination] = $argv;
$contents = gzdecode((string) file_get_contents($archive));
if ($contents === false) {
    fail('not a gzip archive: ' . $archive);
}
if (file_exists($destination) || !mkdir($destination, 0o777, true)) {
    fail('destination must be a new directory: ' . $destination);
}
$destination = Paths::resolve($destination);
$inside = static fn(string $path): bool => $path === $destination || str_starts_with($path, $destination . '/');
// A later member replaces an earlier file or link of the same name, like tarfile, and
// is never written through a link. Every failed write stops the extraction.
$directory = static function (string $path, string $name): void {
    if (!is_dir($path) && (is_link($path) || !mkdir($path, 0o755, true))) {
        fail('cannot create archive directory for: ' . $name);
    }
};
$replace = static function (string $path, string $name): void {
    if (is_link($path) || is_file($path)) {
        unlink($path) || fail('cannot replace archive member: ' . $name);
    } elseif (file_exists($path)) {
        fail('archive member replaces a directory: ' . $name);
    }
};
foreach (Tar::read($contents) as $entry) {
    $name = $entry['name'];
    if ($name === '' || str_starts_with($name, '/') || in_array('..', explode('/', $name), true)) {
        fail('archive member escapes the destination: ' . $name);
    }
    $target = "{$destination}/{$name}";
    if (!$inside(Paths::resolve(dirname($target)))) {
        fail('archive member escapes the destination: ' . $name);
    }
    switch ($entry['type']) {
        case 'directory':
            $directory($target, $name);
            break;
        case 'file':
            $directory(dirname($target), $name);
            $replace($target, $name);
            // Like the data filter: keep owner execute bits, drop set-id and group or other write.
            if (
                file_put_contents($target, $entry['data']) !== strlen($entry['data'])
                || !chmod($target, ($entry['mode'] & 0o100) !== 0 ? 0o755 : 0o644)
            ) {
                fail('cannot write archive member: ' . $name);
            }
            break;
        case 'symlink':
            $link = str_starts_with($entry['link'], '/') ? $entry['link'] : dirname($target) . '/' . $entry['link'];
            if (str_starts_with($entry['link'], '/') || !$inside(Paths::resolve($link))) {
                fail('archive link leaves the destination: ' . $name);
            }
            $directory(dirname($target), $name);
            $replace($target, $name);
            symlink($entry['link'], $target) || fail('cannot create archive link: ' . $name);
            break;
        default:
            fail('archive member is not a regular file, directory or symlink: ' . $name);
    }
}
