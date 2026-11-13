<?php

declare(strict_types=1);

use PhpProfiler\Profiler;
use PhpProfiler\StateException;

$sessions = [];
$refused = false;
try {
    for ($index = 0; $index < 16; ++$index) {
        try {
            $sessions[] = Profiler::start(null, ['max_events' => 8192]);
        } catch (StateException $error) {
            if (!str_contains($error->getMessage(), 'memory budget')) {
                throw $error;
            }
            $refused = true;
            break;
        }
    }
    if (!$refused || count($sessions) < 2 || !Profiler::isEnabled()) {
        throw new RuntimeException('large-session admission did not enforce the shared quota safely');
    }
    $expectedId = count($sessions) + 1;
    $first = $sessions[0];
    unset($sessions[0]);
    $first->stop();
    if ($first->getReport()['status'] !== 'complete') {
        throw new RuntimeException('quota refusal invalidated an existing independent session');
    }
    $first->reset();
    $retry = Profiler::start(null, ['max_events' => 8192]);
    if ($retry->getId() !== $expectedId) {
        throw new RuntimeException('refused admission published or consumed a session identifier');
    }
    $sessions[] = $retry;
    usleep(1);
    foreach ($sessions as $session) {
        $session->stop();
        $report = $session->getReport();
        if ($report['status'] !== 'complete' || ($report['functions']['usleep']['calls'] ?? 0) !== 1) {
            throw new RuntimeException('large-session quota retry damaged retained captures');
        }
        $session->reset();
    }
} finally {
    Profiler::resetAll();
    Profiler::disable();
}
echo "large-session quota refusal preserves independent sessions and recovers after release\n";
