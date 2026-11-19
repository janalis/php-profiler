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
            is_dir($target) || mkdir($target, 0o755, true);
            break;
        case 'file':
            is_dir(dirname($target)) || mkdir(dirname($target), 0o755, true);
            file_put_contents($target, $entry['data']);
            // Like the data filter: keep owner execute bits, drop set-id and group or other write.
            chmod($target, ($entry['mode'] & 0o100) !== 0 ? 0o755 : 0o644);
            break;
        case 'symlink':
            $link = str_starts_with($entry['link'], '/') ? $entry['link'] : dirname($target) . '/' . $entry['link'];
            if (str_starts_with($entry['link'], '/') || !$inside(Paths::resolve($link))) {
                fail('archive link leaves the destination: ' . $name);
            }
            is_dir(dirname($target)) || mkdir(dirname($target), 0o755, true);
            symlink($entry['link'], $target);
            break;
        default:
            fail('archive member is not a regular file, directory or symlink: ' . $name);
    }
}
