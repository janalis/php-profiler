<?php

declare(strict_types=1);

require_once __DIR__ . '/support.php';
require_once dirname(__DIR__, 2) . '/scripts/lib/BackgroundProcess.php';
require_once dirname(__DIR__, 2) . '/scripts/lib/HttpClient.php';
require_once dirname(__DIR__, 2) . '/scripts/lib/SanitizerDiagnostics.php';

use PhpProfiler\Tooling\BackgroundProcess;
use PhpProfiler\Tooling\HttpClient;
use PhpProfiler\Tooling\Process;
use PhpProfiler\Tooling\SanitizerDiagnostics;

return [
    'a tooling warning fails the script, unless silenced or a deprecation' => static function (): void {
        $root = temporaryDirectory();
        $run = static function (string $code) use ($root): array {
            writeFile("{$root}/script.php", "<?php\n{$code}\necho 'reached';\n");
            return Process::run([ROOT . '/scripts/php-tool.sh', "{$root}/script.php"]);
        };
        // A missing key in a decoded report, which Python's KeyError failed on.
        $missing = $run('$report = []; $timeline = $report["timeline"];');
        same([255, ''], [$missing['status'], $missing['stdout']], $missing['stderr']);
        same(true, str_contains($missing['stderr'], 'Undefined array key "timeline"'), $missing['stderr']);
        $silenced = $run('$contents = @file_get_contents("/nonexistent/profiler-file");');
        same([0, 'reached'], [$silenced['status'], $silenced['stdout']], $silenced['stderr']);
        $deprecated = $run('trigger_error("old", E_USER_DEPRECATED);');
        same([0, 'reached'], [$deprecated['status'], $deprecated['stdout']], $deprecated['stderr']);
    },
    'a repeated header is joined, like http.client getheader()' => static function (): void {
        $headers = [['X-Php-Profiler-Id', 'one'], ['Content-Type', 'text/html'], ['x-php-profiler-id', 'two']];
        same('one, two', HttpClient::header($headers, 'X-PHP-Profiler-Id'));
        same('text/html', HttpClient::header($headers, 'content-type'));
        same(null, HttpClient::header($headers, 'Missing'));
    },
    'a log the sanitizer pattern cannot scan is a failure' => static function (): void {
        $limit = (string) ini_get('pcre.backtrack_limit');
        $jit = (string) ini_get('pcre.jit');
        ini_set('pcre.jit', '0');
        ini_set('pcre.backtrack_limit', '1');
        try {
            $failure = throws(RuntimeException::class, static fn() => SanitizerDiagnostics::reject(
                str_repeat("ordinary log line\n", 100),
                'test',
            ));
            same(true, str_contains($failure->getMessage(), 'cannot scan test output'), $failure->getMessage());
        } finally {
            ini_set('pcre.backtrack_limit', $limit);
            ini_set('pcre.jit', $jit);
        }
        SanitizerDiagnostics::reject("ordinary log line\n", 'test');
    },
    'a server that ignores its stop signal fails only when it must exit' => static function (): void {
        $start = static fn(): BackgroundProcess => new BackgroundProcess(
            ['sh', '-c', 'trap "" INT; echo started >&2; while :; do sleep 1; done'],
            Process::environment(),
        );
        $server = $start();
        $server->waitUntil(static fn(): bool => str_contains($server->log(), 'started'), 'stubborn server');
        same(true, str_contains($server->stop(SIGINT), 'started'));
        $server = $start();
        $server->waitUntil(static fn(): bool => str_contains($server->log(), 'started'), 'stubborn server');
        $failure = throws(RuntimeException::class, static fn() => $server->stop(SIGINT, mustExit: true));
        same(true, str_contains($failure->getMessage(), 'did not exit within 10 seconds'), $failure->getMessage());
    },
];
