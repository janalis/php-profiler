<?php

declare(strict_types=1);

namespace PhpProfiler\Tooling;

use RuntimeException;

/** A long-running server process whose standard error is kept in a file. */
final class BackgroundProcess
{
    /** @var resource */
    private $process;
    private string $errors;
    private ?int $status = null;
    private bool $temporary;

    /**
     * @param list<string> $command
     * @param array<string, string> $environment
     * @param string|null $log the file for standard error, or a temporary one
     * @param bool $mergeOutput write standard output into the log too, otherwise discard it
     */
    public function __construct(
        array $command,
        array $environment,
        ?string $directory = null,
        ?string $log = null,
        bool $mergeOutput = false,
    ) {
        $this->errors = $log ?? (string) tempnam(sys_get_temp_dir(), 'php-profiler-server-');
        $this->temporary = $log === null;
        $descriptors = $mergeOutput
            ? [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->errors, 'a'], 2 => ['redirect', 1]]
            : [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $this->errors, 'a']];
        $this->process = proc_open($command, $descriptors, $pipes, $directory, $environment)
        ?: throw new RuntimeException('start ' . $command[0]);
    }

    public function running(): bool
    {
        if ($this->status !== null) {
            return false;
        }
        $state = proc_get_status($this->process);
        if (!$state['running']) {
            $this->status = $state['signaled'] ? -$state['termsig'] : $state['exitcode'];
        }
        return $state['running'];
    }

    /** Polls until $ready, failing if the process exits first or 10 seconds pass. */
    public function waitUntil(callable $ready, string $what): void
    {
        for ($attempt = 0; $attempt < 200; $attempt++) {
            if ($ready()) {
                return;
            }
            if (!$this->running()) {
                throw new RuntimeException("{$what} exited during startup:\n" . $this->log());
            }
            usleep(50_000);
        }
        throw new RuntimeException("{$what} startup timed out");
    }

    /**
     * Sends $signal, waits up to 10 seconds, then kills; returns the error log. With
     * $mustExit, a process that had to be killed is a failure, reported with its log.
     */
    public function stop(int $signal, bool $mustExit = false): string
    {
        $killed = false;
        if ($this->running()) {
            proc_terminate($this->process, $signal);
            $deadline = hrtime(true) + 10_000_000_000;
            while ($this->running() && hrtime(true) < $deadline) {
                usleep(10_000);
            }
            if ($this->running()) {
                $killed = true;
                proc_terminate($this->process, SIGKILL);
                while ($this->running()) {
                    usleep(10_000);
                }
            }
        }
        proc_close($this->process);
        $log = $this->log();
        if ($this->temporary) {
            @unlink($this->errors);
        }
        if ($killed && $mustExit) {
            throw new RuntimeException("process did not exit within 10 seconds of signal {$signal}:\n{$log}");
        }
        return $log;
    }

    public function log(): string
    {
        return (string) @file_get_contents($this->errors);
    }
}
