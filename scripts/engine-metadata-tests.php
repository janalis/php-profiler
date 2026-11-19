<?php

declare(strict_types=1);

// Tests arbitrary host SAPI names without adding a production configuration hook.
require_once __DIR__ . '/lib/ExceptionFixture.php';
require_once __DIR__ . '/lib/Fail.php';
require_once __DIR__ . '/lib/Paths.php';
require_once __DIR__ . '/lib/Process.php';
require_once __DIR__ . '/lib/Shlex.php';

use PhpProfiler\Tooling\ExceptionFixture;
use PhpProfiler\Tooling\Paths;
use PhpProfiler\Tooling\Process;
use PhpProfiler\Tooling\Shlex;

use function PhpProfiler\Tooling\fail;
use function PhpProfiler\Tooling\requiredEnvironment;

chdir(dirname(__DIR__));
$config = (string) getenv('PHP_CONFIG') ?: 'php-config';
$prefix = trim(Process::output([$config, '--prefix']));
$includes = Shlex::split(Process::output([$config, '--includes']));
$suffix = PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so';
$library = (string) getenv('PROFILER_EMBED_LIBRARY') ?: "{$prefix}/lib/libphp.{$suffix}";
if (!is_file($library)) {
    fail('PHP embed library required: ' . $library);
}
$extension = Paths::resolve(requiredEnvironment('PROFILER_EXTENSION'));
$directory = ExceptionFixture::temporaryDirectory('profiler-engine-metadata-');
$escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $extension);
file_put_contents(
    "{$directory}/php.ini",
    "extension=\"{$escaped}\"\nhtml_errors=0\ndisplay_errors=stderr\nlog_errors=0\n",
);
$executable = "{$directory}/engine_metadata";
Process::check([
    ...Shlex::split((string) getenv('CC') ?: 'cc'),
    ...$includes,
    'tests/native/engine-metadata.c',
    $library,
    '-Wl,-rpath,' . dirname($library),
    '-o',
    $executable,
]);
$environment = Process::environment();
$environment['PHP_INI_SCAN_DIR'] = '';
$result = Process::run([$executable, "{$directory}/php.ini"], $environment, timeout: 30);
fwrite(STDOUT, $result['stdout']);
fwrite(STDERR, $result['stderr']);
if ($result['status'] !== 0) {
    fail('engine metadata check failed with status ' . json_encode($result['status']));
}
