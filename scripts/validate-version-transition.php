<?php

declare(strict_types=1);

// Proves, and with --apply writes, a version-only change to the C proof ledger.
require_once __DIR__ . '/lib/Arguments.php';
require_once __DIR__ . '/lib/Fail.php';
require_once __DIR__ . '/lib/Json.php';
require_once __DIR__ . '/lib/Process.php';
require_once __DIR__ . '/lib/VersionTransition.php';

use PhpProfiler\Tooling\Arguments;
use PhpProfiler\Tooling\Json;
use PhpProfiler\Tooling\Process;
use PhpProfiler\Tooling\VersionTransition;

$usage = 'validate-version-transition.php <base> <version> [--apply]';
['options' => $options, 'positionals' => $positionals] = Arguments::parse($argv, [], ['apply'], $usage);
if (count($positionals) !== 2) {
    Arguments::fail('expected a base revision and a version', $usage);
}
[$base, $version] = $positionals;
$root = dirname(__DIR__);
$git = static fn(string ...$arguments): string => Process::output(['git', '-C', $root, ...$arguments]);
$baseSha = trim($git('rev-parse', '--verify', $base . '^{commit}'));
$changed = array_values(array_filter(explode("\n", $git('diff', '--name-only', $baseSha, '--'))));
$unexpected = array_diff($changed, VersionTransition::ALLOWED);
if ($unexpected !== []) {
    sort($unexpected, SORT_STRING);
    throw new RuntimeException('Unexpected release transition files: ' . implode(', ', $unexpected));
}
$readBase = static fn(string $name): string => $git('show', "{$baseSha}:{$name}");
$readTarget = static fn(string $name): string => (string) file_get_contents("{$root}/{$name}");
[$expected, $updates] = VersionTransition::expected($readBase, $readTarget, $version);
$actual = $readTarget(VersionTransition::LEDGER);
if (!in_array($actual, [$readBase(VersionTransition::LEDGER), $expected], true)) {
    throw new RuntimeException('Target ledger changes more than the two derived dependency hashes');
}
if (isset($options['apply'])) {
    file_put_contents("{$root}/" . VersionTransition::LEDGER, $expected);
} elseif ($actual !== $expected) {
    throw new RuntimeException('Target ledger is not the exact expected version transition');
}
$receipt = [
    'schema_version' => 1,
    'base_sha' => $baseSha,
    'version' => $version,
    'base_ledger_sha256' => hash('sha256', $readBase(VersionTransition::LEDGER)),
    'candidate_ledger_sha256' => hash('sha256', $expected),
    'dependency_updates' => $updates,
    'unchanged_dependency_count' => count(VersionTransition::DEPENDENCIES) - 2,
    'proof_status' => 'unchanged; fresh NTS/ZTS native context validation and Mull still required',
];
$destination = "{$root}/target/version-transition.json";
if (!is_dir(dirname($destination))) {
    mkdir(dirname($destination), 0o777, true);
}
file_put_contents($destination, Json::encode($receipt, indent: 2, sortKeys: false) . "\n");
echo $destination, "\n";
