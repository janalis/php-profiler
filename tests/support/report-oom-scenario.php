<?php

declare(strict_types=1);

use PhpProfiler\Profiler;

function run_report_oom_scenario(string $mode, ?Closure $beforeMaterialize = null): void
{
    // Build a bounded profile in Rust whose materialization needs multiple Zend pages.
    $names = [];
    for ($i = 0; $i < 500; $i++) {
        $names[] = 'profile_oom_' . str_repeat('a', 980) . '_' . $i;
    }
    $source = '';
    foreach ($names as $i => $name) {
        $body = isset($names[$i + 1]) ? $names[$i + 1] . '();' : 'usleep(1);';
        $source .= 'function ' . $name . '():void{' . $body . '}';
    }
    eval($source);
    unset($source);
    $session = Profiler::start(null, ['timeline' => true]);
    $names[0]();
    $session->stop();
    $size = strlen($session->getReportJson());
    if ($size < 2097152) {
        throw new RuntimeException('profile too small for controlled materialization OOM: ' . $size);
    }
    if ($beforeMaterialize !== null) {
        $beforeMaterialize();
    }
    $limit = memory_get_usage(true);
    ini_set('memory_limit', (string) $limit);
    $padding = [];
    while (memory_get_usage() < ($limit * 0.6)) {
        $padding[] = str_repeat('p', 256);
    }
    echo "BEFORE_REPORT\n";
    try {
        if ($mode === 'json') {
            $session->getReportJson();
        } else {
            $session->getReport();
        }
        echo "AFTER_REPORT\n";
    } catch (Throwable $error) {
        echo "CAUGHT_FATAL\n";
    }
}
