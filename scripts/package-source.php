<?php

declare(strict_types=1);

// Creates a deterministic PIE source archive from a clean committed HEAD.
require_once __DIR__ . '/lib/Arguments.php';
require_once __DIR__ . '/lib/Fail.php';
require_once __DIR__ . '/lib/Process.php';
require_once __DIR__ . '/lib/Toml.php';

use PhpProfiler\Tooling\Arguments;
use PhpProfiler\Tooling\Process;
use PhpProfiler\Tooling\Toml;

$usage = 'package-source.php [--output <path>]';
['options' => $options, 'positionals' => $positionals] = Arguments::parse($argv, ['output'], usage: $usage);
if ($positionals !== []) {
    Arguments::fail('unexpected arguments', $usage);
}
$root = dirname(__DIR__);
$git = static fn(string ...$arguments): string => Process::output(['git', '-C', $root, ...$arguments]);
if (trim($git('status', '--porcelain', '--untracked-files=no')) !== '') {
    Arguments::fail('Commit all tracked changes before packaging', $usage);
}
$version = Toml::parse($git('show', 'HEAD:Cargo.toml'))['workspace']['package']['version'] ?? '';
if (!preg_match('/\A0\.\d+\.\d+(?:-alpha\.[1-9]\d*)?\z/', $version)) {
    Arguments::fail('Expected a development or alpha 0.x version', $usage);
}
$timestamp = (int) trim($git('show', '-s', '--format=%ct', 'HEAD'));
// git archive preserves Git modes and symlinks and applies committed export-ignore.
$source = $git('-c', 'tar.umask=022', 'archive', '--format=tar', "--prefix=php-profiler-{$version}/", 'HEAD');
// The bytes Python's GzipFile(filename='', mtime=...) wrote at level 9: no name, xfl 2,
// OS 255, and zlib's default memory level 8 (gzdeflate() would use 9 and differ).
$deflate = deflate_init(ZLIB_ENCODING_RAW, ['level' => 9, 'memory' => 8, 'window' => 15])
?: throw new RuntimeException('initialize deflate');
$compressed =
    "\x1f\x8b\x08\x00"
    . pack('V', $timestamp)
    . "\x02\xff"
    . deflate_add($deflate, $source, ZLIB_FINISH)
    . pack('V', crc32($source))
    . pack('V', strlen($source) & 0xffffffff);
$output = $options['output'] ?? 'target/php-profiler-source.tar.gz';
$output = str_starts_with($output, '/') ? $output : "{$root}/{$output}";
if (!is_dir(dirname($output))) {
    mkdir(dirname($output), 0o777, true);
}
$temporary = tempnam(dirname($output), '.php-profiler-source-')
?: throw new RuntimeException('create temporary archive');
try {
    file_put_contents($temporary, $compressed) !== false || throw new RuntimeException('write archive');
    chmod($temporary, 0o600);
    rename($temporary, $output) || throw new RuntimeException('publish archive');
} finally {
    if (file_exists($temporary)) {
        unlink($temporary);
    }
}
echo $output, "\n";
