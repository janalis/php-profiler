<?php

declare(strict_types=1);

// Coordinates exact-SHA quality dispatch, protected candidate merges and PIE releases.
require_once __DIR__ . '/lib/Arguments.php';
require_once __DIR__ . '/lib/Fail.php';
require_once __DIR__ . '/lib/ReleaseAutomation.php';

use PhpProfiler\Tooling\Arguments;
use PhpProfiler\Tooling\ReleaseAutomation;
use PhpProfiler\Tooling\SystemReleaseRunner;

$usage = 'release-automation.php <quality-run-id>';
['positionals' => $positionals] = Arguments::parse($argv, [], usage: $usage);
if (count($positionals) !== 1 || !preg_match('/\A\d+\z/', $positionals[0])) {
    Arguments::fail('expected one quality run id', $usage);
}
(new ReleaseAutomation(new SystemReleaseRunner(), dirname(__DIR__)))->main((int) $positionals[0]);
