<?php

declare(strict_types=1);

use PhpProfiler\Tooling\ProcessFailed;
use PhpProfiler\Tooling\ProcessGroups;
use PhpProfiler\Tooling\ProcessTimedOut;

require_once dirname(__DIR__, 2) . '/scripts/lib/ProcessGroups.php';

const FAMILY = 'sleep 60 & printf "%s %s" "$$" "$!" > "$1.partial"; mv "$1.partial" "$1"; wait';

function assertDead(string $path): void
{
    same(true, is_file($path), 'real family was never started:');
    foreach (array_map(intval(...), explode(' ', (string) file_get_contents($path))) as $pid) {
        $deadline = hrtime(true) + 5_000_000_000;
        while (posix_kill($pid, 0)) {
            same(true, hrtime(true) < $deadline, 'owned process survived cleanup ' . $pid . ':');
            usleep(10_000);
        }
    }
}

function temporaryPath(): string
{
    $path = tempnam(sys_get_temp_dir(), 'php-profiler-family-');
    unlink($path);
    return $path;
}

return [
    'capture and nonzero status are preserved' => static function (): void {
        $runner = new ProcessGroups();
        $result = $runner->run(['sh', '-c', 'printf out; printf err >&2; exit 7'], 5, capture: true);
        same([7, 'out', 'err'], [$result->status, $result->stdout, $result->stderr]);
        $failure = throws(ProcessFailed::class, static fn() => $runner->run(['sh', '-c', 'exit 9'], 5, check: true));
        same(9, $failure->result->status);
        $runner->close();
    },
    'environment and directory reach the child' => static function (): void {
        $runner = new ProcessGroups();
        $directory = realpath(sys_get_temp_dir());
        $result = $runner->run(
            ['/bin/sh', '-c', 'printf "%s %s" "$MARK" "$(pwd -P)"'],
            5,
            ['MARK' => 'seen'],
            $directory,
            true,
        );
        same('seen ' . $directory, $result->stdout);
        $runner->close();
    },
    'a child killed by a signal reports it' => static function (): void {
        $runner = new ProcessGroups();
        same(-SIGKILL, $runner->run(['sh', '-c', 'kill -KILL $$'], 5)->status);
        $runner->close();
    },
    'descriptors of the owner do not reach the child' => static function (): void {
        $runner = new ProcessGroups();
        $held = fopen(__FILE__, 'r');
        $result = $runner->run(['sh', '-c', 'ls /dev/fd | wc -l'], 5, capture: true);
        fclose($held);
        same(true, (int) trim($result->stdout) <= 5, 'inherited descriptors: ' . $result->stdout);
        $runner->close();
    },
    'a missing executable fails before starting' => static function (): void {
        $runner = new ProcessGroups();
        throws(RuntimeException::class, static fn() => $runner->run(['/nonexistent/tool'], 5));
        $runner->close();
    },
    'timeout kills and reaps child and grandchild' => static function (): void {
        $path = temporaryPath();
        $runner = new ProcessGroups();
        throws(ProcessTimedOut::class, static fn() => $runner->run(['sh', '-c', FAMILY, 'family', $path], 2));
        throws(ProcessFailed::class, static fn() => $runner->run(['sh', '-c', 'exit 3'], 5, check: true));
        same([], (new ReflectionProperty(ProcessGroups::class, 'statuses'))->getValue($runner), 'stale statuses:');
        $runner->close();
        assertDead($path);
        unlink($path);
    },
    'signal cleanup owns real descendants' => static function (): void {
        $path = temporaryPath();
        $helper = sprintf(
            'require %s; (new PhpProfiler\Tooling\ProcessGroups())->run(["sh", "-c", %s, "family", %s], 60);',
            var_export(dirname(__DIR__, 2) . '/scripts/lib/ProcessGroups.php', true),
            var_export(FAMILY, true),
            var_export($path, true),
        );
        $process = proc_open([dirname(__DIR__, 2) . '/scripts/php-tool.sh', '-r', $helper], [], $pipes);
        $deadline = hrtime(true) + 10_000_000_000;
        while (!is_file($path)) {
            same(true, hrtime(true) < $deadline, 'real signal-test family was never started:');
            usleep(10_000);
        }
        proc_terminate($process, SIGTERM);
        do {
            $status = proc_get_status($process);
            usleep(10_000);
        } while ($status['running']);
        proc_close($process);
        same(128 + SIGTERM, $status['exitcode']);
        assertDead($path);
        unlink($path);
    },
];
