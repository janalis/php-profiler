<?php

declare(strict_types=1);

namespace PhpProfiler\Tooling;

use FFI;
use InvalidArgumentException;
use RuntimeException;

final readonly class ProcessResult
{
    /** @param list<string> $command */
    public function __construct(
        public array $command,
        public int $status,
        public ?string $stdout,
        public ?string $stderr,
    ) {}
}

final class ProcessFailed extends RuntimeException
{
    public function __construct(
        public readonly ProcessResult $result,
    ) {
        parent::__construct('command exited with status ' . $result->status);
    }
}

final class ProcessTimedOut extends RuntimeException
{
    /** @param list<string> $command */
    public function __construct(
        public readonly array $command,
        public readonly float $timeout,
        public readonly ?string $stdout,
        public readonly ?string $stderr,
    ) {
        parent::__construct('command timed out after ' . $timeout . ' seconds');
    }
}

/**
 * Bounded test subprocesses; kills and reaps only groups created by this owner.
 *
 * The child stays unreaped until its group is retired (WNOWAIT), so its PID and
 * group cannot be recycled underneath us. On Linux a child-subreaper scope also
 * reaps orphaned grandchildren of an owned group. Development infrastructure only.
 */
final class ProcessGroups
{
    private const int P_PID = 1;
    private const int WEXITED = 4;
    private const int PR_SET_CHILD_SUBREAPER = 36;
    private const int PR_GET_CHILD_SUBREAPER = 37;
    private const int ESRCH = 3;
    private const int EPERM = 1;
    private const int ECHILD = 10;
    private const int POLL_MICROSECONDS = 5000;
    private const float RETIRE_SECONDS = 5.0;

    private FFI $libc;
    private ?FFI $linux = null;
    private int $waitNoWait;
    private int $owner;
    /** @var array<int, true> */
    private array $active = [];
    /** @var array<int, int> */
    private array $statuses = [];
    private bool $closed = false;
    private bool $spawning = false;
    private ?int $pendingSignal = null;
    /** @var array<int, mixed> */
    private array $originalHandlers = [];
    private bool $originalAsyncSignals;
    private ?int $previousSubreaper = null;

    public function __construct()
    {
        $this->owner = posix_getpid();
        $this->libc = FFI::cdef(<<<'C'
            int waitid(int idtype, unsigned int id, void *info, int options);
            int chdir(const char *path);
            int dup2(int from, int to);
            int close(int descriptor);
            int open(const char *path, int flags, ...);
            void _exit(int status);
            C);
        $this->waitNoWait = PHP_OS_FAMILY === 'Darwin' ? 0x20 : 0x01000000;
        if (PHP_OS_FAMILY === 'Linux') {
            // glibc declares prctl variadic: one declaration serves the pointer and integer calls.
            $this->linux = FFI::cdef('int prctl(int option, ...);');
            $previous = $this->linux->new('int');
            if ($this->linux->prctl(self::PR_GET_CHILD_SUBREAPER, FFI::addr($previous), 0, 0, 0) !== 0) {
                throw new RuntimeException('read child-subreaper scope');
            }
            $this->setSubreaper(1, 'establish child-subreaper scope');
            $this->previousSubreaper = $previous->cdata;
        }
        $this->originalAsyncSignals = pcntl_async_signals(true);
        foreach ([SIGTERM, SIGINT] as $signal) {
            $this->originalHandlers[$signal] = pcntl_signal_get_handler($signal);
            pcntl_signal($signal, $this->handleSignal(...));
        }
        register_shutdown_function($this->close(...));
    }

