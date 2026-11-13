<?php

declare(strict_types=1);

use PhpProfiler\Profiler;

if (!profiler_test_uid_allocator() || !profiler_test_uid_threads()) {
    throw new RuntimeException('UID allocator monotonicity, spurious retry, overflow or pthread uniqueness failed');
}
$cached = static fn(): int => 17;
if ($cached() !== 17 || !profiler_test_uid_cache($cached)) {
    throw new RuntimeException('real Zend cache generation/absent-cache/handle contracts failed');
}
function uid_after_failure_work(): int
{
    return 113;
}

$failed = Profiler::start();
profiler_test_exhaust_closure_ids();
$fresh = eval('return static fn(): int => 23;');
if ($fresh() !== 23) {
    throw new RuntimeException('UID exhaustion changed execution');
}
if (uid_after_failure_work() !== 113) {
    throw new RuntimeException('post-failure PHP execution changed');
}
$failed->stop();
if ($failed->getReport()['status'] !== 'incomplete' || profiler_test_uid_clock_failed()) {
    throw new RuntimeException('UID exhaustion was not explicit or incorrectly invalidated clocks');
}
if (isset($failed->getReport()['functions']['uid_after_failure_work'])) {
    throw new RuntimeException('capture continued after closure UID failure');
}
Profiler::resetAll();
$retained = Profiler::start();
if ($cached() !== 17 || $cached() !== 17) {
    throw new RuntimeException('retained cache execution changed');
}
$retained->stop();
if ($retained->getReport()['status'] !== 'complete') {
    throw new RuntimeException('retained issued cache ID became unusable at exhaustion');
}
Profiler::resetAll();
$again = Profiler::start();
$another = eval('return static fn(): int => 29;');
if ($another() !== 29) {
    throw new RuntimeException('second UID exhaustion changed execution');
}
$again->stop();
if ($again->getReport()['status'] !== 'incomplete') {
    throw new RuntimeException('reset improperly restarted the global UID sequence');
}
Profiler::resetAll();
echo "real closure UID cache boundaries, exhaustion and 4x4096 pthread uniqueness passed\n";
