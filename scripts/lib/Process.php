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
        $deadline = $timeout === null ? null : hrtime(true) + (int) ($timeout * 1e9);
        $status = null;
        while (true) {
            $state = proc_get_status($process);
            if (!$state['running']) {
                $status = $state['signaled'] ? -$state['termsig'] : $state['exitcode'];
                break;
            }
            if ($deadline !== null && hrtime(true) >= $deadline) {
                proc_terminate($process, SIGKILL);
                break;
            }
            usleep(2000);
        }
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
    public static function check(array $command, ?array $environment = null, ?string $directory = null): void
    {
        $process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $directory, $environment);
        if ($process === false) {
            throw new RuntimeException('start ' . $command[0]);
        }
        $status = proc_close($process);
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
