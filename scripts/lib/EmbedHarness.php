<?php

declare(strict_types=1);

namespace PhpProfiler\Tooling;

require_once __DIR__ . '/Fail.php';
require_once __DIR__ . '/Process.php';
require_once __DIR__ . '/Shlex.php';

/** Builds the pthread embed harness in tests/native/zts_threads.c against the PHP SDK. */
final class EmbedHarness
{
    public static function library(): string
    {
        $config = (string) getenv('PHP_CONFIG') ?: 'php-config';
        $prefix = trim(Process::output([$config, '--prefix']));
        $suffix = PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so';
        return (string) getenv('PROFILER_EMBED_LIBRARY') ?: "{$prefix}/lib/libphp.{$suffix}";
    }

    public static function build(string $library, string $executable): void
    {
        $includes = Shlex::split(Process::output([(string) getenv('PHP_CONFIG') ?: 'php-config', '--includes']));
        $compiler = Shlex::split((string) getenv('CC') ?: 'cc');
        Process::check([
            ...$compiler,
            '-std=c11',
            ...$includes,
            'tests/native/zts_threads.c',
            $library,
            '-lpthread',
            '-Wl,-rpath,' . dirname($library),
            '-o',
            $executable,
        ]);
    }

    /** Runs the harness with no host INI scan directory, failing on a non-zero status. */
    public static function run(array $command): void
    {
        $environment = getenv();
        $environment['PHP_INI_SCAN_DIR'] = '';
        $result = Process::run($command, $environment, timeout: 120);
        fwrite(STDOUT, $result['stdout']);
        fwrite(STDERR, $result['stderr']);
        if ($result['status'] !== 0) {
            fail('embed harness failed with status ' . json_encode($result['status']));
        }
    }

    public static function quoted(string $path): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $path);
    }
}
