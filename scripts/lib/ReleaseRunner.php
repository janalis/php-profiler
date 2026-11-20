<?php

declare(strict_types=1);

namespace PhpProfiler\Tooling;

require_once __DIR__ . '/Process.php';

/** The commands, downloads and clock the release scripts use, so tests can replace them. */
interface ReleaseRunner
{
    /** Like subprocess.check_output(): standard output, failing on a non-zero status. */
    public function output(array $command, ?string $directory = null): string;

    /** Like subprocess.run() with inherited streams: the exit status. */
    public function run(array $command, ?string $directory = null): int;

    public function fetch(string $url): string;

    public function sleep(int $seconds): void;

    public function timeNs(): int;
}

final class SystemReleaseRunner implements ReleaseRunner
{
    public function output(array $command, ?string $directory = null): string
    {
        $result = Process::run($command, directory: $directory);
        if ($result['status'] !== 0) {
            throw new ProcessFailedStatus($command, $result['status'] ?? -1, $result['stderr']);
        }
        return $result['stdout'];
    }

    public function run(array $command, ?string $directory = null): int
    {
        $process = proc_open($command, [], $pipes, $directory);
        if ($process === false) {
            throw new \RuntimeException('start ' . $command[0]);
        }
        return proc_close($process);
    }

    /** Downloads through curl, which follows redirects and needs no PHP extension. */
    public function fetch(string $url): string
    {
        return $this->output(['curl', '--fail', '--silent', '--show-error', '--location', '--max-time', '30', $url]);
    }

    public function sleep(int $seconds): void
    {
        sleep($seconds);
    }

    public function timeNs(): int
    {
        return (int) (microtime(true) * 1e9);
    }
}

/** Release checks shared by the automation that opens a release and the job that publishes it. */
final class ReleaseChecks
{
    /** Runs $command with inherited streams, failing on a non-zero status. */
    public static function check(ReleaseRunner $runner, array $command, ?string $directory = null): void
    {
        $status = $runner->run($command, $directory);
        if ($status !== 0) {
            throw new ProcessFailedStatus($command, $status, '');
        }
    }

    /**
     * Whether $run is a successful quality run of exactly $sha in $repository, started by a push
     * or a dispatch: never by a pull request, whose code may come from elsewhere.
     */
    public static function exactQualityRun(array $run, string $sha, string $repository): bool
    {
        return ($run['name'] ?? null) === 'quality'
        && ($run['conclusion'] ?? null) === 'success'
        && ($run['head_sha'] ?? null) === $sha
        && ($run['head_repository']['full_name'] ?? null) === $repository
        && in_array($run['event'] ?? null, ['push', 'workflow_dispatch'], true);
    }
}
