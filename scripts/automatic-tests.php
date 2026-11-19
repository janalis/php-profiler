<?php

declare(strict_types=1);

// Automatic request capture integration checks.
require_once __DIR__ . '/lib/BackgroundProcess.php';
require_once __DIR__ . '/lib/ExceptionFixture.php';
require_once __DIR__ . '/lib/Fail.php';
require_once __DIR__ . '/lib/FpmServer.php';
require_once __DIR__ . '/lib/HttpClient.php';
require_once __DIR__ . '/lib/Paths.php';
require_once __DIR__ . '/lib/Process.php';
require_once __DIR__ . '/lib/SanitizerDiagnostics.php';

use PhpProfiler\Tooling\BackgroundProcess;
use PhpProfiler\Tooling\ExceptionFixture;
use PhpProfiler\Tooling\FpmServer;
use PhpProfiler\Tooling\HttpClient;
use PhpProfiler\Tooling\Paths;
use PhpProfiler\Tooling\Process;
use PhpProfiler\Tooling\SanitizerDiagnostics;

use function PhpProfiler\Tooling\requiredEnvironment;

const INHERITED = 'HTTP_X_PHP_PROFILER_TRIGGER is set in the process environment';

/** Like a Python assert: fails with the context that shows why. */
function check(bool $condition, mixed ...$context): void
{
    if (!$condition) {
        $described = array_map(static fn(mixed $item): string => is_string($item)
            ? $item
            : (string) json_encode($item, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES), $context);
        throw new RuntimeException('check failed: ' . implode(' | ', $described));
    }
}

/** @return list<string> */
function reports(string $directory): array
{
    return glob("{$directory}/*.json") ?: [];
}

function report(string $path): array
{
    return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
}

$phpEnvironment = Process::childEnvironment();
$extension = requiredEnvironment('PROFILER_EXTENSION');

/**
 * Runs PHP with the test environment, rejecting sanitizer reports in its output.
 *
 * @return array{status: int|null, stdout: string, stderr: string}
 */
function runPhp(array $command, ?array $environment = null, bool $check = false, ?string $directory = null): array
{
    global $phpEnvironment;
    $result = Process::run($command, $environment ?? $phpEnvironment, $directory, timeout: 120);
    if ($check && $result['status'] !== 0) {
        throw new RuntimeException('PHP subprocess failed: ' . $result['stdout'] . $result['stderr']);
    }
    SanitizerDiagnostics::reject($result['stdout'] . $result['stderr'], 'automatic PHP subprocess');
    return $result;
}

/** PHP options without any host INI; run with PHP_INI_SCAN_DIR set to ''. */
function triggerSettings(string $directory, string $secret): array
{
    global $extension;
    if (str_contains($secret, "\0")) {
        // Only a quoted INI string carries a NUL byte; -c replaces php.ini as -n would skip it.
        file_put_contents("{$directory}/secret.ini", 'php_profiler.trigger_secret="' . $secret . "\"\n");
        $source = ['-c', "{$directory}/secret.ini"];
    } else {
        $source = ['-n', '-d', 'php_profiler.trigger_secret=' . $secret];
    }
    // OPcache stays enabled for the built-in server even with -n; a cached script may inline leaf().
    return [
        ...$source,
        '-d',
        'opcache.enable=0',
        '-d',
        'extension=' . $extension,
        '-d',
        'php_profiler.mode=trigger',
        '-d',
        'php_profiler.output_dir=' . $directory,
    ];
}

/** The built-in server exposes headers only through _SERVER, never the process environment. */
function builtInServerChecks(string $output): void
{
    global $phpEnvironment;
    file_put_contents("{$output}/server.php", '<?php function leaf() {} leaf(); echo "ok";');
    $long = str_repeat('x', 4097);
    $longest = str_repeat('x', 4096);
    foreach ([
        ['test-secret', [[null, false], ['wrong', false], ['test-secret,other', false], ['test-secret', true]], null],
        // The built-in server reads headers only: even an inherited copy of the secret is ignored.
        ['test-secret', [[null, false], ['test-secret', true], ['wrong', false]], 'test-secret'],
        // Latin-1 bytes are compared exactly, never through a lossy UTF-8 conversion.
        ["caf\xe9", [["caf\xe9", true], ["caf\u{e9}", false]], null],
        // Unusable secrets are configuration errors, logged only when a capture is requested.
        ['', [[null, false], ['', false], ['x', false]], null],
        ['a,b', [[null, false], ['a,b', false]], null],
        [$long, [[null, false], [$long, false]], null],
        // No header value can carry the NUL byte a quoted INI string can.
        ["a\0b", [[null, false], ['a', false]], null],
        // The longest usable secret still captures through a real header.
        [$longest, [[$longest, true]], null],
    ] as [$secret, $cases, $inherited]) {
        $environment = [...$phpEnvironment, 'PHP_INI_SCAN_DIR' => ''];
        unset($environment['HTTP_X_PHP_PROFILER_TRIGGER']);
        if ($inherited !== null) {
            $environment['HTTP_X_PHP_PROFILER_TRIGGER'] = $inherited;
        }
        $port = HttpClient::freePort();
        $server = new BackgroundProcess([
            'php',
            ...triggerSettings($output, $secret),
            '-S',
            "127.0.0.1:{$port}",
            '-t',
            $output,
        ], $environment);
        try {
            $server->waitUntil(static fn(): bool => HttpClient::accepts($port), 'PHP HTTP server');
            foreach ($cases as [$token, $captured]) {
                $response = HttpClient::get(
                    $port,
                    '/server.php',
                    $token === null ? [] : ['X-Php-Profiler-Trigger' => $token],
                    30,
                );
                check($response['body'] === 'ok', bin2hex($secret), $token);
                $identifier = HttpClient::header($response['headers'], 'X-Php-Profiler-Id');
                check(($identifier !== null) === $captured, bin2hex($secret), $token, $identifier);
                if ($captured) {
                    // The built-in server persists reports after sending the response.
                    $path = "{$output}/php-profile-{$identifier}.json";
                    for ($attempt = 0; $attempt < 500 && !file_exists($path); $attempt++) {
                        usleep(10_000);
                    }
                    check(report($path)['functions']['leaf']['calls'] === 1, $path);
                    unlink($path);
                }
            }
        } finally {
            $log = $server->stop(SIGINT);
        }
        SanitizerDiagnostics::reject($log, 'automatic built-in server');
        check(reports($output) === [], bin2hex($secret));
        // One log line per requested capture with an unusable secret, none without a header.
        $unusable =
            $secret === '' || strlen($secret) > 4096 || str_contains($secret, ',') || str_contains($secret, "\0");
        $requested = count(array_filter($cases, static fn(array $case): bool => $case[0] !== null));
        check(substr_count($log, 'automatic capture failed') === ($unusable ? $requested : 0), bin2hex($secret), $log);
        check(!str_contains($log, INHERITED), bin2hex($secret), $log);
    }
    echo "automatic built-in server trigger checks passed\n";
}

