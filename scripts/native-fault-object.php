<?php

declare(strict_types=1);

// Compiles tests/native/bailout-fault.c and prints an immutable content-addressed copy
// of the object. External object contents are not a Cargo input, so linking the
// content-addressed path makes an actual native edit invalidate Cargo's link command.
require_once __DIR__ . '/lib/Json.php';
require_once __DIR__ . '/lib/Paths.php';
require_once __DIR__ . '/lib/Shlex.php';

use PhpProfiler\Tooling\Json;
use PhpProfiler\Tooling\Paths;
use PhpProfiler\Tooling\Shlex;

if ($argc < 3) {
    fwrite(STDERR, "usage: native-fault-object.php <compiler> <target-directory> [include-flag]...\n");
    exit(2);
}
[$compiler, $target] = [$argv[1], Paths::resolve($argv[2])];
if (!is_dir($target)) {
    mkdir($target, 0o777, true);
}
$flags = Shlex::split((string) getenv('CFLAGS'));
$source = 'tests/native/bailout-fault.c';
$intermediate = "{$target}/bailout-fault.o";
$command = [$compiler, ...$flags, '-fPIC', '-c', ...array_slice($argv, 3), $source, '-o', $intermediate];
// A stable compiler output path keeps DWARF and profile flags deterministic. The lock
// also stops concurrent writers from hashing an unfinished object.
$lock = fopen("{$target}/.native-fault-object.lock", 'a') ?: throw new RuntimeException('open lock');
flock($lock, LOCK_EX) || throw new RuntimeException('lock native fault object');
// The compiler's standard output goes to standard error, keeping ours for the path.
$process = proc_open($command, [1 => ['pipe', 'w']], $pipes);
if ($process === false) {
    throw new RuntimeException('start ' . $compiler);
}
fwrite(STDERR, (string) stream_get_contents($pipes[1]));
fclose($pipes[1]);
$status = proc_close($process);
if ($status !== 0) {
    exit($status);
}
$contents = (string) file_get_contents($intermediate);
$digest = hash('sha256', $contents);
$output = "{$target}/bailout-fault-{$digest}.o";
if (!is_file($output) || hash_file('sha256', $output) !== $digest) {
    $temporary = tempnam($target, '.native-fault-') ?: throw new RuntimeException('create temporary object');
    try {
        file_put_contents($temporary, $contents) !== false || throw new RuntimeException('write temporary object');
        rename($temporary, $output) || throw new RuntimeException('publish object');
    } finally {
        if (file_exists($temporary)) {
            unlink($temporary);
        }
    }
}
file_put_contents(
    "{$target}/native-fault-link.json",
    Json::encode(
        [
            'command' => $command,
            'CFLAGS' => $flags,
            'source_sha256' => hash_file('sha256', $source),
            'object_sha256' => $digest,
            'link_object' => $output,
        ],
        indent: 2,
        sortKeys: false,
    )
        . "\n",
);
echo $output, "\n";
