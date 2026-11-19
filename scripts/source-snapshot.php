<?php

declare(strict_types=1);

// Freezes one source tree so every Docker matrix cell validates identical inputs,
// and records the SHA-256 of every frozen file.
require_once __DIR__ . '/lib/Json.php';
require_once __DIR__ . '/lib/Paths.php';
require_once __DIR__ . '/lib/ValidationInputs.php';

use PhpProfiler\Tooling\Json;
use PhpProfiler\Tooling\Paths;
use PhpProfiler\Tooling\ValidationInputs;

if ($argc !== 3) {
    fwrite(STDERR, "usage: source-snapshot.php <destination> <hashes.json>\n");
    exit(2);
}
[, $destination, $receipt] = $argv;
$root = (string) getcwd();
foreach (array_keys(ValidationInputs::snapshot($root)) as $name) {
    Paths::copyFile($root . '/' . $name, $destination . '/' . $name);
}
foreach (['crates', 'scripts', 'tests', 'vendor', 'schema', '.github'] as $name) {
    // Checkouts from before the PHP port may still hold ignored Python caches.
    Paths::copyTree($root . '/' . $name, $destination . '/' . $name, [
        'target',
        '__pycache__',
        'mutants.out',
        '.DS_Store',
    ]);
}
$hashes = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($destination, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    $relative = substr($file->getPathname(), strlen($destination) + 1);
    if ($file->isFile() && !in_array('__pycache__', explode('/', $relative), true)) {
        $hashes[$relative] = hash_file('sha256', $file->getPathname());
    }
}
file_put_contents($receipt, Json::encode((object) $hashes, indent: 2))
    ?: throw new RuntimeException('write ' . $receipt);
