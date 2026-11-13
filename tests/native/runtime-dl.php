<?php

declare(strict_types=1);

function after_rejected_profiler_load(): int
{
    return 42;
}

register_shutdown_function(static function (): void {
    if (class_exists('PhpProfiler\\Profiler', false) || class_exists('PhpProfiler\\Session', false)) {
        throw new RuntimeException('runtime loading partially registered profiler classes');
    }
    if (after_rejected_profiler_load() !== 42) {
        throw new RuntimeException('shutdown PHP execution state changed');
    }
    echo "runtime dl rejected before class registration; shutdown PHP remains valid\n";
});
// Zend treats a MINIT failure as E_CORE_ERROR. Its documented native fatal path
// is preferable to registering observers after the engine's startup-only phase.
dl($argv[1]);
echo "AFTER_DL_UNEXPECTED\n";
