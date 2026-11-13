<?php

declare(strict_types=1);

use PhpProfiler\Profiler;

$directory = sys_get_temp_dir() . '/php-profiler-anonymous-source-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
// Linux accepts arbitrary filename bytes; macOS requires valid Unicode filenames.
$suffixes = PHP_OS_FAMILY === 'Linux' ? ["\x80", "\x81"] : ['é', 'ö'];
$paths = [
    $directory . '/private_source_marker_' . $suffixes[0] . '.php',
    $directory . '/private_source_marker_' . $suffixes[1] . '.php',
];
$source = '<?php return new class {public function anonymous_byte_leaf():int {usleep(1); return 7;}};';
try {
    foreach ($paths as $path) {
        if (file_put_contents($path, $source) === false) {
            throw new RuntimeException('cannot create source filename fixture');
        }
    }
    $one = require $paths[0];
    $two = require $paths[1];
    $session = Profiler::start();
    if ($one->anonymous_byte_leaf() !== 7 || $two->anonymous_byte_leaf() !== 7) {
        throw new RuntimeException('anonymous class execution changed');
    }
    $session->stop();
    $report = $session->getReport();
    $methods = array_filter(
        $report['functions'],
        static fn(array $metrics, string $name): bool => str_ends_with($name, '::anonymous_byte_leaf'),
        ARRAY_FILTER_USE_BOTH,
    );
    if (count($methods) !== 2 || array_column(array_values($methods), 'calls') !== [1, 1]) {
        throw new RuntimeException('anonymous classes from distinct filename bytes collapsed');
    }
    foreach (array_keys($methods) as $name) {
        if (!str_starts_with($name, 'anonymous-class#')) {
            throw new RuntimeException('anonymous class source was encoded instead of hidden');
        }
    }
    $json = $session->getReportJson();
    foreach ([
        'private_source_marker_',
        bin2hex('private_source_marker_'),
        $directory,
        bin2hex($directory),
    ] as $private) {
        if (str_contains($json, $private)) {
            throw new RuntimeException('anonymous class source path leaked in plaintext or hex');
        }
    }
    Profiler::resetAll();
    Profiler::disable();
    echo "anonymous class source filename bytes remain private and distinct passed\n";
} finally {
    foreach ($paths as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    rmdir($directory);
}
