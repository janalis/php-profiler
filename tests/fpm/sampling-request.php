<?php

declare(strict_types=1);

require __DIR__ . '/../support/sampling-workload.php';
$token = filter_var($_SERVER['PROFILE_TEST_TOKEN'] ?? null, FILTER_VALIDATE_INT);
if ($token === false || $token === null)
    throw new RuntimeException('missing isolated request token');
$receipt = sampling_jit_capture($token, ($_SERVER['PROFILE_TEST_JIT'] ?? '') === '1');
header('Content-Type: application/json');
echo json_encode($receipt, JSON_THROW_ON_ERROR), PHP_EOL;
