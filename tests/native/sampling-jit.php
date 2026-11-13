<?php

declare(strict_types=1);

require __DIR__ . '/../support/sampling-workload.php';
echo json_encode(sampling_jit_capture(17, ($argv[1] ?? '') === 'jit'), JSON_THROW_ON_ERROR), PHP_EOL;
