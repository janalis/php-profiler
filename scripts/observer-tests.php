<?php

declare(strict_types=1);

// php_profiler.observer: Off registers no Zend observer, refuses every capture entry point
// and leaves automatic capture inert; the default keeps the observers.
require_once __DIR__ . '/lib/BackgroundProcess.php';
require_once __DIR__ . '/lib/ExceptionFixture.php';
require_once __DIR__ . '/lib/Fail.php';
require_once __DIR__ . '/lib/HttpClient.php';
require_once __DIR__ . '/lib/Paths.php';
require_once __DIR__ . '/lib/Process.php';
require_once __DIR__ . '/lib/SanitizerDiagnostics.php';

use PhpProfiler\Tooling\BackgroundProcess;
use PhpProfiler\Tooling\ExceptionFixture;
use PhpProfiler\Tooling\HttpClient;
use PhpProfiler\Tooling\Process;
use PhpProfiler\Tooling\SanitizerDiagnostics;

use function PhpProfiler\Tooling\fail;
use function PhpProfiler\Tooling\requiredEnvironment;

const NOTICE = 'php_profiler.observer is Off, so php_profiler.mode captures nothing';

const REFUSED = 'Profiling is unavailable: php_profiler.observer was Off at PHP startup';

$extension = requiredEnvironment('PROFILER_EXTENSION');
$environment = [...Process::childEnvironment(), 'PHP_INI_SCAN_DIR' => ''];
$directory = ExceptionFixture::temporaryDirectory('profiler-observer-');

function check(bool $condition, string $message, mixed $context = null): void
{
    if (!$condition) {
        fail("observer: {$message}: " . json_encode($context, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));
    }
}

/**
 * @param list<string> $settings
 * @return array{status: int|null, stdout: string, stderr: string}
 */
function runPhp(array $settings, string $code): array
{
    global $environment, $extension;
    $result = Process::run(
        ['php', '-n', '-d', 'extension=' . $extension, ...$settings, '-r', $code],
        $environment,
        timeout: 120,
    );
    SanitizerDiagnostics::reject($result['stdout'] . $result['stderr'], 'observer PHP subprocess');
    return $result;
}

/** @return list<string> */
function reports(string $directory): array
{
    return glob("{$directory}/*.json") ?: [];
}

// Each capture entry point names the setting; the request itself keeps running, and
// neither ini_set() nor a session API brings capture back.
$refusals = <<<'PHP'
    use PhpProfiler\Profiler;
    $refused = [];
    foreach ([
        'start' => static fn() => Profiler::start(),
        'start options' => static fn() => Profiler::start(null, ['timeline' => true]),
        'enable' => static fn() => Profiler::enable(),
    ] as $name => $call) {
        try {
            $call();
            $refused[$name] = false;
        } catch (PhpProfiler\ConfigurationException $e) {
            $refused[$name] = $e->getMessage();
        }
    }
    function leaf(): int { return 1; }
    $fiber = new Fiber(static function (): int { return leaf() + (int) Fiber::suspend(1); });
    $fiber->start();
    $fiber->resume(1);
    Profiler::disable();
    Profiler::resetAll();
    Profiler::setOutputPath('/nonexistent/default.json');
    echo json_encode([
        'refused' => $refused,
        'enabled' => Profiler::isEnabled(),
        'ini' => ini_get('php_profiler.observer'),
        'ini_set' => ini_set('php_profiler.observer', '1'),
        'fiber' => $fiber->getReturn(),
    ]);
    PHP;
foreach (['0', 'Off', 'false', 'no'] as $value) {
    $result = runPhp(['-d', "php_profiler.observer={$value}"], $refusals);
    check($result['status'] === 0 && $result['stderr'] === '', "Off ({$value}) process failed", $result);
    $state = json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR);
    check(
        $state === [
            'refused' => ['start' => REFUSED, 'start options' => REFUSED, 'enable' => REFUSED],
            'enabled' => false,
            // PHP stores unquoted false keywords as "".
            'ini' => $value === '0' ? '0' : '',
            'ini_set' => false,
            'fiber' => 2,
        ],
        "Off ({$value}) state",
        $state,
    );
}
$info = runPhp(['-d', 'php_profiler.observer=0'], 'phpinfo(INFO_MODULES);');
check(str_contains($info['stdout'], 'php_profiler.observer => Off => Off'), 'phpinfo Off', $info);
echo "observer Off refusal checks passed\n";

