<?php

declare(strict_types=1);

namespace PhpProfiler\Tooling;

use RuntimeException;

/** Runs a command from an argument list, with no shell, like Python's subprocess.run(). */
final class Process
{
    /**
     * Captures both streams in files, so no pipe can fill and stall the child. A
     * signal death is reported as the negative signal number, like Python does. On
     * timeout only the child is killed, again like Python, and status is null.
     *
     * @param list<string> $command
     * @param array<string, string>|null $environment
     * @return array{status: int|null, stdout: string, stderr: string}
     */
    public static function run(
        array $command,
        ?array $environment = null,
        ?string $directory = null,
        ?string $input = null,
        ?float $timeout = null,
        bool $mergeErrors = false,
    ): array {
        $output = tmpfile();
        $errors = $mergeErrors ? null : tmpfile();
        $source = $input === null ? ['file', '/dev/null', 'r'] : tmpfile();
        if ($output === false || $errors === false || $source === false) {
            throw new RuntimeException('create process capture files');
        }
        if ($input !== null) {
            fwrite($source, $input);
            rewind($source);
        }
        $descriptors = [0 => $source, 1 => $output, 2 => $errors ?? ['redirect', 1]];
        $process = proc_open($command, $descriptors, $pipes, $directory, $environment);
        if ($process === false) {
            throw new RuntimeException('start ' . $command[0]);
        }
        $status = self::wait($process, $timeout === null ? null : hrtime(true) + (int) ($timeout * 1e9));
        proc_close($process);
        rewind($output);
        $stdout = (string) stream_get_contents($output);
        $stderr = '';
        if ($errors !== null) {
            rewind($errors);
            $stderr = (string) stream_get_contents($errors);
        }
        return ['status' => $status, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /**
     * The exit status, or minus the signal that ended the process; null when $deadline (an
     * hrtime) passed and it was killed. Without a deadline, blocks in waitpid() instead of
     * polling; with one, polls at intervals growing from 1 to 50 ms.
     *
     * @param resource $process
     */
    private static function wait($process, ?int $deadline): ?int
    {
        $state = proc_get_status($process);
        if ($state['running'] && $deadline === null && function_exists('pcntl_waitpid')) {
            do {
                $reaped = pcntl_waitpid($state['pid'], $raw);
            } while ($reaped === -1 && pcntl_get_last_error() === PCNTL_EINTR);
            if ($reaped !== $state['pid']) {
                throw new RuntimeException(
                    'wait for process ' . $state['pid'] . ': ' . pcntl_strerror(pcntl_get_last_error()),
                );
            }
            return pcntl_wifsignaled($raw) ? -pcntl_wtermsig($raw) : pcntl_wexitstatus($raw);
        }
        $interval = 1000;
        while ($state['running']) {
            if ($deadline !== null && hrtime(true) >= $deadline) {
                proc_terminate($process, SIGKILL);
                return null;
            }
            usleep($interval);
            $interval = min($interval * 2, 50_000);
            $state = proc_get_status($process);
        }
        return $state['signaled'] ? -$state['termsig'] : $state['exitcode'];
    }

    /**
     * The process environment exactly as children inherit it. getenv() without a name
     * cannot be used: it registers each name like a request variable and rewrites "."
     * and " " to "_", so Mull's mutant switches (named after "file.c:line:column") would
     * silently disappear from every child built from it. Read on each call, so putenv()
     * changes are included.
     *
     * @return array<string, string>
     */
    public static function environment(): array
    {
        $process = proc_open(['/usr/bin/env', '-0'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w']], $pipes);
        if ($process === false) {
            throw new RuntimeException('start /usr/bin/env');
        }
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        if (proc_close($process) !== 0 || $output === false) {
            throw new RuntimeException('read the process environment');
        }
        $environment = [];
        foreach (explode("\0", $output) as $entry) {
            $separator = strpos($entry, '=');
            if ($separator !== false && $separator > 0) {
                $environment[substr($entry, 0, $separator)] = substr($entry, $separator + 1);
            }
        }
        return $environment;
    }

    /**
     * The current environment for a PHP child under test, with the AddressSanitizer
     * runtime preloaded when PROFILER_ASAN_RUNTIME names one. The tooling's own PHP is
     * never instrumented.
     *
     * @return array<string, string>
     */
    public static function childEnvironment(): array
    {
        $environment = self::environment();
        if (($environment['PROFILER_ASAN_RUNTIME'] ?? '') !== '') {
            $environment[PHP_OS_FAMILY === 'Darwin' ? 'DYLD_INSERT_LIBRARIES' : 'LD_PRELOAD'] =
                $environment['PROFILER_ASAN_RUNTIME'];
        }
        return $environment;
    }

    /**
     * Like subprocess.check_output(): the standard output of a successful command.
     *
     * @param list<string> $command
     * @param array<string, string>|null $environment
     */
    public static function output(array $command, ?array $environment = null, ?string $input = null): string
    {
        $result = self::run($command, $environment, input: $input);
        if ($result['status'] !== 0) {
            throw new ProcessFailedStatus($command, $result['status'] ?? -1, $result['stderr']);
        }
        return $result['stdout'];
    }

    /**
     * Like subprocess.run(check=True) with inherited streams: fails on a non-zero status.
     *
     * @param list<string> $command
     * @param array<string, string>|null $environment
     */
    public static function check(
        array $command,
        ?array $environment = null,
        ?string $directory = null,
        ?float $timeout = null,
    ): void {
        // Unspecified descriptors are inherited as they are. Passing the STDOUT and STDERR
        // constants instead gives the child separate file offsets, so a redirected log
        // would be overwritten.
        $process = proc_open($command, [], $pipes, $directory, $environment);
        if ($process === false) {
            throw new RuntimeException('start ' . $command[0]);
        }
        $status = self::wait($process, $timeout === null ? null : hrtime(true) + (int) ($timeout * 1e9));
        proc_close($process);
        if ($status === null) {
            throw new RuntimeException($command[0] . " timed out after {$timeout} seconds");
        }
        if ($status !== 0) {
            throw new ProcessFailedStatus($command, $status, '');
        }
    }
}

final class ProcessFailedStatus extends RuntimeException
{
    /** @param list<string> $command */
    public function __construct(
        public readonly array $command,
        public readonly int $status,
        string $stderr,
    ) {
        parent::__construct(
            $command[0] . ' exited with status ' . $status . ($stderr === '' ? '' : ': ' . trim($stderr)),
        );
    }
}
