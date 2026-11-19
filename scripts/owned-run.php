<?php

declare(strict_types=1);

// Runs one command in its own process group with a timeout, then retires the whole
// group, and exits non-zero unless the command succeeded.
require_once __DIR__ . '/lib/Fail.php';
require_once __DIR__ . '/lib/Process.php';
require_once __DIR__ . '/lib/ProcessGroups.php';

use PhpProfiler\Tooling\Process;
use PhpProfiler\Tooling\ProcessGroups;

if ($argc < 3 || !preg_match('/\A\d+(\.\d+)?\z/', $argv[1])) {
    fwrite(STDERR, "usage: owned-run.php <timeout-seconds> <command> [argument]...\n");
    exit(2);
}
$processes = new ProcessGroups();
try {
    $processes->run(array_slice($argv, 2), (float) $argv[1], Process::environment(), check: true);
} finally {
    $processes->close();
}
