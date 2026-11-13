<?php

declare(strict_types=1);

use PhpProfiler\Profiler;

$mode = $argv[1];
$outer = isset($argv[2]) ? Profiler::start($argv[2]) : null;
function native_fault_consumer(string $mode): void
{
    $path = in_array($mode, ['allowed', 'resolve', 'small', 'failure', 'exact', 'unterminated'], true)
        ? sys_get_temp_dir() . '/profiler-fault-' . getmypid() . '.json'
        : null;
    $session = Profiler::start($path);
    for ($i = 0; $i < 25; ++$i) {
        $name = 'fault_' . $i . str_repeat('x', 500);
        eval('function ' . $name . '() { return 1; }');
        $name();
    }
    fwrite(STDOUT, "BEFORE_NATIVE_FAULT\n");
    if ($path !== null) {
        fwrite(STDOUT, 'NATIVE_TARGET=' . $path . "\n");
    }
    try {
        if ($mode === 'control') {
            if (!Profiler::isEnabled()) {
                throw new RuntimeException('control state');
            }
            $session->stop();
            if ($session->getReport()['status'] !== 'complete') {
                throw new RuntimeException('control read polluted measured clocks');
            }
            echo 'CONTROL_CLOCKS_INDEPENDENT';
            exit(0);
        }
        if ($path !== null) {
            if (in_array($mode, ['small', 'failure', 'exact', 'unterminated'], true)) {
                try {
                    $session->stop();
                } catch (PhpProfiler\PersistenceException|PhpProfiler\StateException $error) {
                    if (
                        $mode === 'unterminated'
                        && (
                            !$error instanceof PhpProfiler\StateException
                            || $error->getMessage() !== 'Invalid resolved output path'
                        )
                    ) {
                        throw new RuntimeException('corrupt resolver contract was not rejected precisely');
                    }
                    if ($mode !== 'unterminated' && !$error instanceof PhpProfiler\PersistenceException) {
                        throw new RuntimeException('boundary denial changed exception type');
                    }
                    echo 'EXPECTED_BOUNDARY_DENIAL';
                    exit(0);
                }
                throw new RuntimeException('boundary denial was not propagated');
            }
            $session->stop();
        } else {
            $session->stop();
            if ($mode === 'decode') {
                $session->getReport();
            } else {
                $session->getReportJson();
            }
        }
    } catch (Throwable $error) {
        echo "CAUGHT_NATIVE_FAULT\n";
        exit(17);
    }
    echo "AFTER_NATIVE_FAULT\n";
}

native_fault_consumer($mode);
