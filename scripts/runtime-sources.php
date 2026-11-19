<?php

declare(strict_types=1);

// Prints the runtime source registry, one resolved path per line, for shell callers.
require_once __DIR__ . '/lib/Paths.php';

use PhpProfiler\Tooling\Paths;

if ($argc !== 3 || !in_array($argv[2], ['all', 'rust', 'c'], true)) {
    fwrite(STDERR, "usage: runtime-sources.php <root> all|rust|c\n");
    exit(2);
}
$sources = Paths::runtimeSources($argv[1], $argv[2]);
if ($sources === []) {
    fwrite(STDERR, "no runtime sources found\n");
    exit(1);
}
foreach ($sources as $source) {
    if (str_contains($source, "\n")) {
        fwrite(STDERR, "unsupported newline in runtime source path\n");
        exit(1);
    }
    echo $source, "\n";
}
