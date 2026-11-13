<?php

declare(strict_types=1);

use PhpProfiler\Profiler;

$session = Profiler::start();
$contents = file_get_contents(__FILE__);
$session->stop();
$report = $session->getReport();
if (!is_string($contents) || !str_contains($contents, 'file_get_contents')) {
    throw new RuntimeException('I/O semantics changed');
}
if ($report['functions']['file_get_contents']['calls'] !== 1 || $report['operations']['io']['calls'] !== 1) {
    throw new RuntimeException('real internal I/O instrumentation missing');
}
if ($report['operations']['io']['inclusive_wall_ns'] <= 0) {
    throw new RuntimeException('I/O duration missing');
}
if (str_contains($session->getReportJson(), '<?php')) {
    throw new RuntimeException('file content leaked');
}
Profiler::resetAll();
Profiler::disable();
echo "native I/O category and payload privacy passed\n";
