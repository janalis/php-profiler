<?php

declare(strict_types=1);

require_once __DIR__ . '/support.php';
require_once ROOT . '/scripts/lib/PythonRandom.php';

use PhpProfiler\Tooling\PythonRandom;

/** Recorded from Python 3: [random.Random(seed).shuffle(...) for seed in (0, 1, 2, 7, 2**32)]. */
const EXPECTED_SHUFFLES = [
    ['absent', 'enabled', 'disabled'],
    ['disabled', 'enabled', 'absent'],
    ['disabled', 'enabled', 'absent'],
    ['enabled', 'absent', 'disabled'],
    ['enabled', 'disabled', 'absent'],
];

return [
    'shuffles match Python random' => static function (): void {
        $modes = ['absent', 'disabled', 'enabled'];
        $actual = array_map(static fn(int $seed): array => (new PythonRandom($seed))->shuffle($modes), [
            0,
            1,
            2,
            7,
            2 ** 32,
        ]);
        same(EXPECTED_SHUFFLES, $actual);
    },
];
