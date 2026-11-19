<?php

declare(strict_types=1);

namespace PhpProfiler\Tooling;

use RuntimeException;

/** Runs a command from an argument list, with no shell, and captures both streams. */
final class Process
{
    /**
     * @param list<string> $command
     * @param array<string, string>|null $environment
     * @return array{status: int, stdout: string, stderr: string}
     */
    public static function run(
        array $command,
        ?array $environment = null,
        ?string $directory = null,
        ?string $input = null,
    ): array {
        $output = tmpfile();
        $errors = tmpfile();
        $source = $input === null ? ['file', '/dev/null', 'r'] : tmpfile();
        if ($output === false || $errors === false || $source === false) {
            throw new RuntimeException('create process capture files');
        }
        if ($input !== null) {
            fwrite($source, $input);
            rewind($source);
        }
        $process = proc_open($command, [0 => $source, 1 => $output, 2 => $errors], $pipes, $directory, $environment);
        if ($process === false) {
            throw new RuntimeException('start ' . $command[0]);
        }
        $status = proc_close($process);
        rewind($output);
        rewind($errors);
        return [
            'status' => $status,
            'stdout' => (string) stream_get_contents($output),
            'stderr' => (string) stream_get_contents($errors),
        ];
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
            throw new ProcessFailedStatus($command, $result['status'], $result['stderr']);
        }
        return $result['stdout'];
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
