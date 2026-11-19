<?php

declare(strict_types=1);

// Fingerprints production, build and test inputs without relying on a Git checkout.
require __DIR__ . '/lib/Json.php';
require __DIR__ . '/lib/ValidationInputs.php';

use PhpProfiler\Tooling\Json;
use PhpProfiler\Tooling\ValidationInputs;

$root = realpath($argv[1] ?? '') ?: throw new RuntimeException('usage: coverage-snapshot.php <workspace>');
echo Json::encode(ValidationInputs::snapshot($root), indent: 2), "\n";
