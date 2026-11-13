<?php

declare(strict_types=1);

use PhpProfiler\ConfigurationException;
use PhpProfiler\PersistenceException;
use PhpProfiler\Profiler;
use PhpProfiler\Session;
use PhpProfiler\StateException;

function controls_check(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

function controls_throws(string $type, Closure $operation): void
{
    try {
        $operation();
    } catch (Throwable $error) {
        controls_check($error instanceof $type, 'wrong exception type: ' . $error::class);
        return;
    }
    throw new RuntimeException('missing ' . $type);
}

function controls_start_inside(): Session
{
    return Profiler::start(null, ['timeline' => true]);
}

function controls_leaf(): void
{
    usleep(1);
}

$manifest = file_get_contents(__DIR__ . '/../../Cargo.toml');
controls_check($manifest !== false, 'workspace manifest missing');
controls_check(
    preg_match('/^\[workspace\.package\]\s*$(.*?)(?=^\[|\z)/ms', $manifest, $package) === 1,
    'workspace package missing',
);
controls_check(preg_match('/^version\s*=\s*"([^"]+)"\s*$/m', $package[1], $version) === 1, 'workspace version missing');
$expectedVersion = $version[1];
controls_check(phpversion('php_profiler') === $expectedVersion, 'extension version differs from workspace');

Profiler::disable();
controls_check(!Profiler::isEnabled(), 'disabled state missing');
Profiler::enable();
controls_check(Profiler::isEnabled(), 'enabled state missing');
Profiler::disable();
Profiler::resetAll();
$directory = sys_get_temp_dir() . '/php-profiler-controls-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
try {
    foreach ([['timeline' => 1], ['max_events' => '2'], ['max_events' => 0], ['max_events' => 8193]] as $options) {
        controls_throws(ConfigurationException::class, fn() => Profiler::start(null, $options));
    }
    controls_throws(ConfigurationException::class, fn() => Profiler::start(str_repeat('p', 4097)));
    controls_throws(ConfigurationException::class, fn() => Profiler::setOutputPath(str_repeat('p', 4097)));
    $pathSession = Profiler::start();
    controls_throws(ConfigurationException::class, fn() => $pathSession->setOutputPath(str_repeat('p', 4097)));
    $pathSession->reset();
    controls_throws(StateException::class, fn() => new Session());
    Profiler::setOutputPath($directory);
    $outer = Profiler::start();
    $inner = controls_start_inside();
    controls_leaf();
    $inner->stop();
    controls_throws(StateException::class, fn() => Profiler::getReport()); // outer still active
    $outer->stop();
    $innerReport = $inner->getReport();
    controls_check(
        $innerReport['unmatched_exits'] === 0 && $innerReport['status'] === 'complete',
        'mid-stack session boundary',
    );
    controls_check($innerReport['runtime_metadata']['php_version'] === PHP_VERSION, 'PHP version missing');
    controls_check($innerReport['runtime_metadata']['sapi'] === PHP_SAPI, 'SAPI missing');
    controls_check(
        $innerReport['runtime_metadata']['profiler_version'] === $expectedVersion,
        'report version differs from workspace',
    );
    controls_check($innerReport['runtime_metadata']['zts'] === (PHP_ZTS ? 'true' : 'false'), 'ZTS metadata differs');
    controls_check($outer->getReportPath() !== $inner->getReportPath(), 'directory report filenames collide');
    controls_check(
        realpath(dirname($outer->getReportPath())) === realpath($directory) && is_file($outer->getReportPath()),
        'directory output missing',
    );
    controls_throws(StateException::class, fn() => $outer->setOutputPath(null));
    Profiler::reset(); // latest completed inner
    controls_throws(StateException::class, fn() => $inner->getReport());
    $outer->reset();
    Profiler::setOutputPath(null);
    $limited = Profiler::start(null, ['max_events' => 1]);
    controls_leaf();
    controls_leaf();
    $limited->stop();
    controls_check($limited->getReport()['status'] === 'truncated', 'call bound not enforced');
    controls_check($limited->getReportPath() === null, 'default output did not clear');
    $limited->reset();
    $disabled = Profiler::start($directory);
    controls_leaf();
    Profiler::disable();
    $report = $disabled->getReport();
    controls_check($report['termination_reason'] === 'disabled', 'disable reason missing');
    controls_check(
        json_decode(file_get_contents($disabled->getReportPath()), true)['termination_reason'] === 'disabled',
        'persisted disable reason differs',
    );
    Profiler::resetAll();
    $sessions = [];
    for ($i = 0; $i < 16; $i++) {
        $sessions[] = Profiler::start();
    }
    controls_throws(StateException::class, fn() => Profiler::start());
    Profiler::resetAll();
    controls_throws(StateException::class, fn() => Profiler::reset());
    controls_throws(ConfigurationException::class, fn() => Profiler::setOutputPath(''));
    echo "extension controls passed\n";
} finally {
    Profiler::resetAll();
    Profiler::setOutputPath(null);
    foreach (glob($directory . '/*') as $path) {
        unlink($path);
    }
    rmdir($directory);
}