/** SAPIs that read the process environment never take a trigger from it, and say why. */
function inheritedEnvironmentChecks(string $output): void
{
    global $phpEnvironment;
    $environment = [...$phpEnvironment, 'HTTP_X_PHP_PROFILER_TRIGGER' => 'test-secret', 'PHP_INI_SCAN_DIR' => ''];
    $script = "{$output}/environment.php";
    file_put_contents($script, '<?php echo "ok";');
    $settings = triggerSettings($output, 'test-secret');
    if (Paths::which('phpdbg') !== null) {
        // phpdbg drops log messages unless error_log names a file.
        $log = "{$output}/phpdbg.log";
        try {
            $result = runPhp(['phpdbg', ...$settings, '-d', "error_log={$log}", '-qrr', $script], $environment, true);
            check(
                str_ends_with($result['stdout'], 'ok')
                && substr_count((string) file_get_contents($log), INHERITED) === 1,
                $result,
            );
        } finally {
            @unlink($log);
        }
        check(reports($output) === [], 'phpdbg environment must not trigger a capture');
        echo "automatic phpdbg environment check passed\n";
    } else {
        echo "automatic phpdbg environment check skipped: phpdbg unavailable\n";
    }
    if (Paths::which('php-cgi') !== null) {
        $server = FpmServer::phpCgi($settings, $environment);
        $server->serve(static function (FpmServer $server) use ($script): void {
            // The fallback is indistinguishable from a header, so every request is refused,
            // including one with a real FastCGI parameter; one log line per process.
            foreach ([[1, []], [0, []], [0, ['HTTP_X_PHP_PROFILER_TRIGGER' => 'test-secret']]] as [
                $logged,
                $parameters,
            ]) {
                $response = $server->requestRaw($script, $parameters);
                check($response['body'] === 'ok', $response);
                check(substr_count($response['stderr'], INHERITED) === $logged, $response);
                check(!str_contains($response['stderr'], 'automatic capture failed'), $response);
                check(!str_contains($response['headers'], 'X-Php-Profiler-Id'), $response);
            }
        });
        SanitizerDiagnostics::reject($server->lastLog, 'automatic php-cgi');
        check(reports($output) === [], 'php-cgi environment fallback must not trigger a capture');
        $clean = $environment;
        unset($clean['HTTP_X_PHP_PROFILER_TRIGGER']);
        $server = FpmServer::phpCgi($settings, $clean);
        $server->serve(static function (FpmServer $server) use ($script): void {
            $response = $server->requestRaw($script, ['HTTP_X_PHP_PROFILER_TRIGGER' => 'test-secret']);
            check($response['body'] === 'ok' && $response['stderr'] === '', $response);
            check(str_contains($response['headers'], 'X-Php-Profiler-Id'), $response);
        });
        SanitizerDiagnostics::reject($server->lastLog, 'automatic php-cgi');
        $found = reports($output);
        check(count($found) === 1, 'a real FastCGI trigger header must still capture');
        unlink($found[0]);
        echo "automatic php-cgi environment check passed\n";
    } else {
        echo "automatic php-cgi environment check skipped: php-cgi unavailable\n";
    }
}

function profilerId(string $headers): string
{
    foreach (explode("\r\n", $headers) as $line) {
        if (str_starts_with(strtolower($line), 'x-php-profiler-id:')) {
            return trim(explode(':', $line, 2)[1]);
        }
    }
    throw new RuntimeException('X-Php-Profiler-Id missing: ' . $headers);
}

