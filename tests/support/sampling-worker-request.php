<?php

declare(strict_types=1);

use PhpProfiler\Profiler;

function embed_sample_work(): int
{
    $end = hrtime(true) + 70000000;
    $sum = 0;
    while (hrtime(true) < $end) {
        for ($i = 0; $i < 1000; $i++)
            $sum += ($i * 13) % 97;
    }
    if ($sum <= 0)
        throw new RuntimeException('work checksum');
    return 73;
}

if (!Profiler::isSamplingSupported())
    throw new RuntimeException('Linux sampler unexpectedly unsupported');
$s = Profiler::start(null, ['sampling' => true]);
if ($s->getId() !== 1)
    throw new RuntimeException('request session leaked');
embed_sample_barrier();
if (embed_sample_work() !== 73)
    throw new RuntimeException('main semantics');
$f = new Fiber(static function (): int {
    return embed_sample_work();
});
$f->start();
if ($f->getReturn() !== 73)
    throw new RuntimeException('fiber semantics');
$s->stop();
$r = $s->getReport();
if (
    $r['schema_version'] !== 2
    || $r['status'] !== 'complete'
    || $r['functions']['embed_sample_work']['calls'] !== 2
    || $r['sampling']['captured_samples'] < 2
)
    throw new RuntimeException('sample report/count isolation failed: ' . json_encode($r['sampling']));
$contexts = [];
foreach ($r['sampling']['samples'] as $v)
    $contexts[$v['context_id']] = true;
if (count($contexts) < 2)
    throw new RuntimeException('main/fiber sample contexts merged');
$j = $s->getReportJson();
if (str_contains($j, __FILE__))
    throw new RuntimeException('source path leak');
file_put_contents($embed_receipt, json_encode([
    'id' => $s->getId(),
    'samples' => $r['sampling']['captured_samples'],
    'contexts' => array_keys($contexts),
    'calls' => $r['functions']['embed_sample_work']['calls'],
]));
$s->reset();
