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
    public static function run(array $command, ?array $environment = null, ?string $directory = null): array
    {
        $output = tmpfile();
        $errors = tmpfile();
        if ($output === false || $errors === false) {
            throw new RuntimeException('create process capture files');
        }
        $process = proc_open(
            $command,
            [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $errors],
            $pipes,
            $directory,
            $environment,
        );
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
     * @param list<string> $command
     * @param array<string, string>|null $environment
     */
    public static function output(array $command, ?array $environment = null): string
    {
        $result = self::run($command, $environment);
        if ($result['status'] !== 0) {
            throw new RuntimeException(
                $command[0] . ' exited with status ' . $result['status'] . ': ' . $result['stderr'],
            );
        }
        return $result['stdout'];
    }
}