function fpmChecks(string $directory, string $fixture): void
{
    global $extension, $phpEnvironment;
    // PHP 8.5 always builds OPcache, and -n leaves it enabled for FPM. Once a script is cached,
    // its optimizer may inline the empty leaf() call, so cache state would decide the report.
    $settings = [
        'opcache.enable' => '0',
        'php_profiler.mode' => 'trigger',
        'php_profiler.trigger_secret' => 'test-secret',
        'php_profiler.output_dir' => $directory,
    ];
    chmod($directory, 0o777);
    (new FpmServer($extension, $settings))->serve(static function (FpmServer $server) use ($fixture, $directory): void {
        foreach ([
            null,
            '',
            'wrong',
            'test-secret,other',
            str_repeat('x', 4097),
            'test-secret',
            null,
            'test-secret',
        ] as $token) {
            $response = $server->requestRaw($fixture, $token === null ? [] : ['HTTP_X_PHP_PROFILER_TRIGGER' => $token]);
            check($response['body'] === 'ok', $response);
            check($response['stderr'] === '', $response);
            if ($token === 'test-secret') {
                check(
                    report(
                        "{$directory}/php-profile-" . profilerId($response['headers']) . '.json',
                    )['termination_reason'] === 'request_complete',
                );
            } else {
                check(!str_contains(strtolower($response['headers']), 'x-php-profiler-id:'), $response);
            }
        }
    });
    check(count(reports($directory)) === 2);
    array_map(unlink(...), reports($directory));
    // FPM reads FastCGI parameters only: its startup environment, even holding the secret
    // itself, never shadows them. The worker writes error_log synchronously, before replying.
    $leaked = [...$phpEnvironment, 'HTTP_X_PHP_PROFILER_TRIGGER' => 'test-secret'];
    $log = "{$directory}/fpm-inherited.log";
    (new FpmServer(
        $extension,
        [...$settings, 'error_log' => $log],
        environment: $leaked,
    ))->serve(static function (FpmServer $server) use ($fixture, $directory): void {
        foreach ([[null, false], ['test-secret', true]] as [$token, $captured]) {
            $response = $server->requestRaw($fixture, $token === null ? [] : ['HTTP_X_PHP_PROFILER_TRIGGER' => $token]);
            check($response['body'] === 'ok' && $response['stderr'] === '', $response);
            check(str_contains($response['headers'], 'X-Php-Profiler-Id') === $captured, $response);
        }
        // A marker proves the worker writes this log, so the absence below is meaningful.
        $marker = "{$directory}/fpm-log-marker.php";
        file_put_contents($marker, "<?php error_log('fpm-log-marker'); echo 'ok';");
        chmod($marker, 0o644);
        check($server->requestRaw($marker)['body'] === 'ok');
        unlink($marker);
    });
    $written = (string) file_get_contents($log);
    check(str_contains($written, 'fpm-log-marker') && !str_contains($written, 'php_profiler'), $written);
    unlink($log);
    $found = reports($directory);
    check(count($found) === 1, 'FPM must capture a real header despite an inherited variable');
    unlink($found[0]);
    // `php-fpm -i` runs a request before any pool, so FPM's getenv still reads the process
    // environment: that request has no method and must not capture.
    $information = Process::run(
        [
            (string) getenv('PHP_FPM') ?: 'php-fpm',
            '-n',
            '-d',
            'extension=' . $extension,
            ...array_map(
                static fn(string $key, string $value): string => "-d{$key}={$value}",
                array_keys($settings),
                $settings,
            ),
            '-i',
        ],
        $leaked,
        timeout: 60,
    );
    SanitizerDiagnostics::reject($information['stderr'], 'php-fpm -i');
    check($information['status'] === 0 && str_contains($information['stdout'], 'php_profiler'), $information['status']);
    check(reports($directory) === [], 'php-fpm -i must not capture an inherited trigger');
    foreach (['', 'test-secret,other'] as $secret) {
        $settings['php_profiler.trigger_secret'] = $secret;
        (new FpmServer($extension, $settings))->serve(static function (FpmServer $server) use (
            $fixture,
            $secret,
        ): void {
            check(!str_contains(
                $server->requestRaw($fixture, ['HTTP_X_PHP_PROFILER_TRIGGER' => $secret])['headers'],
                'X-Php-Profiler-Id',
            ));
        });
        check(reports($directory) === []);
    }
    $settings['php_profiler.mode'] = 'always';
    $responses = (new FpmServer(
        $extension,
        $settings,
        4,
    ))->serve(static fn(FpmServer $server): array => $server->concurrent(array_fill(0, 24, [$fixture, []]), 8));
    $ids = [];
    foreach ($responses as $response) {
        check($response['body'] === 'ok' && $response['stderr'] === '', $response);
        check(!str_contains($response['headers'], $directory) && !str_contains($response['headers'], 'test-secret'));
        $identifier = profilerId($response['headers']);
        check(!isset($ids[$identifier]));
        $ids[$identifier] = true;
        check(report("{$directory}/php-profile-{$identifier}.json")['functions']['leaf']['calls'] === 1);
    }
    check(count(reports($directory)) === 24);
    array_map(unlink(...), reports($directory));
    (new FpmServer($extension, $settings))->serve(static function (FpmServer $server) use ($fixture, $directory): void {
        foreach ([
            [
                'http_response_code(201); echo "early"; flush(); register_shutdown_function(function () { function shutdown_leaf() {} shutdown_leaf(); });',
                'early',
                false,
            ],
            ['http_response_code(503); throw new Exception("test fatal");', null, true],
        ] as [$source, $body, $fatal]) {
            file_put_contents($fixture, '<?php ' . $source);
            $response = $server->requestRaw($fixture);
            if ($body !== null) {
                check($response['body'] === $body, $response);
            }
            check(str_contains($response['headers'], $fatal ? '503' : '201'), $response);
            check(str_contains($response['headers'], 'X-Php-Profiler-Id:'));
            $found = reports($directory);
            check(count($found) === 1);
            if (!$fatal) {
                check(report($found[0])['functions']['shutdown_leaf']['calls'] === 1);
            }
            unlink($found[0]);
        }
        // Occupying the announced filename must never overwrite that file.
        file_put_contents(
            $fixture,
            '<?php foreach (headers_list() as $header) { if (str_starts_with($header, "X-Php-Profiler-Id:")) { $id = trim(substr($header, strlen("X-Php-Profiler-Id:"))); file_put_contents(__DIR__ . "/php-profile-" . $id . ".json", "sentinel"); }} echo "ok";',
        );
        $response = $server->requestRaw($fixture);
        check($response['body'] === 'ok');
        $found = reports($directory);
        check(count($found) === 1 && file_get_contents($found[0]) === 'sentinel');
        check(str_contains($response['stderr'], 'automatic capture failed'), $response);
    });
    echo "automatic HTTP lifecycle, failure and no-overwrite checks passed\n";
}

