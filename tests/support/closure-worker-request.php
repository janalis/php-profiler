<?php

declare(strict_types=1);

$preloaded = function_exists('profiler_worker_exercise');
if (!$preloaded) {
    require __DIR__ . '/closure-worker-functions.php';
}
$profiler_worker_payload = profiler_worker_exercise($profiler_test_token, $profiler_test_output ?? null);
$profiler_worker_payload['preloaded'] = $preloaded;
if (isset($profiler_test_receipt)) {
    $receipt = $profiler_worker_payload;
    unset($receipt['report']);
    file_put_contents($profiler_test_receipt, json_encode($receipt, JSON_THROW_ON_ERROR));
}
