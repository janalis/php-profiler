<?php

declare(strict_types=1);

// Validates real native JSON reports against the published schemas; no remote refs.
require_once __DIR__ . '/lib/ExceptionFixture.php';
require_once __DIR__ . '/lib/Fail.php';
require_once __DIR__ . '/lib/JsonSchema.php';
require_once __DIR__ . '/lib/Process.php';

use PhpProfiler\Tooling\ExceptionFixture;
use PhpProfiler\Tooling\JsonSchema;
use PhpProfiler\Tooling\Process;

use function PhpProfiler\Tooling\fail;
use function PhpProfiler\Tooling\requiredEnvironment;

$root = dirname(__DIR__);
chdir($root);
$validators = [];
foreach ([1, 2] as $version) {
    $validators[$version] = new JsonSchema(json_decode(
        (string) file_get_contents("{$root}/schema/report-v{$version}.json"),
        false,
        flags: JSON_THROW_ON_ERROR,
    ));
}
$command = [(string) getenv('PHP') ?: 'php', '-n', '-d', 'extension=' . requiredEnvironment('PROFILER_EXTENSION')];
$reports = json_decode(
    Process::output([...$command, 'tests/native/schema-reports.php']),
    false,
    flags: JSON_THROW_ON_ERROR,
);
$requireSampling = getenv('CI_PROFILER_REQUIRE_SAMPLING') === '1';
foreach ($reports as $index => $report) {
    $version = $report->schema_version ?? null;
    if (!is_int($version) || !isset($validators[$version])) {
        fail("report {$index}: unsupported schema version " . json_encode($version));
    }
    $errors = $validators[$version]->errors($report);
    if ($errors !== []) {
        fail("report {$index}: " . implode("\n", $errors));
    }
}
$versions = array_map(static fn(stdClass $report): int => $report->schema_version, $reports);
in_array(count($reports), [3, 5], true) || fail('expected 3 or 5 reports, got ' . count($reports));
if ($requireSampling && count($reports) !== 5) {
    fail('supported CI platform must validate native v2 sampling reports');
}
count(array_keys($versions, 1, true)) === 3 || fail('expected three version 1 reports');
count(array_keys($versions, 2, true)) === (count($reports) - 3) || fail('unexpected version 2 report count');
$statuses = array_unique(array_map(static fn(stdClass $report): string => $report->status, $reports));
array_diff(['complete', 'truncated'], $statuses) === [] || fail('reports must include complete and truncated statuses');
$distinct = array_values(array_unique($versions));
sort($distinct);
echo
    'published JSON Schemas validate all ',
    count($reports),
    ' real native reports (versions [',
    implode(', ', $distinct),
    "])\n"
;

// Automatic lifecycle reasons and configured sampling use the same schemas.
$directory = ExceptionFixture::temporaryDirectory('profiler-auto-schema-');
foreach (['0', '1'] as $sampled) {
    $result = Process::run([
        ...$command,
        '-d',
        'php_profiler.mode=always',
        '-d',
        'php_profiler.output_dir=' . $directory,
        '-d',
        'php_profiler.sampling=' . $sampled,
        '-r',
        'function automatic_schema_leaf() {} automatic_schema_leaf();',
    ]);
    $result['status'] === 0 || fail('automatic schema request failed: ' . $result['stdout'] . $result['stderr']);
    $paths = glob("{$directory}/*.json") ?: [];
    if ($paths === []) {
        $sampled === '1' && !$requireSampling
            || fail('automatic request wrote no report: ' . $result['stdout'] . $result['stderr']);
        continue;
    }
    count($paths) === 1 || fail('expected one automatic report, got ' . count($paths));
    $report = json_decode((string) file_get_contents($paths[0]), false, flags: JSON_THROW_ON_ERROR);
    $errors = ($validators[$report->schema_version] ?? fail('unsupported automatic report version'))->errors($report);
    $errors === [] || fail('automatic report: ' . implode("\n", $errors));
    $report->termination_reason === 'request_complete' || fail('automatic report did not complete its request');
    unlink($paths[0]);
}
echo "automatic request reports also validate against published schemas\n";