// CLI configuration, the built-in server, environment-reading SAPIs and FPM.
$directory = ExceptionFixture::temporaryDirectory('profiler-automatic-');
$command = [
    'php',
    '-n',
    '-d',
    'extension=' . $extension,
    '-d',
    'php_profiler.mode=always',
    '-d',
    'php_profiler.output_dir=' . $directory,
    '-r',
    'function leaf() {} leaf(); echo "ok";',
];
$started = runPhp($command, check: true);
check($started['stdout'] === 'ok' && $started['stderr'] === '', $started);
check(
    runPhp([
        'php',
        '-n',
        '-d',
        'extension=' . $extension,
        '-r',
        'if (ini_set("php_profiler.mode", "always") !== false) exit(1); echo "ok";',
    ], check: true)['stdout'] === 'ok',
);
$invalid = $command;
$invalid[array_search('php_profiler.output_dir=' . $directory, $invalid, true)] =
    'php_profiler.output_dir=' . $directory . '/missing';
$failed = runPhp($invalid, check: true);
check($failed['stdout'] === 'ok');
check(str_contains($failed['stderr'], 'automatic capture failed'));
$found = reports($directory);
check(count($found) === 1);
check(report($found[0])['termination_reason'] === 'request_complete');
unlink($found[0]);
$ignored = runPhp(
    [
        'php',
        '-n',
        '-d',
        'extension=' . $extension,
        '-d',
        'php_profiler.mode=trigger',
        '-d',
        'php_profiler.trigger_secret=test-secret',
        '-d',
        'php_profiler.output_dir=' . $directory,
        '-r',
        'echo "ok";',
    ],
    [...$phpEnvironment, 'HTTP_X_PHP_PROFILER_TRIGGER' => 'test-secret'],
    true,
);
check($ignored['stdout'] === 'ok' && $ignored['stderr'] === '');
check(reports($directory) === [], 'CLI environment must not activate HTTP trigger mode');
// No trigger header reaches the CLI, so even an unusable secret requests nothing.
foreach (['', 'test,secret', str_repeat('x', 4097), str_repeat('x', 4096)] as $secret) {
    $checked = runPhp([
        'php',
        '-n',
        '-d',
        'extension=' . $extension,
        '-d',
        'php_profiler.mode=trigger',
        '-d',
        'php_profiler.trigger_secret=' . $secret,
        '-d',
        'php_profiler.output_dir=' . $directory,
        '-r',
        'echo "ok";',
    ], check: true);
    check($checked['stdout'] === 'ok' && $checked['stderr'] === '', strlen($secret), $checked);
    check(reports($directory) === []);
}
builtInServerChecks($directory);
inheritedEnvironmentChecks($directory);
$fixture = "{$directory}/request.php";
file_put_contents($fixture, '<?php function leaf() {} leaf(); echo "ok";');
if (in_array('--fpm', $argv, true)) {
    fpmChecks($directory, $fixture);
}
echo "automatic CLI configuration checks passed\n";

