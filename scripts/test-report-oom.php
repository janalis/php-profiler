<?php

declare(strict_types=1);

// Ensures Zend OOM remains fatal across native report conversion boundaries.
require_once __DIR__ . '/lib/Fail.php';
require_once __DIR__ . '/lib/Process.php';

use PhpProfiler\Tooling\Process;

use function PhpProfiler\Tooling\fail;
use function PhpProfiler\Tooling\requiredEnvironment;

$extension = requiredEnvironment('PROFILER_EXTENSION');
$php = (string) getenv('PHP') ?: 'php';
foreach (['json', 'array'] as $mode) {
    $result = Process::run(
        [$php, '-n', '-d', 'extension=' . $extension, '-d', 'memory_limit=128M', 'tests/native/report-oom.php', $mode],
        Process::childEnvironment(),
        timeout: 30,
    );
    $output = $result['stdout'] . $result['stderr'];
    if (
        $result['status'] !== 255
        || !str_contains($output, 'BEFORE_REPORT')
        || !str_contains($output, 'Allowed memory size')
        || str_contains($output, 'AFTER_REPORT')
        || str_contains($output, 'CAUGHT_FATAL')
    ) {
        fail("{$mode}: report OOM changed fatal behavior: exit=" . json_encode($result['status']) . "\n{$output}");
    }
    echo "{$mode}: report conversion OOM remains fatal (255), no PHP catch/continuation\n";
}
