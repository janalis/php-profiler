<?php

declare(strict_types=1);

// Runs controller fault contracts in genuine initialized PHP subprocesses. The
// debug-only C translation unit delegates normal libc calls. A failed join is
// injected once without consuming the real handle, so the controller must retry and
// complete the real join before PHP can continue.
require_once __DIR__ . '/lib/Fail.php';
require_once __DIR__ . '/lib/Paths.php';
require_once __DIR__ . '/lib/Process.php';
require_once __DIR__ . '/lib/ProcessGroups.php';
require_once __DIR__ . '/lib/SanitizerDiagnostics.php';

use PhpProfiler\Tooling\Paths;
use PhpProfiler\Tooling\Process;
use PhpProfiler\Tooling\ProcessGroups;
use PhpProfiler\Tooling\SanitizerDiagnostics;

$root = Paths::resolve(dirname(__DIR__));
chdir($root);
$php = Paths::which((string) getenv('PHP') ?: 'php') ?? ((string) getenv('PHP') ?: 'php');
$environment = getenv();
$configured = $environment['PROFILER_SAMPLING_EXTENSION'] ?? '';
if ($configured !== '') {
    $extension = Paths::resolve($configured);
} else {
    $environment['PROFILER_SAMPLING_UNIT'] = '1';
    $target = ($environment['CARGO_TARGET_DIR'] ?? '' ?: "{$root}/target") . '/sampling-unit';
    $cargo = [$environment['CARGO'] ?? '' ?: 'cargo'];
    if (($environment['PROFILER_COVERAGE_TOOLCHAIN'] ?? '') !== '') {
        $cargo[] = '+' . $environment['PROFILER_COVERAGE_TOOLCHAIN'];
    }
    Process::check([...$cargo, 'build', '-p', 'php-profiler', '--target-dir', $target], $environment);
    $extension = Paths::resolve("{$target}/debug/libphp_profiler." . (PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so'));
}
if (($environment['PROFILER_ASAN_RUNTIME'] ?? '') !== '') {
    $environment[PHP_OS_FAMILY === 'Darwin' ? 'DYLD_INSERT_LIBRARIES' : 'LD_PRELOAD'] =
        $environment['PROFILER_ASAN_RUNTIME'];
}
/** @param array<string, string|null> $changes null removes a variable */
$with = static function (array $changes) use ($environment): array {
    $copy = $environment;
    foreach ($changes as $name => $value) {
        if ($value === null) {
            unset($copy[$name]);
        } else {
            $copy[$name] = $value;
        }
    }
    return $copy;
};
$processes = new ProcessGroups();
try {
    $processes->run([$php, '-n', 'tests/native/stopped-sampling-assertions.php'], 30, $environment, check: true);
    $base = [$php, '-n', '-d', 'extension=' . $extension];
    $status = json_decode(
        (string) $processes->run(
            [...$base, '-r', 'echo json_encode(profiler_test_sampling_status(), JSON_THROW_ON_ERROR);'],
            30,
            $environment,
            capture: true,
            check: true,
        )->stdout,
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    if (!is_bool($status['supported']) || $status['supported'] !== $status['expected_supported']) {
        throw new RuntimeException('sampling capability differs from independent compiled-platform contract');
    }
    $supported = (bool) $status['expected_supported'];
    // Genuine initialized-PHP defensive provider and observation contracts. Every case
    // delegates normal resources and uses its own fresh PHP process.
    if ($supported) {
        foreach ([
            'conversion',
            'snapshot-error',
            'deadline-exact',
            'ring-wrap',
            'status-mutex',
            'worker-mutex',
            'destroy-retirement',
            'unready-drain',
            'drain-mutex',
            'mutex-error',
            'clock',
            'range',
            'wake-clock',
            'wake-range',
        ] as $mode) {
            $fault = in_array($mode, ['unready-drain', 'mutex-error'], true) ? 'mutex' : null;
            $processes->run(
                [...$base, 'tests/native/sampling-engine-boundaries.php', $mode],
                30,
                $with(['PROFILER_SAMPLING_FAULT' => $fault]),
                check: true,
            );
        }
        foreach ([
            'normal',
            'join',
            'signal',
            'create',
            'attr',
            'queued-reset',
            'request-shutdown',
            'module-shutdown',
            'free-first',
            'fork-registry',
            'stack-equal',
            'page-zero',
            'ctor-mutex',
        ] as $mode) {
            $processes->run(
                [...$base, 'tests/native/sampling-lifecycle-contract.php', $mode],
                30,
                $with(['PROFILER_SAMPLING_FAULT' => $mode === 'ctor-mutex' ? 'mutex' : null]),
                check: true,
            );
        }
        foreach ([
            'equal',
            'equal-wake',
            'service-clock',
            'service-range',
            'service-regression',
            'identity',
            'depth',
            'backlog',
            'registration',
        ] as $mode) {
            $processes->run(
                [...$base, 'tests/native/sampling-frame-contract.php', $mode],
                30,
                $with(['PROFILER_SAMPLING_FAULT' => null]),
                check: true,
            );
        }
    }
    $modes = ['normal'];
    if ($supported) {
        array_push(
            $modes,
            'mutex',
            'condattr',
            'setclock',
            'condition',
            'attr',
            'page',
            'stack',
            'guard',
            'mask',
            'create',
            'join',
            'clock',
            'range',
            'wait',
            'snapshot-clock',
            'snapshot-range',
            'nsec-negative',
            'nsec-upper',
            'seconds-overflow',
            'deadline-overflow',
            'capacity',
            'counter',
            'backlog',
            'fork',
            'request-restart',
            'prior-clock-join',
            'dropped-counter',
            'spurious',
            'wake-clock',
            'wake-range',
            'wake-regression',
            'wake-equal',
            'register-again',
            'lock-contention',
            'lifecycle',
            'real-spurious',
            'fork-clock',
            'fork-range',
        );
    }
    foreach ($modes as $mode) {
        $fault = in_array($mode, ['mutex', 'condattr', 'setclock', 'condition'], true) ? $mode : null;
        $processes->run(
            [...$base, 'tests/native/sampling-controller.php', $mode],
            30,
            $with(['PROFILER_SAMPLING_FAULT' => $fault]),
            check: true,
        );
    }
    if ($supported) {
        foreach (['atfork', ...($status['expected_zts'] ? ['tsrm'] : [])] as $failure) {
            $failed = $processes->run(
                [...$base, '-r', 'echo "AFTER_SAMPLER_STARTUP";'],
                30,
                $with(['PROFILER_SAMPLING_FAULT' => $failure]),
                capture: true,
            );
            $diagnostic = $failed->stdout . "\n" . $failed->stderr;
            SanitizerDiagnostics::reject($diagnostic, "{$failure} sampler registration");
            if (
                $failed->status === 0
                || str_contains($diagnostic, 'AFTER_SAMPLER_STARTUP')
                || !str_contains($diagnostic, 'Unable to start php_profiler module')
            ) {
                throw new RuntimeException('native registration failure did not fail startup: ' . $diagnostic);
            }
            echo "{$failure} registration failure PASS\n";
        }
    }
    $processes->run([...$base, 'tests/native/sampling-stack.php'], 30, $environment, check: true);
    if ($supported) {
        foreach ([
            'create',
            'snapshot-clock',
            'snapshot-range',
            'clock',
            'wait',
            'join',
            'service-clock',
            'service-range',
            'service-regression',
        ] as $mode) {
            $processes->run([...$base, 'tests/native/sampling-recovery.php', $mode], 30, $environment, check: true);
        }
        $signalBase = [$php, '-n'];
        foreach (['pcntl', 'posix'] as $module) {
            if (
                $processes->run(
                    [$php, '-n', '-r', "exit(extension_loaded('{$module}') ? 0 : 1);"],
                    30,
                    $environment,
                )->status !== 0
            ) {
                array_push($signalBase, '-d', 'extension=' . $module);
            }
        }
        // Load the genuine earlier interrupt owner before the profiler module.
        array_push($signalBase, '-d', 'extension=' . $extension);
        $processes->run(
            [...$signalBase, 'tests/native/sampling-inactive-interrupt.php'],
            30,
            $environment,
            check: true,
        );
    }
    $processes->run([...$base, 'tests/native/sampling-nested.php'], 30, $environment, check: true);
    $processes->run([...$base, 'tests/native/sampling-identity.php'], 30, $environment, check: true);
} finally {
    $processes->close();
}
