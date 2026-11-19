<?php

declare(strict_types=1);

// Freezes one source tree so every Docker matrix cell validates identical inputs,
// and records the SHA-256 of every frozen file.
require_once __DIR__ . '/lib/Json.php';
require_once __DIR__ . '/lib/ValidationInputs.php';

use PhpProfiler\Tooling\Json;
use PhpProfiler\Tooling\ValidationInputs;

if ($argc !== 3) {
    fwrite(STDERR, "usage: source-snapshot.php <destination> <hashes.json>\n");
    exit(2);
}
[, $destination, $receipt] = $argv;
$root = (string) getcwd();
const IGNORED = ['target', '__pycache__', 'mutants.out', '.DS_Store'];

function copyFile(string $source, string $target): void
{
    if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0o777, true) && !is_dir(dirname($target))) {
        throw new RuntimeException('create ' . dirname($target));
    }
    if (!copy($source, $target) || !touch($target, (int) filemtime($source))) {
        throw new RuntimeException('copy ' . $source);
    }
}

/** Copies a tree, following symlinks and skipping ignored names at any depth. */
function copyTree(string $source, string $target): void
{
    if (!is_dir($target) && !mkdir($target, 0o777, true) && !is_dir($target)) {
        throw new RuntimeException('create ' . $target);
    }
    foreach (scandir($source) ?: throw new RuntimeException('read ' . $source) as $name) {
        if ($name === '.' || $name === '..' || in_array($name, IGNORED, true)) {
            continue;
        }
        $path = $source . '/' . $name;
        is_dir($path) ? copyTree($path, $target . '/' . $name) : copyFile($path, $target . '/' . $name);
    }
}

foreach (array_keys(ValidationInputs::snapshot($root)) as $name) {
    copyFile($root . '/' . $name, $destination . '/' . $name);
}
foreach (['crates', 'scripts', 'tests', 'vendor', 'schema', '.github'] as $name) {
    copyTree($root . '/' . $name, $destination . '/' . $name);
}
$hashes = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($destination, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    $relative = substr($file->getPathname(), strlen($destination) + 1);
    if ($file->isFile() && !in_array('__pycache__', explode('/', $relative), true)) {
        $hashes[$relative] = hash_file('sha256', $file->getPathname());
    }
}
file_put_contents($receipt, Json::encode($hashes, indent: 2)) ?: throw new RuntimeException('write ' . $receipt);
