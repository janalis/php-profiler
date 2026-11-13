<?php

declare(strict_types=1);

use PhpProfiler\Profiler;
use PhpProfiler\Session;

function session_leaf(): void
{
    strlen(str_repeat('x', 100));
}

function distinct_session_leaf(): void
{
    usleep(1);
}

function expect_failure(Closure $operation): void
{
    try {
        $operation();
    } catch (Throwable $failure) {
        return;
    }
    throw new RuntimeException('expected explicit API failure');
}

$directory = sys_get_temp_dir() . '/php-profiler-session-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$directory = realpath($directory);
$path = $directory . '/report.json';
try {
    Profiler::disable();
    expect_failure(fn() => Profiler::stop());
    expect_failure(fn() => Profiler::start(''));
    $boundaryPath = Profiler::start(str_repeat('x', 4096));
    $boundaryPath->reset(); // Configuration limit; filesystem validity is checked on write.
    expect_failure(fn() => Profiler::start(str_repeat('x', 4097)));
    expect_failure(fn() => Profiler::start("invalid\0path"));
    expect_failure(fn() => Profiler::start(null, ['timeline' => 'yes']));
    expect_failure(fn() => Profiler::start(null, ['max_events' => 0]));
    expect_failure(fn() => Profiler::start(null, ['max_events' => 8193]));
    expect_failure(fn() => Profiler::start(null, ['max_events' => '2']));
    expect_failure(fn() => Profiler::start(null, ['unknown' => true]));
    expect_failure(fn() => new Session());
    $session = Profiler::start(null, ['timeline' => true]);
    if (!$session instanceof Session) {
        throw new RuntimeException('not a Session');
    }
    expect_failure(fn() => $session->getReport());
    session_leaf();
    $session->setOutputPath($path);
    Profiler::setOutputPath($path, $session);
    $session->stop();
    $session->stop();
    $report = $session->getReport();
    if ($report['functions']['session_leaf']['calls'] !== 1 || $report['timeline'] === []) {
        throw new RuntimeException('timeline or call count missing');
    }
    if ($session->getReportPath() !== $path || !is_file($path)) {
        throw new RuntimeException('session persistence missing');
    }
    $other = Profiler::start($path);
    session_leaf();
    expect_failure(fn() => $other->stop());
    if ($other->getReport() === []) {
        throw new RuntimeException('write failure lost report');
    }
    expect_failure(fn() => $other->setOutputPath(''));
    expect_failure(fn() => $other->setOutputPath("invalid\0path"));
    $other->reset();
    $session->reset();
    expect_failure(fn() => $session->getReport());
    Profiler::resetAll();
    $handles = [];
    for ($i = 0; $i < 16; ++$i) {
        $handles[] = Profiler::start();
    }
    expect_failure(fn() => Profiler::start());
    Profiler::disable();
    Profiler::resetAll();
    $limited = Profiler::start(null, ['max_events' => 1]);
    session_leaf();
    distinct_session_leaf();
    $limited->stop();
    if ($limited->getReport()['status'] !== 'truncated') {
        throw new RuntimeException('limit not reported');
    }
    $limited->reset();
    $unfinished = Profiler::start();
    Profiler::disable();
    if ($unfinished->getReport() === []) {
        throw new RuntimeException('disable lost session');
    }
    $unfinished->reset();
    echo "session API failures and persistence passed\n";
} finally {
    Profiler::resetAll();
    Profiler::disable();
    foreach (glob($directory . '/*') as $file) {
        unlink($file);
    }
    rmdir($directory);
}
