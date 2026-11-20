<?php

declare(strict_types=1);

// Exercises concurrent FastCGI requests through one reused worker.
require_once __DIR__ . '/lib/FpmServer.php';

use PhpProfiler\Tooling\FpmServer;

// Payloads are checked while FPM still runs, so a failure carries its master's diagnostics.
$results = (new FpmServer(
    (string) getenv('PROFILER_EXTENSION') ?: throw new RuntimeException('PROFILER_EXTENSION is required'),
))->serve(static function (FpmServer $server): array {
    $results = $server->concurrent(array_fill(0, 32, [
        'tests/fpm/request.php',
        ['PROFILE_TEST_INPUT' => 'request'],
    ]), 8);
    foreach ($results as $raw) {
        $payload = FpmServer::decode($raw);
        if ($payload['id'] !== 1 || $payload['result'] !== 7) {
            throw new RuntimeException('request state or semantics leaked');
        }
        $functions = $payload['report']['functions'];
        if (
            !is_array($functions)
            || array_is_list($functions) && $functions !== []
            || ($functions['fpm_profiled_leaf']['calls'] ?? null) !== 1
        ) {
            throw new RuntimeException('wrong per-request count: ' . json_encode($payload));
        }
    }
    return $results;
});
echo 'FPM: ', count($results), " isolated requests through one reused worker passed\n";