// Each subprocess starts from clean request state. Explicit API controls must not
// resurrect the automatic session or create a second copy at shutdown.
$directory = ExceptionFixture::temporaryDirectory('profiler-automatic-lifecycle-');
$base = [
    'php',
    '-n',
    '-d',
    'extension=' . $extension,
    '-d',
    'display_errors=stderr',
    '-d',
    'php_profiler.mode=always',
    '-d',
    'php_profiler.output_dir=' . $directory,
];
foreach ([
    ['PhpProfiler\Profiler::stop();', 1, 'stopped'],
    ['PhpProfiler\Profiler::disable();', 1, 'disabled'],
    ['PhpProfiler\Profiler::resetAll();', 0, null],
    ['PhpProfiler\Profiler::start();', 1, 'request_complete'],
    [
        'register_shutdown_function(function () { function shutdown_leaf() {} shutdown_leaf(); });',
        1,
        'request_complete',
    ],
    ['echo "early"; flush();', 1, 'request_complete'],
    ['throw new Exception("fatal test");', 1, 'request_complete'],
] as [$source, $count, $reason]) {
    $result = runPhp([...$base, '-r', $source]);
    check($result['status'] === (str_starts_with($source, 'throw') ? 255 : 0), $result);
    $found = reports($directory);
    check(count($found) === $count, $source, $found);
    foreach ($found as $path) {
        $report = report($path);
        check($report['termination_reason'] === $reason, $report['termination_reason']);
        if (str_starts_with($source, 'register_shutdown')) {
            check($report['functions']['shutdown_leaf']['calls'] === 1);
        }
        unlink($path);
    }
}
// PHP's INI parser passes unquoted Off/false/no/none as "" and On/true/yes as "1".
foreach (['off', 'Off', 'false', 'none', ''] as $word) {
    $result = runPhp([...$base, '-d', 'php_profiler.mode=' . $word, '-r', 'echo "ok";'], check: true);
    check($result['stdout'] === 'ok' && $result['stderr'] === '', $word, $result);
    check(reports($directory) === []);
}
foreach ([['On', 'no', true], ['Off', 'false', false]] as [$timeline, $sampling, $recorded]) {
    $result = runPhp([
        ...$base,
        '-d',
        'php_profiler.timeline=' . $timeline,
        '-d',
        'php_profiler.sampling=' . $sampling,
        '-r',
        'function leaf() {} leaf(); echo "ok";',
    ], check: true);
    check($result['stdout'] === 'ok' && $result['stderr'] === '', $timeline, $result);
    $found = reports($directory);
    check(count($found) === 1, $found);
    check((bool) report($found[0])['timeline'] === $recorded, $timeline);
    unlink($found[0]);
}
// phpinfo() masks a configured trigger secret and still reports an unset one.
foreach ([['hidden-secret-value', '********'], ['', 'no value']] as [$secret, $shown]) {
    $info = runPhp([
        'php',
        '-n',
        '-d',
        'extension=' . $extension,
        '-d',
        'php_profiler.trigger_secret=' . $secret,
        '-i',
    ], check: true)['stdout'];
    check(str_contains($info, "php_profiler.trigger_secret => {$shown} => {$shown}"), substr($info, -2000));
    check(!str_contains($info, 'hidden-secret-value'));
}
mkdir("{$directory}/relative-directory");
foreach ([
    ['mode', 'invalid'],
    ['max_events', '0'],
    ['max_events', '8193'],
    ['timeline', 'invalid'],
    ['sampling', 'invalid'],
    ['output_dir', 'relative'],
    ['output_dir', $directory . '/missing'],
] as [$name, $value]) {
    $result = runPhp([...$base, '-d', "php_profiler.{$name}={$value}", '-r', 'echo "ok";']);
    check($result['status'] === 0 && $result['stdout'] === 'ok', $result);
    check(str_contains($result['stderr'], 'automatic capture failed'), $result);
    check(reports($directory) === []);
}
// Exact INI bytes: values that are not UTF-8 or not numeric are configuration errors.
foreach ([
    "php_profiler.max_events=\xff",
    'php_profiler.max_events=many',
    "php_profiler.output_dir={$directory}/\xff",
] as $setting) {
    $result = runPhp([...$base, '-d', $setting, '-r', 'echo "ok";'], check: true);
    check(
        $result['stdout'] === 'ok' && str_contains($result['stderr'], 'automatic capture failed'),
        bin2hex($setting),
        $result,
    );
    check(reports($directory) === []);
}
$relative = runPhp(
    [...$base, '-d', 'php_profiler.output_dir=relative-directory', '-r', 'echo "ok";'],
    check: true,
    directory: $directory,
);
check($relative['stdout'] === 'ok' && str_contains($relative['stderr'], 'automatic capture failed'));
check(reports("{$directory}/relative-directory") === []);
$result = runPhp([...$base, '-d', 'open_basedir=/nonexistent-profiler-test', '-r', 'echo "ok";'], check: true);
check($result['stdout'] === 'ok' && str_contains($result['stderr'], 'automatic capture failed'));
check(reports($directory) === []);
runPhp([
    ...$base,
    '-d',
    'php_profiler.timeline=1',
    '-d',
    'php_profiler.max_events=1',
    '-r',
    'function a() {} a();',
], check: true);
check((bool) report(reports($directory)[0])['timeline']);
array_map(unlink(...), reports($directory));
$result = runPhp([...$base, '-d', 'php_profiler.sampling=1', '-r', 'usleep(20000);'], check: true);
$sampled = reports($directory);
if (getenv('CI_PROFILER_REQUIRE_SAMPLING') === '1') {
    check($sampled !== [], $result);
}
if ($sampled !== []) {
    check(report($sampled[0])['schema_version'] === 2);
} else {
    check(str_contains($result['stderr'], 'automatic capture failed'));
}
array_map(unlink(...), $sampled);
// Tightening open_basedir while recording must prevent persistence.
$result = runPhp([...$base, '-r', 'ini_set("open_basedir", "/nonexistent-profiler-test"); echo "ok";'], check: true);
check($result['stdout'] === 'ok' && str_contains($result['stderr'], 'automatic capture failed'));
check(reports($directory) === []);
echo "automatic lifecycle, configuration, options and persistence failure checks passed\n";

// Exercise the _SERVER fallback used by HTTP SAPIs without a getenv callback.
$directory = ExceptionFixture::temporaryDirectory('profiler-http-');
file_put_contents("{$directory}/index.php", '<?php function http_leaf() {} http_leaf(); echo "ok";');
$port = HttpClient::freePort();
$server = new BackgroundProcess([
    'php',
    '-n',
    '-d',
    'extension=' . $extension,
    '-d',
    'php_profiler.mode=trigger',
    '-d',
    'php_profiler.trigger_secret=http-secret',
    '-d',
    'php_profiler.output_dir=' . $directory,
    '-S',
    "127.0.0.1:{$port}",
    '-t',
    $directory,
], $phpEnvironment);
try {
    $ready = false;
    for ($attempt = 0; $attempt < 100 && !$ready; $attempt++) {
        try {
            $response = HttpClient::get($port, '/', timeout: 1);
            check($response['body'] === 'ok' && HttpClient::header($response['headers'], 'X-Php-Profiler-Id') === null);
            $ready = true;
        } catch (RuntimeException $failure) {
            if (str_starts_with($failure->getMessage(), 'check failed')) {
                throw $failure;
            }
            check($server->running(), 'PHP HTTP server exited');
            usleep(50_000);
        }
    }
    check($ready, 'PHP HTTP server did not start');
    $response = HttpClient::get($port, '/', ['X-Php-Profiler-Trigger' => 'http-secret']);
    check($response['body'] === 'ok');
    $identifier = HttpClient::header($response['headers'], 'X-Php-Profiler-Id');
    check((string) $identifier !== '');
    $path = "{$directory}/php-profile-{$identifier}.json";
    for ($attempt = 0; $attempt < 100 && !file_exists($path); $attempt++) {
        usleep(10_000);
    }
    check(report($path)['functions']['http_leaf']['calls'] === 1);
} finally {
    $log = $server->stop(SIGINT);
}
SanitizerDiagnostics::reject($log, 'automatic HTTP server');
echo "automatic built-in HTTP server header trigger passed\n";

if (PHP_OS_FAMILY === 'Linux') {
    // A valid native directory can leave too little room for the generated basename.
    // Admission must reject it before FixedPath publication.
    $current = ExceptionFixture::temporaryDirectory('profiler-long-path-');
    while ((strlen($current) + 251) < 4070) {
        $current .= '/' . str_repeat('x', 250);
        mkdir($current);
    }
    $remaining = 4070 - strlen($current) - 1;
    if ($remaining > 0) {
        $current .= '/' . str_repeat('y', $remaining);
        mkdir($current);
    }
    $result = runPhp([
        'php',
        '-n',
        '-d',
        'extension=' . $extension,
        '-d',
        'php_profiler.mode=always',
        '-d',
        'php_profiler.output_dir=' . $current,
        '-r',
        'echo "ok";',
    ], check: true);
    check($result['stdout'] === 'ok' && str_contains($result['stderr'], 'automatic capture failed'));
    check(reports($current) === []);
    echo "automatic native path admission boundary passed\n";
}

