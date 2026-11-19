<?php

declare(strict_types=1);

require_once __DIR__ . '/support.php';
require_once ROOT . '/scripts/lib/SanitizerDiagnostics.php';

use PhpProfiler\Tooling\Process;
use PhpProfiler\Tooling\SanitizerDiagnostics;

/**
 * Adversarial test-transport output must not conceal a sanitizer failure. The real
 * drivers run against a fake PHP that answers the startup probes and stops the driver
 * at its next subprocess. These are gate tests, not native sanitizer evidence.
 */
const SANITIZER_HEADERS = [
    "==14==ERROR: LeakSanitizer: detected memory leaks\n",
    "SUMMARY: AddressSanitizer: 20232 byte(s) leaked in 632 allocation(s).\n",
    "==14==ERROR: AddressSanitizer: heap-use-after-free on address 0x1234\n",
    "AddressSanitizer:DEADLYSIGNAL\n",
    "AddressSanitizer CHECK failed: sanitizer_common.cpp:42\n",
    "LeakSanitizer has encountered a fatal error.\n",
    "ASan runtime does not come first in initial library list\n",
];

/** @return array{string, string, int|null} outcome, combined output, status */
function exerciseStartup(
    string $driver,
    string $payload = '',
    int $status = 255,
    string $channel = 'stderr',
    string $fault = 'atfork',
): array {
    $directory = temporaryDirectory();
    $php = '#!' . PHP_BINARY . " -n\n<?php\n";
    writeFile("{$directory}/php", $php . <<<'PHP'
        $state = getenv('FAKE_STATE');
        if (file_exists("{$state}/selected")) {
            fwrite(STDERR, "STOPPED_AFTER_STARTUP\n");
            exit(97);
        }
        $program = implode(' ', array_slice($argv, 1));
        if (str_contains($program, 'json_encode(profiler_test_sampling_status()')) {
            echo json_encode(['supported' => true, 'expected_supported' => true, 'expected_zts' => getenv('FAKE_FAULT') === 'tsrm']);
            exit(0);
        }
        $module = str_contains($program, 'AFTER_MODULE_STARTUP');
        if ($module || str_contains($program, 'AFTER_SAMPLER_STARTUP')) {
            $selected = $module || getenv('PROFILER_SAMPLING_FAULT') === getenv('FAKE_FAULT');
            if ($selected) {
                touch("{$state}/selected");
            }
            $payload = $selected ? (string) getenv('FAKE_PAYLOAD') : '';
            echo "PHP Warning: Unable to start php_profiler module\n", $module ? "INJECTED_HANDLE_API_FAILURE\n" : '';
            fwrite(getenv('FAKE_CHANNEL') === 'stdout' ? STDOUT : STDERR, $payload);
            exit((int) getenv('FAKE_STATUS'));
        }
        exit(0);
        PHP, 0o755);
    writeFile("{$directory}/php-config", "#!/bin/sh\necho -I/test/include\n", 0o755);
    // On macOS the driver preloads this helper, so it must be a loadable, empty library.
    $helper = PHP_OS_FAMILY === 'Darwin' ? 'cc -dynamiclib -x c /dev/null -o "$2"' : ': > "$2"';
    writeFile("{$directory}/cc", "#!/bin/sh\nwhile [ \"\$1\" != -o ]; do shift; done\n{$helper}\n", 0o755);
    mkdir("{$directory}/state");
    $environment = [
        'PATH' => (string) getenv('PATH'),
        'PHP' => "{$directory}/php",
        'PHP_CONFIG' => "{$directory}/php-config",
        'CC' => "{$directory}/cc",
        'PROFILER_EXTENSION' => '/test-only/extension.so',
        'PROFILER_SAMPLING_EXTENSION' => '/test-only/extension.so',
        'PROFILER_LINKED_BAILOUT_FAULT' => '1',
        'CARGO_TARGET_DIR' => "{$directory}/target",
        'PROFILER_TOOL_PHP' => PHP_BINARY,
        'FAKE_STATE' => "{$directory}/state",
        'FAKE_PAYLOAD' => $payload,
        'FAKE_CHANNEL' => $channel,
        'FAKE_STATUS' => (string) $status,
        'FAKE_FAULT' => $fault,
    ];
    $result = Process::run([ROOT . '/scripts/php-tool.sh', ROOT . "/scripts/{$driver}"], $environment, timeout: 120);
    $output = $result['stdout'] . $result['stderr'];
    $outcome = match (true) {
        str_contains($output, 'Sanitizer failure') => 'rejected',
        str_contains($output, 'STOPPED_AFTER_STARTUP') => 'accepted_startup',
        default => 'other',
    };
    return [$outcome, $output, $result['status']];
}

