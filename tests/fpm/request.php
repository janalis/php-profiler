<?php

declare(strict_types=1);

use PhpProfiler\Profiler;

function fpm_profiled_leaf(string $input): int
{
    usleep(1);
    return strlen($input);
}

$session = Profiler::start();
$result = fpm_profiled_leaf($_SERVER['PROFILE_TEST_INPUT'] ?? 'request');
Profiler::stop($session);
header('Content-Type: application/json');
echo
    json_encode([
        'pid' => getmypid(),
        'id' => $session->getId(),
        'result' => $result,
        'report' => Profiler::getReport($session),
    ], JSON_THROW_ON_ERROR)
;