/**
 * Sends a GET and returns the response's X-Php-Profiler-Id. Only a worker's throwing
 * handler may answer 500, and that response still names the capture.
 */
function serverRequest(int $port, ?string $token, string $path = '/'): ?string
{
    $response = HttpClient::get($port, $path, $token === null ? [] : ['X-Php-Profiler-Trigger' => $token]);
    if ($response['status'] >= 400) {
        check($path === '/throw' && $response['status'] === 500, $path, $response['status']);
    } elseif ($path === '/throw') {
        // A worker reports its handler's uncaught exception in that response.
        check(str_contains($response['body'], 'worker handler failure'), $token, $response['body']);
    } else {
        check($response['body'] === 'ok', $path, $token, $response['body']);
    }
    return HttpClient::header($response['headers'], 'X-Php-Profiler-Id');
}

// FrankenPHP's _SERVER starts from the process environment and ends with its configured env
// values, so its headers come from frankenphp_request_headers(). Its PHP build must match the
// extension's, so this runs only where FRANKENPHP names one.
if ((string) getenv('FRANKENPHP') !== '') {
    /**
     * Serves root/ with php-server, or through a Caddyfile when $configured names an env value.
     * A $worker script serves every request from one worker thread; a $script replaces the
     * classic index.php.
     *
     * @return array{BackgroundProcess, int}
     */
    $frankenphp = static function (
        string $directory,
        array $environment,
        ?string $configured = null,
        string $mode = 'trigger',
        ?string $worker = null,
        ?string $script = null,
    ) use ($extension): array {
        $configuration = "{$directory}/conf";
        $root = "{$directory}/root";
        mkdir($configuration);
        mkdir($root);
        file_put_contents(
            "{$configuration}/profiler.ini",
            "extension={$extension}\nphp_profiler.mode={$mode}\nphp_profiler.trigger_secret=franken-secret\nphp_profiler.output_dir={$directory}\n",
        );
        file_put_contents("{$root}/index.php", $worker ?? $script ?? '<?php echo "ok";');
        $port = HttpClient::freePort();
        $command = [(string) getenv('FRANKENPHP'), 'php-server', '--listen', "127.0.0.1:{$port}", '--root', $root];
        if ($worker !== null) {
            array_push($command, '--worker', "{$root}/index.php,1");
        }
        if ($configured !== null) {
            file_put_contents(
                "{$directory}/Caddyfile",
                "{\n\tadmin off\n\tauto_https off\n}\nhttp://127.0.0.1:{$port} {\n\troot * {$root}\n\tphp_server {\n\t\tenv HTTP_X_PHP_PROFILER_TRIGGER {$configured}\n\t}\n}\n",
            );
            $command = [
                (string) getenv('FRANKENPHP'),
                'run',
                '--config',
                "{$directory}/Caddyfile",
                '--adapter',
                'caddyfile',
            ];
        }
        $process = new BackgroundProcess(
            $command,
            [...$environment, 'PHP_INI_SCAN_DIR' => $configuration, 'PHPRC' => $configuration],
            $root,
        );
        try {
            $process->waitUntil(static fn(): bool => HttpClient::accepts($port), 'FrankenPHP');
        } catch (Throwable $failure) {
            throw new RuntimeException($failure->getMessage() . "\n" . $process->stop(SIGINT), 0, $failure);
        }
        return [$process, $port];
    };
    /** Stops FrankenPHP and returns its log; diagnostics never mask an earlier failure. */
    $stopFrankenphp = static function (BackgroundProcess $process, ?Throwable $failure = null): string {
        $log = $process->stop(SIGINT);
        if ($failure !== null) {
            throw new RuntimeException($failure->getMessage() . "\n" . $log, 0, $failure);
        }
        SanitizerDiagnostics::reject($log, 'FrankenPHP');
        return $log;
    };
    foreach ([[null, null], ['franken-secret', null], [null, 'franken-secret']] as [$inherited, $configured]) {
        $environment = $phpEnvironment;
        unset($environment['HTTP_X_PHP_PROFILER_TRIGGER']);
        if ($inherited !== null) {
            $environment['HTTP_X_PHP_PROFILER_TRIGGER'] = $inherited;
        }
        $directory = ExceptionFixture::temporaryDirectory('profiler-franken-');
        [$process, $port] = $frankenphp($directory, $environment, $configured);
        try {
            $captured = array_map(static fn(?string $token): bool => serverRequest($port, $token) !== null, [
                null,
                'wrong',
                'franken-secret',
                'franken-secret',
            ]);
        } catch (Throwable $failure) {
            $stopFrankenphp($process, $failure);
        }
        $log = $stopFrankenphp($process);
        // Only real headers capture: neither an inherited nor a configured env value stands
        // in for a missing one, and neither is reported.
        check($captured === [false, false, true, true], $inherited, $configured, $captured, $log);
        check(count(reports($directory)) === 2, $inherited, $configured);
        check(!str_contains($log, INHERITED), $inherited, $configured, $log);
    }
    echo "automatic FrankenPHP trigger checks passed\n";

    // Worker mode serves every request inside the PHP request running the worker script; each
    // one is still its own profiler request, past the 16 sessions a request may hold.
    $workerScript = <<<'WORKER'
        <?php
        $handler = static function (): void {
            if ($_SERVER['REQUEST_URI'] === '/throw') {
                throw new RuntimeException('worker handler failure');
            }
            echo 'ok';
        };
        $between = false;
        while (frankenphp_handle_request($handler)) {
            // A session started between requests ends before the worker waits for the next one.
            if (!$between) {
                PhpProfiler\Profiler::start(__DIR__ . '/between.json');
                $between = true;
            }
        }

        WORKER;
    /**
     * Sends (token, path) requests to one worker thread, the first after $delay seconds;
     * returns the response IDs, the reports keyed by file name, and FrankenPHP's log.
     */
    $frankenWorker = static function (string $mode, array $requests, float $delay = 0) use (
        $frankenphp,
        $stopFrankenphp,
        $phpEnvironment,
        $workerScript,
    ): array {
        $environment = $phpEnvironment;
        unset($environment['HTTP_X_PHP_PROFILER_TRIGGER']);
        $directory = ExceptionFixture::temporaryDirectory('profiler-franken-worker-');
        [$process, $port] = $frankenphp($directory, $environment, mode: $mode, worker: $workerScript);
        try {
            usleep((int) ($delay * 1e6));
            $ids = array_map(static fn(array $request): ?string => serverRequest($port, ...$request), $requests);
        } catch (Throwable $failure) {
            $stopFrankenphp($process, $failure);
        }
        $log = $stopFrankenphp($process);
        $found = [];
        foreach (reports($directory) as $path) {
            $found[basename($path)] = report($path);
        }
        $between = is_file("{$directory}/root/between.json") ? report("{$directory}/root/between.json") : null;
        check(!str_contains($log, INHERITED) && !str_contains($log, 'automatic capture failed'), $log);
        check(
            array_filter($found, static fn(array $report): bool => $report['termination_reason'] !== 'request_complete')
            === [],
            array_keys($found),
        );
        check($between !== null && $between['termination_reason'] === 'request_shutdown', $between, $log);
        return [$ids, $found, $log];
    };
    $secret = 'franken-secret';
    $requests = [
        [null, '/'],
        ['wrong', '/'],
        [$secret, '/'],
        [$secret, '/'],
        [null, '/'],
        ...array_fill(0, 20, [$secret, '/']),
        [$secret, '/throw'],
        [$secret, '/'],
    ];
    [$ids, $found, $log] = $frankenWorker('trigger', $requests);
    $expected = [false, false, true, true, false, ...array_fill(0, 22, true)];
    check(array_map(static fn(?string $identifier): bool => $identifier !== null, $ids) === $expected, $ids, $log);
    $captured = array_values(array_filter($ids, static fn(?string $identifier): bool => $identifier !== null));
    check(count(array_unique($captured)) === count($captured), $captured);
    $names = array_map(static fn(string $identifier): string => "php-profile-{$identifier}.json", $captured);
    sort($names);
    $reportNames = array_keys($found);
    sort($reportNames);
    check($reportNames === $names, $reportNames, $captured);
    // In always mode the worker script's start, up to its first request, is captured as well.
    // The start's report ends before the worker waits for its first request.
    [$ids, $found] = $frankenWorker('always', array_fill(0, 5, [null, '/']), 1.5);
    check(!in_array(null, $ids, true) && count(array_unique($ids)) === 5, $ids);
    $served = array_map(static fn(string $identifier): string => "php-profile-{$identifier}.json", $ids);
    check(count($found) === 6 && array_diff($served, array_keys($found)) === [], $ids, array_keys($found));
    $start = array_values(array_diff_key($found, array_flip($served)))[0];
    check($start['elapsed_wall_ns'] < 1_000_000_000, $start['elapsed_wall_ns']);
    echo "automatic FrankenPHP worker checks passed\n";

    // Outside worker mode FrankenPHP refuses frankenphp_handle_request(); the request keeps
    // its one capture, including what runs after the refused call.
    $classic = <<<'CLASSIC'
        <?php
        function profiler_before_refused_call(): void {}
        function profiler_after_refused_call(): void {}
        profiler_before_refused_call();
        try {
            frankenphp_handle_request(static function (): void {});
            echo 'accepted';
        } catch (RuntimeException $refused) {
            echo 'ok';
        }
        profiler_after_refused_call();

        CLASSIC;
    $environment = $phpEnvironment;
    unset($environment['HTTP_X_PHP_PROFILER_TRIGGER']);
    $directory = ExceptionFixture::temporaryDirectory('profiler-franken-classic-');
    [$process, $port] = $frankenphp($directory, $environment, mode: 'always', script: $classic);
    try {
        $identifier = serverRequest($port, null);
    } catch (Throwable $failure) {
        $stopFrankenphp($process, $failure);
    }
    $log = $stopFrankenphp($process);
    $classicReports = reports($directory);
    check($identifier !== null && count($classicReports) === 1, $identifier, $classicReports, $log);
    $classicReport = report($classicReports[0]);
    $calls = static fn(string $name): int => $classicReport['functions'][$name]['calls'] ?? 0;
    check(
        basename($classicReports[0]) === "php-profile-{$identifier}.json"
        && $classicReport['termination_reason'] === 'request_complete'
        && $calls('profiler_before_refused_call') === 1
        && $calls('profiler_after_refused_call') === 1,
        $classicReport['termination_reason'] ?? null,
        array_keys($classicReport['functions'] ?? []),
        $log,
    );
    echo "automatic FrankenPHP classic-mode checks passed\n";
}