    /**
     * @param list<string> $command
     * @param array<string, string>|null $environment
     */
    public function run(
        array $command,
        float $timeout,
        ?array $environment = null,
        ?string $directory = null,
        bool $capture = false,
        bool $check = false,
    ): ProcessResult {
        if ($this->closed || $timeout <= 0 || $command === []) {
            throw new InvalidArgumentException('open process scope, command and positive timeout required');
        }
        $executable = self::resolve($command[0], $environment);
        if ($directory !== null && !is_dir($directory)) {
            throw new RuntimeException('working directory does not exist: ' . $directory);
        }
        $output = $capture ? self::temporaryFile() : null;
        $errors = $capture ? self::temporaryFile() : null;
        try {
            $pid = $this->spawn($executable, $command, $environment, $directory, $output, $errors);
            if ($this->pendingSignal !== null) {
                $this->handleSignal($this->pendingSignal);
            }
            $expired = false;
            $deadline = hrtime(true) + (int) ($timeout * 1e9);
            try {
                while (!$this->hasExited($pid)) {
                    if (hrtime(true) >= $deadline) {
                        $expired = true;
                        break;
                    }
                    usleep(self::POLL_MICROSECONDS);
                }
            } finally {
                $this->retire($pid);
                // Drop the reaped status on every path, so a reused PID never finds it.
                $status = $this->statuses[$pid] ?? null;
                unset($this->statuses[$pid]);
            }
            $stdout = $output === null ? null : (string) file_get_contents($output);
            $stderr = $errors === null ? null : (string) file_get_contents($errors);
            if ($expired) {
                throw new ProcessTimedOut($command, $timeout, $stdout, $stderr);
            }
            $result = new ProcessResult($command, (int) $status, $stdout, $stderr);
            if ($check && $result->status !== 0) {
                throw new ProcessFailed($result);
            }
            return $result;
        } finally {
            foreach ([$output, $errors] as $path) {
                if ($path !== null) {
                    unlink($path);
                }
            }
        }
    }

    public function close(): void
    {
        if ($this->closed || posix_getpid() !== $this->owner) {
            return;
        }
        foreach (array_keys($this->active) as $pid) {
            $this->retire($pid);
        }
        foreach ($this->originalHandlers as $signal => $handler) {
            pcntl_signal($signal, $handler);
        }
        pcntl_async_signals($this->originalAsyncSignals);
        if ($this->previousSubreaper !== null) {
            $this->setSubreaper($this->previousSubreaper, 'restore child-subreaper scope');
        }
        $this->closed = true;
    }

    private function setSubreaper(int $value, string $action): void
    {
        if ($this->linux?->prctl(self::PR_SET_CHILD_SUBREAPER, $value, 0, 0, 0) !== 0) {
            throw new RuntimeException($action);
        }
    }

    private function handleSignal(int $signal): void
    {
        if (posix_getpid() !== $this->owner) {
            $this->libc->_exit(128 + $signal);
        }
        // Defer until the new PID is recorded; masking around fork would alter the child's signals.
        if ($this->spawning) {
            $this->pendingSignal = $signal;
            return;
        }
        $this->close();
        exit(128 + $signal);
    }

