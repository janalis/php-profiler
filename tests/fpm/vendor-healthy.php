<?php

declare(strict_types=1);

header('Content-Type: application/json');
echo
    json_encode([
        'pid' => getmypid(),
        'result' => strlen('healthy'),
        'live_bytes' => profiler_fixture_live_bytes(),
    ], JSON_THROW_ON_ERROR)
;
