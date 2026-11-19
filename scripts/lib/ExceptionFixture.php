<?php

declare(strict_types=1);

namespace PhpProfiler\Tooling;

require_once __DIR__ . '/Paths.php';
require_once __DIR__ . '/Process.php';
require_once __DIR__ . '/Shlex.php';

/** Builds or locates the test-only exception ownership extension. */
final class ExceptionFixture
{
    public static function path(): string
    {
        $root = Paths::resolve(dirname(__DIR__, 2));
        $configured = getenv('PROFILER_FIXTURE_EXTENSION');
        if (is_string($configured) && $configured !== '') {
            return Paths::resolve($configured);
        }
        $target = ((string) getenv('CARGO_TARGET_DIR') ?: "{$root}/target") . '/exception-fixture';
        $cargo = ['cargo'];
        if ((string) getenv('PROFILER_VENDOR_TOOLCHAIN') !== '') {
            $cargo[] = '+' . getenv('PROFILER_VENDOR_TOOLCHAIN');
        }
        Process::check(
            [
                ...$cargo,
                'build',
                '--manifest-path',
                'tests/support/exception-fixture/Cargo.toml',
                '--target-dir',
                $target,
            ],
            Process::environment(),
            $root,
        );
        $suffix = PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so';
        return Paths::resolve("{$target}/debug/libprofiler_exception_fixture.{$suffix}");
    }

    /**
     * On macOS, builds a preloadable interposer for an allocation fault; ELF builds
     * wrap the fixture's own references at link time instead.
     *
     * @return array<string, string> environment additions
     */
    public static function interposer(string $source, string $directory): array
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            return [];
        }
        $includes = Shlex::split(Process::output([(string) getenv('PHP_CONFIG') ?: 'php-config', '--includes']));
        $library = $directory . '/' . pathinfo($source, PATHINFO_FILENAME) . '.dylib';
        Process::check([
            ...Shlex::split((string) getenv('CC') ?: 'cc'),
            ...$includes,
            '-dynamiclib',
            '-undefined',
            'dynamic_lookup',
            $source,
            '-o',
            $library,
        ]);
        return ['DYLD_INSERT_LIBRARIES' => $library];
    }

    public static function temporaryDirectory(string $prefix): string
    {
        $path = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(6));
        mkdir($path, 0o700) || throw new \RuntimeException('create ' . $path);
        register_shutdown_function(static fn() => Paths::removeTree($path));
        return $path;
    }

    public static function fail(string $message): never
    {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}