    /**
     * @param list<string> $command
     * @param array<string, string>|null $environment
     */
    private function spawn(
        string $executable,
        array $command,
        ?array $environment,
        ?string $directory,
        ?string $output,
        ?string $errors,
    ): int {
        $ready = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($ready === false) {
            throw new RuntimeException('create process start channel');
        }
        $this->spawning = true;
        try {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('fork test process');
            }
            if ($pid === 0) {
                $this->execute($executable, $command, $environment, $directory, $output, $errors, $ready);
            }
            $this->active[$pid] = true;
        } finally {
            $this->spawning = false;
        }
        fclose($ready[1]);
        // The group must exist before anything may signal it.
        $started = fread($ready[0], 1);
        fclose($ready[0]);
        if ($started !== '1') {
            $this->retire($pid);
            throw new RuntimeException('test process failed before starting');
        }
        return $pid;
    }

    /**
     * @param list<string> $command
     * @param array<string, string>|null $environment
     * @param array{resource, resource} $ready
     */
    private function execute(
        string $executable,
        array $command,
        ?array $environment,
        ?string $directory,
        ?string $output,
        ?string $errors,
        array $ready,
    ): never {
        pcntl_async_signals(false);
        foreach ([SIGTERM, SIGINT] as $signal) {
            pcntl_signal($signal, SIG_DFL);
        }
        // PHP's chdir() is virtual on ZTS builds and would not reach the executed program.
        $usable = posix_setsid() !== -1 && ($directory === null || $this->libc->chdir($directory) === 0);
        foreach ([1 => $output, 2 => $errors] as $target => $path) {
            if ($path !== null) {
                $descriptor = $this->libc->open($path, 1);
                $usable = $usable && $descriptor >= 0 && $this->libc->dup2($descriptor, $target) === $target;
            }
        }
        if ($usable) {
            fwrite($ready[1], '1');
        }
        fclose($ready[0]);
        fclose($ready[1]);
        if ($usable) {
            for ($descriptor = 3; $descriptor < 256; $descriptor++) {
                $this->libc->close($descriptor);
            }
            $arguments = array_slice($command, 1);
            if ($environment === null) {
                pcntl_exec($executable, $arguments);
            } else {
                pcntl_exec($executable, $arguments, $environment);
            }
        }
        $this->libc->_exit(127);
    }

    private function hasExited(int $pid): bool
    {
        $information = $this->libc->new('char[512]');
        $options = self::WEXITED | WNOHANG | $this->waitNoWait;
        if ($this->libc->waitid(self::P_PID, $pid, FFI::addr($information), $options) !== 0) {
            throw new RuntimeException('observe owned test process');
        }
        // POSIX leaves si_signo zero when WNOHANG finds no state change; its offset is portable.
        return $this->libc->cast('int *', FFI::addr($information))[0] !== 0;
    }

    private function retire(int $pid): void
    {
        if (!isset($this->active[$pid])) {
            return;
        }
        if (!posix_kill(-$pid, SIGKILL)) {
            $this->acceptKillFailure($pid, posix_get_last_error());
        }
        $this->reapLeader($pid);
        if ($this->linux !== null) {
            $this->reapDescendants($pid);
        }
        unset($this->active[$pid]);
    }

    private function acceptKillFailure(int $pid, int $error): void
    {
        if ($error === self::ESRCH) {
            return;
        }
        // Darwin reports EPERM for an unreaped group containing only zombies. Accept only a
        // bounded query of this owned group's actual state; a live member stays fatal.
        if ($error === self::EPERM && PHP_OS_FAMILY === 'Darwin') {
            exec('ps -g ' . $pid . ' -o stat= 2>/dev/null', $states, $status);
            $live = array_filter($states, static fn(string $state): bool => !str_starts_with(trim($state), 'Z'));
            if (in_array($status, [0, 1], true) && $live === []) {
                return;
            }
        }
        throw new RuntimeException('retire owned process group: ' . posix_strerror($error));
    }

    private function reapLeader(int $pid): void
    {
        $deadline = hrtime(true) + (int) (self::RETIRE_SECONDS * 1e9);
        while (true) {
            $reaped = pcntl_waitpid($pid, $status, WNOHANG);
            if ($reaped === $pid) {
                $this->statuses[$pid] = pcntl_wifsignaled($status)
                    ? -pcntl_wtermsig($status)
                    : pcntl_wexitstatus($status);
                return;
            }
            if ($reaped === -1 || hrtime(true) >= $deadline) {
                throw new RuntimeException('owned process did not retire after SIGKILL');
            }
            usleep(self::POLL_MICROSECONDS);
        }
    }

    private function reapDescendants(int $pid): void
    {
        $deadline = hrtime(true) + (int) (self::RETIRE_SECONDS * 1e9);
        while (true) {
            $reaped = pcntl_waitpid(-$pid, $status, WNOHANG);
            if ($reaped === -1) {
                if (pcntl_get_last_error() !== self::ECHILD) {
                    throw new RuntimeException('reap owned descendants');
                }
                return;
            }
            if ($reaped === 0) {
                if (hrtime(true) >= $deadline) {
                    throw new RuntimeException('owned descendant did not retire after SIGKILL');
                }
                usleep(self::POLL_MICROSECONDS);
            }
        }
    }

    /** @param array<string, string>|null $environment */
    private static function resolve(string $program, ?array $environment): string
    {
        $candidates = str_contains($program, '/')
            ? [$program]
            : array_map(
                static fn(string $path): string => ($path === '' ? '.' : $path) . '/' . $program,
                explode(':', $environment['PATH'] ?? (string) getenv('PATH')),
            );
        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        throw new RuntimeException('executable not found: ' . $program);
    }

    private static function temporaryFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'php-profiler-process-');
        if ($path === false) {
            throw new RuntimeException('create process capture file');
        }
        return $path;
    }
}
