<?php

declare(strict_types=1);

require_once __DIR__ . '/support.php';
require_once ROOT . '/scripts/lib/MutationUnviable.php';

use PhpProfiler\Tooling\MutationUnviable;
use PhpProfiler\Tooling\Process;

function unviableLog(string $diagnostic): string
{
    return "*** mutation diff:\n+ error[E9999]: text in user string\n*** cargo nextest run --no-run\n" . $diagnostic;
}

return [
    'rejects infrastructure even with rust error' => static function (): void {
        foreach ([
            'unable to copy x.o: No such file or directory',
            'linking with cc failed: exit status: 1',
            'could not execute process rustc',
            'failed to download package',
            'No space left on device',
        ] as $error) {
            same(
                'infrastructure_or_unknown',
                MutationUnviable::classifyLog(unviableLog(
                    "error[E0308]: mismatched types\nerror: {$error}",
                ))['classification'],
                $error,
            );
        }
    },
    'compile rejects and summary' => static function (): void {
        foreach ([
            'error[E0308]: mismatched types',
            'error: `||` operators are not supported in let chain conditions',
        ] as $text) {
            same(
                'compiler_rejected',
                MutationUnviable::classifyLog(unviableLog($text
                . "\nerror: could not compile `x` (lib) due to 1 previous error"))['classification'],
            );
        }
        same(
            'compiler_rejected',
            MutationUnviable::classifyLog(unviableLog(
                "\x1b[0m\x1b[1m\x1b[38;5;9merror[E0308]\x1b[0m: mismatched",
            ))['classification'],
        );
    },
    'diff is not diagnostic' => static function (): void {
        same(
            'infrastructure_or_unknown',
            MutationUnviable::classifyLog(unviableLog('error: command `cargo` exited with code 101'))['classification'],
        );
        same(
            'infrastructure_or_unknown',
            MutationUnviable::classifyLog('error[E0308]: text without command')['classification'],
        );
    },
    'unknown error code fails closed' => static function (): void {
        same(
            'infrastructure_or_unknown',
            MutationUnviable::classifyLog(unviableLog('error[E0463]: cannot find crate std'))['classification'],
        );
    },
    'unknown error fails closed' => static function (): void {
        same(
            'infrastructure_or_unknown',
            MutationUnviable::classifyLog(unviableLog('error: future unknown problem'))['classification'],
        );
    },
    'parallel shared target rejected before cargo' => static function (): void {
        $directory = temporaryDirectory();
        writeFile("{$directory}/cargo", "#!/bin/sh\ntouch \"\$PROFILER_TEST_METADATA_MARKER\"\nexit 42\n", 0o700);
        $environment = withEnvironment([
            'PATH' => $directory . ':' . getenv('PATH'),
            'CARGO_TARGET_DIR' => "{$directory}/shared",
            'PROFILER_TEST_METADATA_MARKER' => "{$directory}/metadata-called",
        ]);
        $command = [
            ROOT . '/scripts/php-tool.sh',
            ROOT . '/scripts/rust-mutation.php',
            '--list-only',
            '--output',
            "{$directory}/result",
        ];
        $rejected = Process::run([...$command, '--jobs', '2'], $environment);
        same(2, $rejected['status']);
        same(true, str_contains($rejected['stderr'], 'separate target directories'), $rejected['stderr']);
        same(false, file_exists("{$directory}/metadata-called"));
        $admitted = Process::run([...$command, '--jobs', '1'], $environment);
        same(true, $admitted['status'] !== 0);
        same(true, file_exists("{$directory}/metadata-called"));
    },
    'log path cannot escape' => static function (): void {
        $directory = temporaryDirectory();
        $error = throws(InvalidArgumentException::class, static fn() => MutationUnviable::audit([
            'unviable' => 1,
            'outcomes' => [['summary' => 'Unviable', 'log_path' => '../outside']],
        ], $directory));
        same(true, str_contains($error->getMessage(), 'escapes'));
    },
    'count and phase must agree' => static function (): void {
        $error = throws(InvalidArgumentException::class, static fn() => MutationUnviable::audit([
            'unviable' => 1,
            'outcomes' => [],
        ], '.'));
        same(true, str_contains($error->getMessage(), 'count'));
        $directory = temporaryDirectory();
        writeFile("{$directory}/log", unviableLog('error[E0308]: mismatch'));
        $result = MutationUnviable::audit([
            'unviable' => 1,
            'outcomes' => [[
                'summary' => 'Unviable',
                'log_path' => 'log',
                'scenario' => [],
                'phase_results' => [['phase' => 'Test']],
            ]],
        ], $directory);
        same(1, $result['infrastructure_or_unknown']);
        $result = MutationUnviable::audit([
            'unviable' => 1,
            'outcomes' => [[
                'summary' => 'Unviable',
                'log_path' => 'log',
                'scenario' => [],
                'phase_results' => [['phase' => 'Build', 'process_status' => ['Failure' => 101]]],
            ]],
        ], $directory);
        same(1, $result['compiler_rejected']);
    },
];
