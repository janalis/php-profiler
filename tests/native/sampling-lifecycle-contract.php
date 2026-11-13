<?php

declare(strict_types=1);

$mode = $argv[1];
if (!profiler_test_sampling_lifecycle_contract($mode))
    throw new RuntimeException('native lifecycle contract failed: ' . $mode);
echo 'lifecycle native ' . $mode . " PASS\n";
