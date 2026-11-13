<?php

declare(strict_types=1);

use PhpProfiler\Profiler;

interface JitMegaReceiver
{
    public function visit(string $key): int;
}

final class JitMegaA implements JitMegaReceiver
{
    private array $values = [];

    public function visit(string $key): int
    {
        $this->values[$key] = ($this->values[$key] ?? 0) + 1;
        return count($this->values);
    }
}

final class JitMegaB implements JitMegaReceiver
{
    private array $values = [];

    public function visit(string $key): int
    {
        $this->values[$key] = ($this->values[$key] ?? 0) + 2;
        return count($this->values);
    }
}

final class JitMegaC implements JitMegaReceiver
{
    private array $values = [];

    public function visit(string $key): int
    {
        $this->values[$key] = ($this->values[$key] ?? 0) + 3;
        return count($this->values);
    }
}

final class JitMegaD implements JitMegaReceiver
{
    private array $values = [];

    public function visit(string $key): int
    {
        $this->values[$key] = ($this->values[$key] ?? 0) + 4;
        return count($this->values);
    }
}

final class JitMegaE implements JitMegaReceiver
{
    private array $values = [];

    public function visit(string $key): int
    {
        $this->values[$key] = ($this->values[$key] ?? 0) + 5;
        return count($this->values);
    }
}

$mode = $argv[1] ?? 'absent';
$iterations = (int) ($argv[2] ?? 100000);
if (!in_array($mode, ['absent', 'disabled', 'enabled', 'other'], true) || $iterations < 40 || ($iterations % 5) !== 0) {
    throw new RuntimeException('invalid megamorphic fixture arguments');
}
$before = opcache_get_status(false);
if (
    empty($before['jit']['on'])
    || $before['jit']['kind'] !== 5
    || ini_get('opcache.jit_max_polymorphic_calls') !== '2'
    || !opcache_is_script_cached(__FILE__)
) {
    throw new RuntimeException('cached source and actual tracing JIT required');
}
$objects = [new JitMegaA(), new JitMegaB(), new JitMegaC(), new JitMegaD(), new JitMegaE()];
if ($mode === 'enabled') {
    $session = Profiler::start();
}
$total = 0;
// One callsite sees five distinct method implementations, beyond the configured
// two-type polymorphic limit. This exercises runtime observer-handle selection.
for ($index = 0; $index < $iterations; ++$index) {
    $total += $objects[$index % 5]->visit('k' . ($index & 7));
}
$after = opcache_get_status(false);
if (empty($after['jit']['on']) || $before['jit']['buffer_free'] <= $after['jit']['buffer_free']) {
    throw new RuntimeException('actual JIT code generation required for megamorphic regression');
}
if ($total !== ((8 * $iterations) - 140)) {
    throw new RuntimeException('megamorphic application checksum changed');
}
$calls = null;
if ($mode === 'enabled') {
    $session->stop();
    $report = $session->getReport();
    $calls = [];
    if ($report['status'] !== 'complete') {
        throw new RuntimeException('megamorphic capture incomplete');
    }
    foreach (['A', 'B', 'C', 'D', 'E'] as $receiver) {
        $symbol = 'JitMega' . $receiver . '::visit';
        $calls[$symbol] = $report['functions'][$symbol]['calls'] ?? 0;
        if ($calls[$symbol] !== intdiv($iterations, 5)) {
            throw new RuntimeException('lost observed dynamic calls: ' . $symbol);
        }
    }
}
$other = null;
if (function_exists('profiler_test_other_observer_counts')) {
    $other = profiler_test_other_observer_counts();
    foreach (['begin', 'end'] as $phase) {
        if ($other[$phase] !== array_fill(0, 5, intdiv($iterations, 5))) {
            throw new RuntimeException('independent observer counts changed: ' . $phase);
        }
    }
}
echo
    json_encode([
        'php' => PHP_VERSION,
        'zts' => (bool) PHP_ZTS,
        'mode' => $mode,
        'iterations' => $iterations,
        'checksum' => $total,
        'calls' => $calls,
        'other' => $other,
        'cached' => opcache_is_script_cached(__FILE__),
        'jit_before' => $before['jit'],
        'jit_after' => $after['jit'],
    ], JSON_THROW_ON_ERROR),
    PHP_EOL
;
