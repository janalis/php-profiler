<?php

declare(strict_types=1);

// GH-22158: real megamorphic JIT calls, exact counts and observer coexistence.
require_once __DIR__ . '/lib/Arguments.php';
require_once __DIR__ . '/lib/ExceptionFixture.php';
require_once __DIR__ . '/lib/Fail.php';
require_once __DIR__ . '/lib/Json.php';
require_once __DIR__ . '/lib/Paths.php';
require_once __DIR__ . '/lib/Process.php';
require_once __DIR__ . '/lib/Shlex.php';

use PhpProfiler\Tooling\Arguments;
use PhpProfiler\Tooling\ExceptionFixture;
use PhpProfiler\Tooling\Json;
use PhpProfiler\Tooling\Paths;
use PhpProfiler\Tooling\Process;
use PhpProfiler\Tooling\Shlex;

use function PhpProfiler\Tooling\fail;

$usage = 'jit-megamorphic-tests.php [--php <php>] [--php-config <php-config>] [--extension <path>] [--opcache-extension <name>] [--iterations <n>] [--out <path>]';
['options' => $options, 'positionals' => $positionals] = Arguments::parse(
    $argv,
    ['php', 'php-config', 'extension', 'opcache-extension', 'iterations', 'out'],
    usage: $usage,
);
$php = (string) ($options['php'] ?? 'php');
$phpConfig = (string) ($options['php-config'] ?? ((string) getenv('PHP_CONFIG') ?: 'php-config'));
$extensionOption = $options['extension'] ?? ((string) getenv('PROFILER_EXTENSION') ?: null);
$opcacheExtension = $options['opcache-extension'] ?? ((string) getenv('PROFILER_OPCACHE_EXTENSION') ?: null);
$iterations = (int) ($options['iterations'] ?? 100000);
$out = $options['out'] ?? null;
if ($positionals !== [] || $extensionOption === null) {
    Arguments::fail('--extension (or PROFILER_EXTENSION) is required', $usage);
}
if ($iterations < 40 || ($iterations % 5) !== 0) {
    Arguments::fail('iterations must be >=40 and divisible by five', $usage);
}
$root = Paths::resolve(dirname(__DIR__));
$fixture = "{$root}/tests/native/jit-megamorphic.php";
$helper = "{$root}/tests/support/jit-coobserver.c";
$extension = Paths::resolve($extensionOption);
$includes = Shlex::split(Process::output([$phpConfig, '--includes']));
if (
    $opcacheExtension === null
    && Process::run([$php, '-n', '-r', "exit(function_exists('opcache_get_status') ? 0 : 1);"])['status'] !== 0
) {
    $opcacheExtension = 'opcache';
}
$result = [
    'scope' => 'GH-22158 tracing JIT regression; genuine methods and independent coobserver; no runtime workaround',
    'extension' => $extension,
    'extension_sha256' => hash_file('sha256', $extension),
    'fixture_sha256' => hash_file('sha256', $fixture),
    'coobserver_source_sha256' => hash_file('sha256', $helper),
    'php_config_version' => trim(Process::output([$phpConfig, '--version'])),
    'cases' => [],
];
$save = static function () use (&$result, $out): void {
    if ($out !== null) {
        file_put_contents($out, Json::encode($result, indent: 2, sortKeys: false) . "\n");
    }
};
$directory = ExceptionFixture::temporaryDirectory('profiler-jit-coobserver-');
$darwin = PHP_OS_FAMILY === 'Darwin';
$other = $directory . ($darwin ? '/coobserver.dylib' : '/coobserver.so');
$flags = $darwin ? ['-dynamiclib', '-Wl,-undefined,dynamic_lookup'] : ['-shared', '-fPIC'];
Process::check([...Shlex::split((string) getenv('CC') ?: 'cc'), ...$flags, ...$includes, $helper, '-o', $other]);
$modes = [
    ['absent', false, null],
    ['other', false, 'before'],
    ['disabled', true, null],
    ['enabled', true, null],
    ['disabled', true, 'before'],
    ['enabled', true, 'before'],
    ['disabled', true, 'after'],
    ['enabled', true, 'after'],
];
foreach ($modes as [$mode, $loaded, $order]) {
    $command = [$php, '-n'];
    if ($opcacheExtension !== null) {
        array_push($command, '-d', 'zend_extension=' . $opcacheExtension);
    }
    array_push(
        $command,
        '-d',
        'opcache.enable=1',
        '-d',
        'opcache.enable_cli=1',
        '-d',
        'opcache.file_update_protection=0',
        '-d',
        'opcache.jit=1254',
        '-d',
        'opcache.jit_buffer_size=32M',
        '-d',
        'opcache.jit_hot_func=1',
        '-d',
        'opcache.jit_hot_loop=1',
        '-d',
        'opcache.jit_max_polymorphic_calls=2',
    );
    if ($order === 'before') {
        array_push($command, '-d', 'extension=' . $other);
    }
    if ($loaded) {
        array_push($command, '-d', 'extension=' . $extension);
    }
    if ($order === 'after') {
        array_push($command, '-d', 'extension=' . $other);
    }
    array_push($command, $fixture, $mode, (string) $iterations);
    $completed = Process::run($command, timeout: 120);
    $item = [
        'mode' => $mode,
        'profiler_loaded' => $loaded,
        'coobserver_order' => $order,
        'command' => $command,
        'exit' => $completed['status'],
        'stdout' => $completed['stdout'],
        'stderr' => $completed['stderr'],
    ];
    $result['cases'][] = $item;
    $save();
    if ($completed['status'] !== 0) {
        fail('megamorphic JIT subprocess failed: ' . Json::encode($item, sortKeys: false));
    }
    $item['receipt'] = json_decode($completed['stdout'], true, flags: JSON_THROW_ON_ERROR);
    if (($order === null) !== ($item['receipt']['other'] === null)) {
        fail('independent observer not loaded as requested');
    }
    $result['cases'][count($result['cases']) - 1] = $item;
    $save();
}
if (hash_file('sha256', $extension) !== $result['extension_sha256']) {
    fail('extension changed during proof');
}
$result['complete'] = true;
$save();
echo Json::encode($result, indent: 2, sortKeys: false), "\n";
