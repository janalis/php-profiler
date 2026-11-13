<?php

// Private probes BELOW only inspect lengths, never insert identities/samples.
declare(strict_types=1);

use PhpProfiler\Profiler;

require __DIR__ . '/../support/stopped-sampling-assertions.php';
if (!Profiler::isSamplingSupported())
    return;
$closure = static function (): int {
    if (!profiler_test_sampling_service())
        throw new RuntimeException('real closure tick unavailable');
    return 101;
};
$anonymous = new class {
    public function leaf(): int
    {
        if (!profiler_test_sampling_service())
            throw new RuntimeException('real anonymous-method tick unavailable');
        return 103;
    }
};
$ordinary = Profiler::start();
$sampled = Profiler::start(null, ['sampling' => true]);
if ($closure() !== 101 || $anonymous->leaf() !== 103)
    throw new RuntimeException('semantics');
profiler_sampling_map_lifetime_probe('active-populated');
// Stop another ordinary owner while a sampled owner still needs its identities.
$transient = Profiler::start();
$transient->stop();
profiler_sampling_map_lifetime_probe('active-populated');
$sampled->stop();
// Ordinary capture and stopped reports remain, but no sampled owner can consume
// the mutable generated caches. They must now have zero logical entries.
profiler_sampling_map_lifetime_probe('idle-cleared');
assert_stopped_sampling($sampled->getReport()['sampling']);
$report = $sampled->getReportJson();
$second = Profiler::start(null, ['sampling' => true]);
if ($closure() !== 101 || $anonymous->leaf() !== 103)
    throw new RuntimeException('next episode semantics');
profiler_sampling_map_lifetime_probe('active-populated');
$second->stop();
assert_stopped_sampling($second->getReport()['sampling']);
profiler_sampling_map_lifetime_probe('idle-cleared');
if ($sampled->getReportJson() !== $report)
    throw new RuntimeException('cache cleanup mutated retained report');
$ordinary->stop();
Profiler::resetAll();
Profiler::disable();
echo "sampling identity caches preserve active owners and clear after the last owner\n";
