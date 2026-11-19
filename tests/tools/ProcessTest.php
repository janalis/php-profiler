<?php

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use PhpProfiler\Tooling\Process;
use PhpProfiler\Tooling\ProcessFailedStatus;

return [
    'status streams and input are kept apart' => static function (): void {
        $result = Process::run(['sh', '-c', 'cat; printf err >&2; exit 3'], input: 'in');
        same([3, 'in', 'err'], [$result['status'], $result['stdout'], $result['stderr']]);
    },
    'merged errors interleave into standard output' => static function (): void {
        $result = Process::run(['sh', '-c', 'printf a; printf b >&2; printf c'], mergeErrors: true);
        same(['abc', ''], [$result['stdout'], $result['stderr']]);
    },
    'a signal death is a negative status' => static function (): void {
        same(-SIGTERM, Process::run(['sh', '-c', 'kill -TERM $$'])['status']);
    },
    'a timeout kills the child and keeps partial output' => static function (): void {
        $started = hrtime(true);
        $result = Process::run(['sh', '-c', 'printf partial; exec sleep 30'], timeout: 0.5);
        same([null, 'partial'], [$result['status'], $result['stdout']]);
        same(true, (hrtime(true) - $started) < 5_000_000_000);
    },
    'check enforces its timeout and status' => static function (): void {
        throws(RuntimeException::class, static fn() => Process::check(['sleep', '30'], timeout: 0.3));
        same(4, throws(ProcessFailedStatus::class, static fn() => Process::check(['sh', '-c', 'exit 4']))->status);
        Process::check(['true']);
    },
    'checked children append to a redirected log instead of overwriting it' => static function (): void {
        $directory = temporaryDirectory();
        writeFile(
            "{$directory}/driver.php",
            '<?php require '
            . var_export(ROOT . '/scripts/lib/Process.php', true)
            . '; echo "one\\n"; '
            . 'PhpProfiler\\Tooling\\Process::check(["sh", "-c", "echo two; echo three >&2"]); fwrite(STDERR, "four\\n"); '
            . 'PhpProfiler\\Tooling\\Process::check(["sh", "-c", "echo five"]); echo "six\\n";',
        );
        $result = Process::run([
            'sh',
            '-c',
            'php -n "$1" > "$2" 2>&1',
            'driver',
            "{$directory}/driver.php",
            "{$directory}/log",
        ]);
        same(0, $result['status'], $result['stderr']);
        same("one\ntwo\nthree\nfour\nfive\nsix\n", file_get_contents("{$directory}/log"));
    },
    'check output refuses a failed command' => static function (): void {
        same(9, throws(ProcessFailedStatus::class, static fn() => Process::output(['sh', '-c', 'exit 9']))->status);
    },
    'environment keeps names that getenv() would rewrite' => static function (): void {
        // Mull switches a mutant on with a variable named after its source location.
        // The probes print the environment without a shell: dash itself drops such names.
        $name = 'cxx_mutant:/source/src/sampling.c:140:5 x';
        $inherited = static fn(string $output): bool => in_array($name . '=1', explode("\n", $output), true);
        $launched = Process::run([
            '/usr/bin/env',
            $name . '=1',
            ROOT . '/scripts/php-tool.sh',
            '-r',
            sprintf(
                'require %s; $e = PhpProfiler\\Tooling\\Process::environment(); echo $e[%s] ?? "missing", "\n", PhpProfiler\\Tooling\\Process::run(["/usr/bin/env"], $e)["stdout"];',
                var_export(ROOT . '/scripts/lib/Process.php', true),
                var_export($name, true),
            ),
        ]);
        same('1', strstr($launched['stdout'], "\n", true), 'environment() lost the switch: ' . $launched['stderr']);
        same(true, $inherited($launched['stdout']), 'a child built from environment() lost the switch');
        $owned = Process::run([
            '/usr/bin/env',
            $name . '=1',
            ROOT . '/scripts/php-tool.sh',
            ROOT . '/scripts/owned-run.php',
            '5',
            '/usr/bin/env',
        ]);
        same(0, $owned['status'], $owned['stderr']);
        same(true, $inherited($owned['stdout']), 'owned run dropped the mutant switch');
    },
    'tooling never reads the whole environment through getenv()' => static function (): void {
        $offenders = [];
        $files = [
            ...glob(ROOT . '/scripts/*.php'),
            ...glob(ROOT . '/scripts/lib/*.php'),
            ...glob(ROOT . '/tests/tools/*.php'),
        ];
        // The tokenizer is a shared module that `php -n` may not load. Instead, string literals
        // become "x" runs, so getenv('NAME') keeps an argument, and comments become spaces.
        // Line breaks stay, so reported lines remain accurate.
        $literals = '~\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"|/\*.*?\*/|(?://|#(?!\[))[^\n]*~s';
        foreach ($files as $file) {
            $code = (string) preg_replace_callback(
                $literals,
                static fn(array $match): string => (string) preg_replace(
                    '/[^\n]/',
                    in_array($match[0][0], ['\'', '"'], true) ? 'x' : ' ',
                    $match[0],
                ),
                (string) file_get_contents($file),
            );
            preg_match_all('/(?<![\w$>:])\\\\?getenv\s*\(\s*\)/i', $code, $calls, PREG_OFFSET_CAPTURE);
            foreach ($calls[0] as [, $offset]) {
                $offenders[] = basename($file) . ':' . (substr_count($code, "\n", 0, $offset) + 1);
            }
        }
        same([], $offenders, 'use Process::environment():');
    },
];
