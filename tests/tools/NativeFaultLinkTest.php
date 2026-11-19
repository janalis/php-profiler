<?php

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use PhpProfiler\Tooling\Process;

/** Test-only compiler and Cargo transports verify the shell plumbing, not ELF freshness. */
function faultLinkFixture(): array
{
    $directory = temporaryDirectory();
    $tools = "{$directory}/tools";
    writeFile("{$directory}/tests/native/bailout-fault.c", "first native shim input\n");
    mkdir("{$directory}/build");
    $php = '#!' . PHP_BINARY . " -n\n<?php\n";
    writeFile(
        "{$tools}/php-config",
        "#!/bin/sh\nprintf '%s\\n' '-I/test/include -I/test/ext'\nexit \${FAKE_PHP_CONFIG_STATUS:-0}\n",
        0o755,
    );
    writeFile("{$tools}/cargo", $php . <<<'PHP'
        file_put_contents(getenv('CARGO_LOG'), json_encode(array_slice($argv, 1)) . "\n", FILE_APPEND);
        exit((int) getenv('FAKE_CARGO_FAIL'));
        PHP, 0o755);
    writeFile("{$tools}/fake-cc", $php . <<<'PHP'
        $args = array_slice($argv, 1);
        file_put_contents(getenv('CC_LOG'), json_encode($args) . "\n", FILE_APPEND);
        if (getenv('FAKE_CC_FAIL')) {
            fwrite(STDERR, "intentional compiler transport failure\n");
            exit(73);
        }
        $index = array_search('-o', $args, true);
        [$source, $target] = [$args[$index - 1], $args[$index + 1]];
        file_put_contents($target, getenv('FAKE_OBJECT_CONSTANT') ? 'constant object bytes' : "test transport object\0" . file_get_contents($source));
        PHP, 0o755);
    $environment = withEnvironment([
        'PATH' => $tools . ':' . getenv('PATH'),
        'CC' => "{$tools}/fake-cc",
        'CARGO_TARGET_DIR' => "{$directory}/build",
        'CC_LOG' => "{$directory}/cc.jsonl",
        'CARGO_LOG' => "{$directory}/cargo.jsonl",
        'PROFILER_LINK_TEST_SCRIPT' => ROOT . '/scripts/link-native-faults.sh',
        'LINK_MARKER' => "{$directory}/linked-marker",
        'PROFILER_TOOL_PHP' => PHP_BINARY,
    ]);
    foreach ([
        'CFLAGS',
        'RUSTFLAGS',
        'PROFILER_COVERAGE_TOOLCHAIN',
        'PROFILER_LINKED_BAILOUT_FAULT',
        'FAKE_CC_FAIL',
        'FAKE_OBJECT_CONSTANT',
        'FAKE_CARGO_FAIL',
    ] as $name) {
        unset($environment[$name]);
    }
    return [$directory, $environment];
}

function sourceLink(string $directory, array $environment, bool $errexit = true): array
{
    $script = 'source "$PROFILER_LINK_TEST_SCRIPT"; status=$?; if [[ $status != 0 ]]; then exit "$status"; fi; printf "%s\n" "$PROFILER_LINKED_BAILOUT_FAULT" > "$LINK_MARKER"';
    return Process::run(['bash', $errexit ? '-euc' : '-uc', $script], $environment, $directory, timeout: 30);
}

function jsonLines(string $path): array
{
    return (
        is_file($path)
            ? array_map(static fn(string $line): array => json_decode(
                $line,
                true,
            ), array_filter(explode("\n", (string) file_get_contents($path)))) : []
    );
}

function linkObject(string $directory, array $call): string
{
    $paths = array_values(array_filter(array_map(static fn(string $argument): ?string => str_starts_with(
        $argument,
        'link-arg=',
    ) && str_ends_with($argument, '.o')
            ? substr($argument, 9)
            : null, $call)));
    same(1, count($paths), json_encode($call));
    same('bailout-fault-' . hash_file('sha256', $paths[0]) . '.o', basename($paths[0]));
    same(false, $paths[0] === "{$directory}/build/bailout-fault.o");
    return $paths[0];
}

