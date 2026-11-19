<?php

declare(strict_types=1);

// Resumes a validated PIE alpha release; never overwrites a version.
require_once __DIR__ . '/lib/Arguments.php';
require_once __DIR__ . '/lib/Fail.php';
require_once __DIR__ . '/lib/FinalizeRelease.php';

use PhpProfiler\Tooling\Arguments;
use PhpProfiler\Tooling\FinalizeRelease;
use PhpProfiler\Tooling\SystemReleaseRunner;

$usage = 'finalize-release.php <version> <archive> --quality-run-id <id>';
['options' => $options, 'positionals' => $positionals] = Arguments::parse($argv, ['quality-run-id'], usage: $usage);
if (count($positionals) !== 2 || !preg_match('/\A\d+\z/', (string) ($options['quality-run-id'] ?? ''))) {
    Arguments::fail('expected a version, an archive and --quality-run-id', $usage);
}
if (!preg_match('/\A0\.\d+\.\d+-alpha\.[1-9]\d*\z/', $positionals[0])) {
    Arguments::fail('Only alpha publication is supported', $usage);
}
(new FinalizeRelease(new SystemReleaseRunner(), dirname(__DIR__)))->main(
    $positionals[0],
    $positionals[1],
    (int) $options['quality-run-id'],
);