// The default and an explicit On keep today's behaviour: observers capture.
$capture = <<<'PHP'
    use PhpProfiler\Profiler;
    function leaf(): void {}
    $session = Profiler::start();
    leaf();
    $session->stop();
    Profiler::enable();
    echo json_encode([
        'calls' => $session->getReport()['functions']['leaf']['calls'] ?? null,
        'enabled' => Profiler::isEnabled(),
        'ini' => ini_get('php_profiler.observer'),
    ]);
    PHP;
foreach ([[], ['-d', 'php_profiler.observer=1'], ['-d', 'php_profiler.observer=On']] as $settings) {
    $result = runPhp($settings, $capture);
    check($result['status'] === 0 && $result['stderr'] === '', 'On process failed', $result);
    check(
        json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR) === [
            'calls' => 1,
            'enabled' => true,
            'ini' => '1',
        ],
        'On capture',
        [$settings, $result],
    );
}
$info = runPhp([], 'phpinfo(INFO_MODULES);');
check(str_contains($info['stdout'], 'php_profiler.observer => On => On'), 'phpinfo On', $info);
echo "observer On capture checks passed\n";

// Automatic capture without observers: the request runs untouched, writes no report and
// sends no header; the first such request logs the reason once. Mode off logs nothing.
$automatic = ['-d', 'php_profiler.observer=0', '-d', "php_profiler.output_dir={$directory}"];
foreach ([
    ['always', 1],
    ['trigger', 1],
    ['invalid', 1],
    ['off', 0],
] as [$mode, $notices]) {
    $result = runPhp([
        ...$automatic,
        '-d',
        "php_profiler.mode={$mode}",
        '-d',
        'php_profiler.trigger_secret=s',
    ], 'function leaf() {} leaf(); echo "ok";');
    check($result['status'] === 0 && $result['stdout'] === 'ok', "automatic {$mode} request", $result);
    check(substr_count($result['stderr'], NOTICE) === $notices, "automatic {$mode} notice", $result);
    check(!str_contains($result['stderr'], 'automatic capture failed'), "automatic {$mode} failure log", $result);
    check(reports($directory) === [], "automatic {$mode} wrote a report", reports($directory));
}
// A request SAPI serving several requests: still no capture, header or per-request log. Trigger
// mode sends a matching header, which the CLI never evaluates.
file_put_contents("{$directory}/server.php", '<?php function leaf() {} leaf(); echo "ok";');
foreach ([['always', []], ['trigger', ['X-Php-Profiler-Trigger' => 's']]] as [$mode, $headers]) {
    $port = HttpClient::freePort();
    $server = new BackgroundProcess([
        'php',
        '-n',
        '-d',
        'opcache.enable=0',
        '-d',
        'extension=' . $extension,
        ...$automatic,
        '-d',
        "php_profiler.mode={$mode}",
        '-d',
        'php_profiler.trigger_secret=s',
        '-S',
        "127.0.0.1:{$port}",
        '-t',
        $directory,
    ], $environment);
    try {
        $server->waitUntil(static fn(): bool => HttpClient::accepts($port), 'PHP HTTP server');
        for ($request = 0; $request < 3; $request++) {
            $response = HttpClient::get($port, '/server.php', $headers, 30);
            check($response['body'] === 'ok', 'built-in server body', $response);
            check(
                HttpClient::header($response['headers'], 'X-Php-Profiler-Id') === null,
                'built-in server header',
                $response,
            );
        }
    } finally {
        $log = $server->stop(SIGINT, mustExit: true);
    }
    SanitizerDiagnostics::reject($log, 'observer built-in server');
    check(substr_count($log, NOTICE) === 1, 'built-in server notice', $log);
    check(!str_contains($log, 'automatic capture failed'), 'built-in server failure log', $log);
    check(reports($directory) === [], 'built-in server wrote a report', reports($directory));
}
echo "observer Off automatic capture checks passed\n";
