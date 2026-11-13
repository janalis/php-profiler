<?php

declare(strict_types=1);

$profiler_test_token = (int) ($_SERVER['PROFILE_TEST_TOKEN'] ?? 0);
$before = function_exists('opcache_get_status') ? opcache_get_status(false) : false;
require __DIR__ . '/../support/closure-worker-request.php';
$profiler_worker_payload['jit_before'] = $before['jit'] ?? null;
header('Content-Type: application/json');
echo json_encode($profiler_worker_payload, JSON_THROW_ON_ERROR);