return [
    'repeated identical object uses identical immutable link path' => static function (): void {
        [$directory, $environment] = faultLinkFixture();
        foreach ([1, 2] as $attempt) {
            $result = sourceLink($directory, $environment);
            same(0, $result['status'], $result['stdout'] . $result['stderr']);
        }
        $calls = jsonLines("{$directory}/cargo.jsonl");
        same(2, count($calls));
        same($calls[0], $calls[1]);
        same(linkObject($directory, $calls[0]), linkObject($directory, $calls[1]));
        $commands = jsonLines("{$directory}/cc.jsonl");
        same($commands[0], $commands[1]);
        same("{$directory}/build/bailout-fault.o", $commands[0][count($commands[0]) - 1]);
        same("1\n", file_get_contents("{$directory}/linked-marker"));
    },
    'changing only shim input changes link argument and preserves old object' => static function (): void {
        [$directory, $environment] = faultLinkFixture();
        same(0, sourceLink($directory, $environment)['status']);
        $old = linkObject($directory, jsonLines("{$directory}/cargo.jsonl")[0]);
        $oldBytes = file_get_contents($old);
        file_put_contents("{$directory}/tests/native/bailout-fault.c", "second native shim input\n");
        same(0, sourceLink($directory, $environment)['status']);
        $calls = jsonLines("{$directory}/cargo.jsonl");
        $new = linkObject($directory, $calls[1]);
        same(false, $old === $new);
        same(false, $oldBytes === file_get_contents($new));
        same($oldBytes, file_get_contents($old));
        $metadata = json_decode((string) file_get_contents("{$directory}/build/native-fault-link.json"), true);
        same(hash_file('sha256', "{$directory}/tests/native/bailout-fault.c"), $metadata['source_sha256']);
        same($new, $metadata['link_object']);
    },
    'same resulting object stays the same even when the source changes' => static function (): void {
        [$directory, $environment] = faultLinkFixture();
        $environment['FAKE_OBJECT_CONSTANT'] = '1';
        same(0, sourceLink($directory, $environment)['status']);
        file_put_contents(
            "{$directory}/tests/native/bailout-fault.c",
            "changed source but constant compiler transport result\n",
        );
        same(0, sourceLink($directory, $environment)['status']);
        $calls = jsonLines("{$directory}/cargo.jsonl");
        same(linkObject($directory, $calls[0]), linkObject($directory, $calls[1]));
    },
    'quoted flags remain one argument and shell text is never evaluated' => static function (): void {
        [$directory, $environment] = faultLinkFixture();
        $payload = '-DUNTRUSTED=$(touch ' . "{$directory}/must-not-exist" . ')';
        $environment['CFLAGS'] = '-DMESSAGE="hello world" "' . $payload . '"';
        $result = sourceLink($directory, $environment);
        same(0, $result['status'], $result['stdout'] . $result['stderr']);
        $arguments = jsonLines("{$directory}/cc.jsonl")[0];
        same([true, true, false, false], [
            in_array('-DMESSAGE=hello world', $arguments, true),
            in_array($payload, $arguments, true),
            in_array('-DMESSAGE=hello', $arguments, true),
            file_exists("{$directory}/must-not-exist"),
        ]);
        same(
            ['-DMESSAGE=hello world', $payload],
            json_decode((string) file_get_contents("{$directory}/build/native-fault-link.json"), true)['CFLAGS'],
        );
    },
    'failed php sdk provider cannot hide its status behind printed include flags' => static function (): void {
        [$directory, $environment] = faultLinkFixture();
        $failed = sourceLink($directory, [...$environment, 'FAKE_PHP_CONFIG_STATUS' => '71'], false);
        same(71, $failed['status'], $failed['stdout'] . $failed['stderr']);
        same([false, false, false], [
            file_exists("{$directory}/cc.jsonl"),
            file_exists("{$directory}/cargo.jsonl"),
            file_exists("{$directory}/linked-marker"),
        ]);
    },
    'failed cargo link propagates without errexit and never exports success' => static function (): void {
        [$directory, $environment] = faultLinkFixture();
        same(0, sourceLink($directory, $environment)['status']);
        writeFile("{$directory}/build/debug/libphp_profiler.so", 'preexisting stale test-transport DSO bytes');
        unlink("{$directory}/linked-marker");
        file_put_contents("{$directory}/tests/native/bailout-fault.c", "new shim whose Cargo link refuses\n");
        $failed = sourceLink($directory, [...$environment, 'FAKE_CARGO_FAIL' => '79'], false);
        same(79, $failed['status'], $failed['stdout'] . $failed['stderr']);
        same(2, count(jsonLines("{$directory}/cargo.jsonl")));
        same(false, file_exists("{$directory}/linked-marker"));
        same(
            'preexisting stale test-transport DSO bytes',
            file_get_contents("{$directory}/build/debug/libphp_profiler.so"),
        );
    },
    'compiler failure cannot reuse an existing stale object or invoke cargo' => static function (): void {
        [$directory, $environment] = faultLinkFixture();
        same(0, sourceLink($directory, $environment)['status']);
        $priorCalls = jsonLines("{$directory}/cargo.jsonl");
        $priorObject = linkObject($directory, $priorCalls[0]);
        $priorBytes = file_get_contents($priorObject);
        same(true, file_exists("{$directory}/build/bailout-fault.o"));
        unlink("{$directory}/linked-marker");
        file_put_contents("{$directory}/tests/native/bailout-fault.c", "input that compiler refuses\n");
        $failed = sourceLink($directory, [...$environment, 'FAKE_CC_FAIL' => '1'], false);
        same(73, $failed['status'], $failed['stdout'] . $failed['stderr']);
        same(true, str_contains($failed['stderr'], 'intentional compiler transport failure'));
        same($priorCalls, jsonLines("{$directory}/cargo.jsonl"));
        same($priorBytes, file_get_contents($priorObject));
        same(false, file_exists("{$directory}/linked-marker"));
    },
];
