<?php

declare(strict_types=1);

use PhpProfiler\Profiler;

function sampling_jit_cpu(int $iterations, int $token): int
{
    $sum = 0;
    for ($index = 0; $index < $iterations; ++$index)
        $sum += $index & 7;
    return $sum + $token;
}

/** Ordinary API workload: no private test transports or invented timer ticks. */
function sampling_jit_capture(int $token, bool $requireJit): array
{
    if (!Profiler::isSamplingSupported())
        throw new RuntimeException('native wall sampler unavailable');
    if (Profiler::isEnabled())
        throw new RuntimeException('request inherited enabled profiling');
    $before = opcache_get_status(false);
    if (empty($before['opcache_enabled']) || !opcache_is_script_cached(__FILE__)) {
        throw new RuntimeException('shared CPU workload was not actually cached');
    }
    if ($requireJit && (empty($before['jit']['on']) || $before['jit']['kind'] !== 5)) {
        throw new RuntimeException('actual tracing JIT required');
    }
    $session = Profiler::start(null, ['sampling' => true]);
    $calls = 0;
    $checksum = 0;
    $deadline = hrtime(true) + 200_000_000;
    do {
        $checksum += sampling_jit_cpu(1_000_000, $token);
        ++$calls;
    } while (hrtime(true) < $deadline);
    // Observe code consumption before validation loops can compile unrelated code.
    $after = opcache_get_status(false);
    $session->stop();
    $report = $session->getReport();
    if (
        $checksum !== ($calls * (3_500_000 + $token))
        || $report['schema_version'] !== 2
        || $report['status'] !== 'complete'
        || $report['functions']['sampling_jit_cpu']['calls'] !== $calls
        || $report['sampling']['captured_samples'] < 1
    ) {
        throw new RuntimeException('ordinary sampled CPU application semantics or measurements changed');
    }
    if (json_decode($session->getReportJson(), true, 512, JSON_THROW_ON_ERROR) !== $report) {
        throw new RuntimeException('ordinary sampled accessors disagree');
    }
    $receipt = [
        'pid' => getmypid(),
        'token' => $token,
        'session_id' => $session->getId(),
        'calls' => $calls,
        'checksum' => $checksum,
        'samples' => $report['sampling']['captured_samples'],
        'sampling_status' => $report['sampling']['status'],
        'sampling_report' => $report['sampling'],
        'runtime' => $report['runtime_metadata'],
        'cached' => opcache_is_script_cached(__FILE__),
        'jit_before' => $before['jit'] ?? null,
        'jit_after' => $after['jit'] ?? null,
    ];
    $session->reset();
    Profiler::disable();
    return $receipt;
}
