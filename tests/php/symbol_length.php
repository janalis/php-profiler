<?php

declare(strict_types=1);

use PhpProfiler\Profiler;

foreach ([1022, 1023, 1024, 1025] as $length) {
    $name = str_repeat('g', $length);
    eval('function ' . $name . '(): int { return 7; }');
    $session = Profiler::start();
    $value = $name();
    $session->stop();
    $report = $session->getReport();
    $accepted = $length <= 1024;
    if (
        $value !== 7
        || $report['status'] !== ($accepted ? 'complete' : 'truncated')
        || count($report['functions']) !== ($accepted ? 1 : 0)
        || $report['dropped_calls'] !== ($accepted ? 0 : 1)
    ) {
        throw new RuntimeException('global symbol boundary differs from rendered byte capacity: ' . $length);
    }
    if ($accepted && ($report['functions'][$name]['calls'] !== 1 || $report['edges'][0]['callee'] !== $name)) {
        throw new RuntimeException('accepted global symbol changed identity');
    }
    $session->reset();
}
foreach ([1023, 1024, 1025] as $length) {
    $class = 'S' . $length;
    $name = str_repeat('m', $length - strlen($class) - 2);
    eval('class ' . $class . ' { public static function ' . $name . '(): int { return 9; } }');
    $symbol = $class . '::' . $name;
    $session = Profiler::start();
    $value = $class::$name();
    $session->stop();
    $report = $session->getReport();
    $accepted = $length <= 1024;
    if (
        strlen($symbol) !== $length
        || $value !== 9
        || $report['status'] !== ($accepted ? 'complete' : 'truncated')
        || count($report['functions']) !== ($accepted ? 1 : 0)
        || $report['dropped_calls'] !== ($accepted ? 0 : 1)
    ) {
        throw new RuntimeException('method separator accounting changed at symbol boundary');
    }
    if ($accepted && $report['functions'][$symbol]['calls'] !== 1) {
        throw new RuntimeException('accepted scoped symbol changed identity');
    }
    $session->reset();
}
// Limits apply to rendered UTF-8 bytes, including reversible byte encoding.
foreach ([['é', 511, true], ['é', 512, true], ['é', 513, false], [chr(128), 508, true], [chr(128), 509, false]] as [
    $unit,
    $count,
    $accepted,
]) {
    $name = str_repeat($unit, $count);
    eval('function ' . $name . '(): int { return 11; }');
    $symbol = $unit === chr(128) ? '{bytes:' . bin2hex($name) . '}' : $name;
    $session = Profiler::start();
    $value = $name();
    $session->stop();
    $report = $session->getReport();
    if (
        $value !== 11
        || $report['status'] !== ($accepted ? 'complete' : 'truncated')
        || count($report['functions']) !== ($accepted ? 1 : 0)
        || $report['dropped_calls'] !== ($accepted ? 0 : 1)
    ) {
        throw new RuntimeException('rendered UTF-8 or encoded-byte symbol boundary changed');
    }
    if ($accepted && $report['functions'][$symbol]['calls'] !== 1) {
        throw new RuntimeException('accepted byte-counted symbol changed identity');
    }
    $session->reset();
}
Profiler::disable();
echo "global and scoped rendered symbol boundaries passed\n";
