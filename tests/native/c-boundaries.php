<?php

declare(strict_types=1);

use PhpProfiler\Profiler;

$session = Profiler::start();
// A real evaluated op array has no function name and must be recorded as {main}.
eval('usleep(1);');
if (profiler_test_empty_string() !== '') {
    throw new RuntimeException('empty native string boundary');
}
if (profiler_test_big_integer()['value'] !== '18446744073709551615') {
    throw new RuntimeException('large integer decoding rounded the value');
}
if (!profiler_test_ini_boundaries()) {
    throw new RuntimeException('unavailable INI boundary');
}
$session->stop();
$report = $session->getReport();
if (($report['functions']['{main}']['calls'] ?? null) !== 1 || $report['status'] !== 'complete') {
    throw new RuntimeException('unnamed C frame boundary');
}
echo "private C boundary contracts passed\n";
