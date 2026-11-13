<?php

declare(strict_types=1);

use PhpProfiler\Profiler;

function unrecorded_recursion(int $depth): int
{
    return $depth === 0 ? 0 : 1 + unrecorded_recursion($depth - 1);
}

Profiler::disable();
if (Profiler::isEnabled()) {
    throw new RuntimeException('disable state incorrect');
}
Profiler::enable();
if (!Profiler::isEnabled()) {
    throw new RuntimeException('enable state incorrect');
}
// Enabled without sessions must not collect frames or exhaust capture capacity.
if (unrecorded_recursion(8200) !== 8200) {
    throw new RuntimeException('unrecorded recursion changed');
}
$session = Profiler::start();
strlen('capture');
$session->stop();
$report = $session->getReport();
if ($report['status'] !== 'complete' || isset($report['functions']['unrecorded_recursion'])) {
    throw new RuntimeException('work outside sessions contaminated later capture');
}
Profiler::resetAll();
Profiler::disable();
if (Profiler::isEnabled()) {
    throw new RuntimeException('final disable state incorrect');
}
echo "disabled collection and control state passed\n";
