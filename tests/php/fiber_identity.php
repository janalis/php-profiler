<?php

declare(strict_types=1);

use PhpProfiler\Profiler;

Profiler::resetAll();
$callback = static function (): void {
    usleep(1);
};
$session = Profiler::start(null, ['timeline' => true]);
for ($i = 0; $i < 32; $i++) {
    $fiber = new Fiber($callback);
    $fiber->start();
    unset($fiber);
}
$session->stop();
$contexts = [];
$count = 0;
foreach ($session->getReport()['timeline'] as $event) {
    if (str_starts_with($event['symbol'], '{closure#')) {
        $contexts[$event['context_id']] = true;
        $count++;
    }
}
if ($count !== 32 || count($contexts) !== 32) {
    throw new RuntimeException('destroyed fiber context identities were reused');
}
Profiler::resetAll();
echo "fiber identity lifecycle passed\n";