return [
    'actual error headers and deadlysignal fail closed' => static function (): void {
        foreach (SANITIZER_HEADERS as $header) {
            $error = throws(RuntimeException::class, static fn() => SanitizerDiagnostics::reject(
                "expected PHP fatal\n" . $header,
                'intentional fatal',
            ));
            same(true, str_contains($error->getMessage(), 'Sanitizer failure'));
        }
    },
    'normal php fatal and informational init are not sanitizer errors' => static function (): void {
        foreach ([
            'PHP Fatal error: expected fixture allocation failure',
            'Unable to start php_profiler module',
            "==14==AddressSanitizer Init done\n",
        ] as $output) {
            SanitizerDiagnostics::reject($output, 'normal fixture');
        }
    },
    'ordinary php message quoting sanitizer text is not a tool header' => static function (): void {
        foreach ([
            'ERROR: AddressSanitizer: quoted application text',
            'SUMMARY: AddressSanitizer: quoted application text',
            'AddressSanitizer:DEADLYSIGNAL',
        ] as $quoted) {
            $diagnostic = 'PHP Fatal error: expected message containing "' . $quoted . "\" in fixture.php:1\n";
            SanitizerDiagnostics::reject($diagnostic, 'PHP quoted message');
            foreach (['sampling-controller-tests.php', 'native-bailout-tests.php'] as $driver) {
                same('accepted_startup', exerciseStartup($driver, $diagnostic)[0], "{$driver} {$quoted}:");
            }
        }
    },
    'color-always tool headers cannot bypass anchored detection' => static function (): void {
        foreach (SANITIZER_HEADERS as $header) {
            $colored = "\x1b[1m\x1b[31m" . rtrim($header) . "\x1b[0m\n";
            foreach (['sampling-controller-tests.php', 'native-bailout-tests.php'] as $driver) {
                same('rejected', exerciseStartup($driver, $colored)[0], "{$driver} {$header}:");
            }
        }
        // Real tools may put color boundaries between the PID and the severity.
        throws(RuntimeException::class, static fn() => SanitizerDiagnostics::reject(
            "==14==\x1b[1m\x1b[31mERROR: LeakSanitizer: detected memory leaks\x1b[0m\n",
            'color always',
        ));
    },
    'real sampling driver rejects stderr or stdout despite expected php fatal' => static function (): void {
        foreach (SANITIZER_HEADERS as $header) {
            foreach (['stdout', 'stderr'] as $channel) {
                foreach (['atfork', 'tsrm'] as $fault) {
                    [$outcome, $output] = exerciseStartup(
                        'sampling-controller-tests.php',
                        $header,
                        channel: $channel,
                        fault: $fault,
                    );
                    same('rejected', $outcome, "{$header} {$channel} {$fault}:");
                    same(true, str_contains($output, rtrim($header)));
                    same(false, str_contains($output, "{$fault} registration failure PASS"));
                }
            }
        }
    },
    'real sampling driver rejects default lsan exit one without sanitizer environment flags' =>
        static function (): void {
            same('rejected', exerciseStartup('sampling-controller-tests.php', SANITIZER_HEADERS[0], status: 1)[0]);
        },
    'real native bailout driver rejects sanitizer output even at expected fatal exit' => static function (): void {
        foreach (SANITIZER_HEADERS as $header) {
            foreach (['stdout', 'stderr'] as $channel) {
                [$outcome, $output] = exerciseStartup('native-bailout-tests.php', $header, channel: $channel);
                same('rejected', $outcome, "{$header} {$channel}:");
                same(true, str_contains($output, rtrim($header)));
                same(false, str_contains($output, 'startup rejects dependency failure'));
            }
        }
    },
    'both real driver paths accept a normal expected php startup failure' => static function (): void {
        foreach (['sampling-controller-tests.php', 'native-bailout-tests.php'] as $driver) {
            [$outcome, $output] = exerciseStartup($driver);
            same('accepted_startup', $outcome, $driver . ':');
            same(
                true,
                str_contains($output, 'registration failure PASS')
                || str_contains($output, 'startup rejects dependency failure'),
                $output,
            );
        }
    },
];