// Apache's mod_php answers getenv from the request, so an inherited variable shadows nothing.
// It needs an Apache built for this PHP, so this runs only where APACHE and APACHE_PHP_MODULE name one.
if ((string) getenv('APACHE') !== '' && (string) getenv('APACHE_PHP_MODULE') !== '') {
    /** The directory holding Apache's own modules, which Homebrew and RHEL keep apart from libphp.so. */
    $apacheModules = static function (): string {
        if ((string) getenv('APACHE_MODULES') !== '') {
            return (string) getenv('APACHE_MODULES');
        }
        $besidePhp = dirname((string) getenv('APACHE_PHP_MODULE'));
        if (file_exists("{$besidePhp}/mod_mpm_prefork.so")) {
            return $besidePhp;
        }
        // Homebrew and source builds install apxs beside httpd; RHEL puts it on PATH with httpd-devel.
        $besideApache = dirname(Paths::which((string) getenv('APACHE')) ?? (string) getenv('APACHE')) . '/apxs';
        $apxs = (string) getenv('APXS') ?: (file_exists($besideApache) ? $besideApache : Paths::which('apxs'));
        if ($apxs !== null && $apxs !== '') {
            $modules = trim(Process::output([$apxs, '-q', 'LIBEXECDIR']));
            if (file_exists("{$modules}/mod_mpm_prefork.so")) {
                return $modules;
            }
        }
        fwrite(
            STDERR,
            "Apache modules not found beside APACHE_PHP_MODULE or through apxs; set APACHE_MODULES to the directory holding mod_mpm_prefork.so\n",
        );
        exit(1);
    };
    /** Stops Apache and returns PHP's log; diagnostics never mask an earlier failure. */
    $stopApache = static function (BackgroundProcess $process, string $directory, ?Throwable $failure = null): string {
        $process->stop(SIGTERM);
        // Apache writes its own log once configured, and startup errors to the console before that.
        $serverLog =
            (string) @file_get_contents("{$directory}/console.log")
            . (string) @file_get_contents("{$directory}/apache.log");
        $log = (string) file_get_contents("{$directory}/php.log");
        if ($failure !== null) {
            throw new RuntimeException($failure->getMessage() . "\n" . $serverLog . $log, 0, $failure);
        }
        SanitizerDiagnostics::reject($serverLog . $log, 'Apache');
        // A worker crash after its response only shows in Apache's log (AH00052).
        check(!str_contains($serverLog, ' exit signal '), $serverLog);
        return $log;
    };
    $apache = static function (string $directory, array $environment) use (
        $extension,
        $apacheModules,
        $stopApache,
    ): array {
        chmod($directory, 0o755);
        [$output, $root, $configuration] = ["{$directory}/out", "{$directory}/root", "{$directory}/conf"];
        foreach ([$output, $root, $configuration] as $path) {
            mkdir($path);
        }
        chmod($output, 0o777);
        $log = "{$directory}/php.log";
        touch($log);
        chmod($log, 0o666);
        file_put_contents(
            "{$configuration}/php.ini",
            "extension={$extension}\nopcache.enable=0\nerror_log={$log}\nphp_profiler.mode=trigger\nphp_profiler.trigger_secret=apache-secret\nphp_profiler.output_dir={$output}\n",
        );
        file_put_contents("{$root}/index.php", '<?php echo "ok";');
        file_put_contents("{$root}/marker.php", "<?php error_log('apache-log-marker'); echo 'ok';");
        $port = HttpClient::freePort();
        $modules = $apacheModules();
        // Running as root, Apache serves from an unprivileged user, as in production.
        $user = posix_geteuid() === 0 ? "User www-data\nGroup www-data\n" : '';
        $module = (string) getenv('APACHE_PHP_MODULE');
        file_put_contents(
            "{$directory}/httpd.conf",
            "ServerRoot {$directory}\nServerName localhost\nListen 127.0.0.1:{$port}\n"
            . "PidFile {$directory}/httpd.pid\nDefaultRuntimeDir {$directory}\nErrorLog {$directory}/apache.log\n"
            . "LoadModule mpm_prefork_module {$modules}/mod_mpm_prefork.so\n"
            . "LoadModule authz_core_module {$modules}/mod_authz_core.so\n"
            . "LoadModule php_module {$module}\n{$user}"
            . "DocumentRoot {$root}\n<Directory {$root}>\n  Require all granted\n</Directory>\n"
            . "StartServers 2\n<FilesMatch \"\\.php\$\">\n  SetHandler application/x-httpd-php\n</FilesMatch>\n",
        );
        $process = new BackgroundProcess(
            [(string) getenv('APACHE'), '-f', "{$directory}/httpd.conf", '-DFOREGROUND'],
            [...$environment, 'PHPRC' => $configuration, 'PHP_INI_SCAN_DIR' => ''],
            log: "{$directory}/console.log",
            mergeOutput: true,
        );
        try {
            $process->waitUntil(static fn(): bool => HttpClient::accepts($port), 'Apache');
        } catch (Throwable $failure) {
            $stopApache($process, $directory, $failure);
        }
        return [$process, $port, $output];
    };
    foreach ([null, 'apache-secret'] as $inherited) {
        $environment = $phpEnvironment;
        unset($environment['HTTP_X_PHP_PROFILER_TRIGGER']);
        if ($inherited !== null) {
            $environment['HTTP_X_PHP_PROFILER_TRIGGER'] = $inherited;
        }
        $directory = ExceptionFixture::temporaryDirectory('profiler-apache-');
        [$process, $port, $output] = $apache($directory, $environment);
        try {
            $captured = array_map(
                static fn(?string $token): bool => serverRequest($port, $token, '/index.php') !== null,
                [null, 'wrong', 'apache-secret,other', 'apache-secret', null],
            );
            // A marker proves the workers write this log, so the absence below is meaningful.
            check(serverRequest($port, null, '/marker.php') === null);
        } catch (Throwable $failure) {
            $stopApache($process, $directory, $failure);
        }
        $log = $stopApache($process, $directory);
        // Apache reads request headers only: even an inherited copy of the secret neither
        // captures a request without the header nor stops a real header from capturing.
        check($captured === [false, false, false, true, false], $inherited, $captured, $log);
        check(count(reports($output)) === 1, $inherited);
        check(str_contains($log, 'apache-log-marker') && !str_contains($log, 'php_profiler'), $inherited, $log);
    }
    echo "automatic Apache trigger checks passed\n";
}
