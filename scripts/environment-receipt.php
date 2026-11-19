<?php

declare(strict_types=1);

// Writes a JSON receipt of named environment variables (null when unset), in order,
// followed by any --value key=value and --sha256 key=path entries in order.
require __DIR__ . '/lib/Json.php';

use PhpProfiler\Tooling\Json;

if ($argc < 2) {
    fwrite(STDERR, "usage: environment-receipt.php <output> [NAME | --value key=value | --sha256 key=path]...\n");
    exit(2);
}
$receipt = [];
for ($index = 2; $index < $argc; $index++) {
    $argument = $argv[$index];
    if ($argument === '--value' || $argument === '--sha256') {
        $entry = $argv[++$index] ?? '';
        if (!str_contains($entry, '=')) {
            fwrite(STDERR, "{$argument} expects key=value\n");
            exit(2);
        }
        [$key, $value] = explode('=', $entry, 2);
        $hash = $argument === '--sha256' ? hash_file('sha256', $value) : $value;
        $receipt[$key] = $hash === false ? throw new RuntimeException('read ' . $value) : $hash;
    } else {
        $value = getenv($argument);
        $receipt[$argument] = $value === false ? null : $value;
    }
}
if (file_put_contents($argv[1], Json::encode($receipt, indent: 2, sortKeys: false) . "\n") === false) {
    fwrite(STDERR, "write {$argv[1]}\n");
    exit(1);
}
