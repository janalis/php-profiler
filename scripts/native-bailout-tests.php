<?php

declare(strict_types=1);

// Real C boundaries and dependency faults, with no production fault switches.
require_once __DIR__ . '/lib/Fail.php';
require_once __DIR__ . '/lib/Paths.php';
require_once __DIR__ . '/lib/Process.php';
require_once __DIR__ . '/lib/ProcessGroups.php';
require_once __DIR__ . '/lib/SanitizerDiagnostics.php';

use PhpProfiler\Tooling\Paths;
use PhpProfiler\Tooling\Process;
use PhpProfiler\Tooling\ProcessGroups;
use PhpProfiler\Tooling\SanitizerDiagnostics;

use function PhpProfiler\Tooling\fail;
use function PhpProfiler\Tooling\requiredEnvironment;

$root = Paths::resolve(dirname(__DIR__));
chdir($root);
$darwin = PHP_OS_FAMILY === 'Darwin';
if (!$darwin && getenv('PROFILER_LINKED_BAILOUT_FAULT') !== '1') {
    fail('Linux requires the explicitly test-linked --wrap fault module');
}
$php = (string) getenv('PHP') ?: 'php';
$extension = requiredEnvironment('PROFILER_EXTENSION');
$units = getenv('PROFILER_C_UNIT_BOUNDARIES') === '1';
$target = ((string) getenv('CARGO_TARGET_DIR') ?: "{$root}/target") . '/fault-tests';
if (!is_dir($target)) {
    mkdir($target, 0o777, true);
}
$flags = preg_split(
    '/\s+/',
    trim(Process::output([(string) getenv('PHP_CONFIG') ?: 'php-config', '--includes'])),
    flags: PREG_SPLIT_NO_EMPTY,
);
$helper = "{$target}/bailout-fault.dylib";
if ($darwin) {
    Process::check([
        (string) getenv('CC') ?: 'cc',
        '-dynamiclib',
        '-Wl,-undefined,dynamic_lookup',
        ...$flags,
        'tests/native/bailout-fault.c',
        '-o',
        $helper,
    ]);
}
/** The environment for one fault mode: inherited, plus the macOS interposer. */
$faultEnvironment = static function (array $additions) use ($darwin, $helper): array {
    $environment = [...Process::environment(), ...$additions];
    if ($darwin) {
        $environment['DYLD_INSERT_LIBRARIES'] = Paths::resolve($helper);
    }
    return $environment;
};
$checked = static function (array $result, string $context): string {
    $output = $result['stdout'] . "\n" . $result['stderr'];
    SanitizerDiagnostics::reject($output, $context);
    return $result['stdout'] . $result['stderr'];
};
$reports = sys_get_temp_dir() . '/profiler-native-boundary-reports-' . bin2hex(random_bytes(6));
mkdir($reports, 0o700);
register_shutdown_function(static fn() => Paths::removeTree($reports));
if ($units) {
    foreach ([
        'materialize',
        'trigger',
        'headers',
        'log',
        'inherited-log',
        'header',
        'worker',
        'worker-start',
    ] as $mode) {
        $processes = new ProcessGroups();
        try {
            $result = $processes->run(
                [$php, '-n', '-d', 'extension=' . $extension, '-r', 'profiler_test_automatic_bailout();'],
                30,
                [...Process::environment(), 'PROFILER_TEST_AUTOMATIC_BAILOUT' => $mode],
                capture: true,
            );
        } finally {
            $processes->close();
        }
        $checked([
            'stdout' => (string) $result->stdout,
            'stderr' => (string) $result->stderr,
        ], 'automatic native bailout');
        if ($result->status !== 0 || !str_contains((string) $result->stdout, 'AUTOMATIC_C_GUARD_OK')) {
            fail("{$mode}: automatic guard failed: {$result->status}: {$result->stdout}{$result->stderr}");
        }
        echo "{$mode}: isolated automatic C bailout contract passed\n";
    }
    foreach (['decode', 'string', 'allowed', 'resolve', 'exact', 'ini'] as $mode) {
        $result = Process::run(
            [$php, '-n', '-d', 'extension=' . $extension, '-r', 'profiler_test_guard_contract();'],
            $faultEnvironment(['PROFILER_TEST_BAILOUT' => $mode]),
            timeout: 30,
        );
        $checked($result, 'native bailout boundary');
        if ($result['status'] !== 0 || !str_contains($result['stdout'], 'C_GUARD_CONTRACT_OK')) {
            fail(
                "{$mode}: C return contract failed: "
                . json_encode($result['status'])
                . ": {$result['stdout']}{$result['stderr']}",
            );
        }
        echo "{$mode}: cold native guard return/publication/rethrow contract passed\n";
    }
}
$startupModes = ['handle-api', ...($units ? ['handles-negative', 'handles-capacity', 'ini-register'] : [])];
foreach ($startupModes as $mode) {
    $result = Process::run(
        [$php, '-n', '-d', 'extension=' . $extension, '-r', 'echo "AFTER_MODULE_STARTUP";'],
        $faultEnvironment(['PROFILER_TEST_BAILOUT' => $mode]),
        timeout: 30,
    );
    $output = $checked($result, 'native bailout boundary');
    $marker = match ($mode) {
        'handle-api' => 'INJECTED_HANDLE_API_FAILURE',
        'ini-register' => 'INJECTED_INI_REGISTER_FAILURE',
        default => 'INJECTED_HANDLE_COUNT_FAILURE',
    };
    if (
        !in_array($result['status'], [254, 255], true)
        || !str_contains($output, $marker)
        || !str_contains($output, 'Unable to start')
        || str_contains($output, 'AFTER_MODULE_STARTUP')
    ) {
        fail("{$mode}: module failure was not safely rejected: " . json_encode($result['status']) . ": {$output}");
    }
    echo "{$mode}: startup rejects dependency failure before PHP execution (exit {$result['status']})\n";
}
if ($units) {
    foreach ([
        'tests/native/fiber-observations.php',
        'tests/native/fiber-tracking.php',
        'tests/native/closure-uid-boundaries.php',
    ] as $fixture) {
        Process::check([$php, '-n', '-d', 'extension=' . $extension, $fixture], timeout: 30);
    }
    $opcache = [$php, '-n'];
    if (Process::run([...$opcache, '-r', "exit(extension_loaded('Zend OPcache') ? 0 : 1);"])['status'] !== 0) {
        array_push($opcache, '-d', 'zend_extension=opcache');
    }
    Process::check([
        ...$opcache,
        '-d',
        'opcache.enable_cli=1',
        '-d',
        'opcache.file_update_protection=0',
        '-d',
        'extension=' . $extension,
        'tests/native/closure-map-pointers.php',
    ], timeout: 30);
}
foreach (['decode', 'string', 'allowed', 'resolve'] as $mode) {
    $outerReport = "{$reports}/{$mode}.json";
    $result = Process::run(
        [$php, '-n', '-d', 'extension=' . $extension, 'tests/native/bailout-fault.php', $mode, $outerReport],
        $faultEnvironment(['PROFILER_TEST_BAILOUT' => $mode]),
        timeout: 30,
    );
    $output = $checked($result, 'native bailout boundary');
    foreach (preg_split('/\r\n|\r|\n/', $result['stdout']) as $line) {
        if (str_starts_with($line, 'NATIVE_TARGET=')) {
            $path = substr($line, strlen('NATIVE_TARGET='));
            if (file_exists($path)) {
                unlink($path);
                fail("{$mode}: report published after fatal path-check failure");
            }
        }
    }
    if (
        $result['status'] !== 255
        || !str_contains($output, 'INJECTED_NATIVE_BAILOUT')
        || str_contains($output, 'AFTER_NATIVE_FAULT')
        || str_contains($output, 'CAUGHT_NATIVE_FAULT')
    ) {
        fail("{$mode}: lost fatal boundary status " . json_encode($result['status']) . ":\n{$output}");
    }
    if (in_array($mode, ['decode', 'string'], true)) {
        $report = json_decode((string) file_get_contents($outerReport), true, flags: JSON_THROW_ON_ERROR);
        if ($report['functions']['native_fault_consumer']['exceptions'] !== 0) {
            fail("{$mode}: native fatal failure manufactured a PHP exception");
        }
    }
    echo "{$mode}: real native guard defers fatal until Rust returns\n";
}
foreach ($darwin ? [] : ['small', 'failure', 'exact', 'unterminated'] as $mode) {
    $result = Process::run(
        [$php, '-n', '-d', 'extension=' . $extension, 'tests/native/bailout-fault.php', $mode],
        $faultEnvironment(['PROFILER_TEST_BAILOUT' => $mode]),
        timeout: 30,
    );
    $checked($result, 'native bailout boundary');
    if ($result['status'] !== 0 || !str_contains($result['stdout'], 'EXPECTED_BOUNDARY_DENIAL')) {
        fail("{$mode}: boundary failure not safely propagated: {$result['stdout']}{$result['stderr']}");
    }
    echo "{$mode}: native boundary failure preserves report without publication\n";
}
$result = Process::run(
    [$php, '-n', '-d', 'extension=' . $extension, 'tests/native/bailout-fault.php', 'control'],
    $faultEnvironment(['PROFILER_TEST_BAILOUT' => 'control']),
    timeout: 30,
);
$checked($result, 'native bailout boundary');
if ($result['status'] !== 0 || !str_contains($result['stdout'], 'CONTROL_CLOCKS_INDEPENDENT')) {
    fail("control API observed unrelated clocks: {$result['stdout']}{$result['stderr']}");
}
echo "control state queries do not contaminate clock measurements\n";
