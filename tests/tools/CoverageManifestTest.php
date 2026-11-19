<?php

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use PhpProfiler\Tooling\Process;

/** Coverage evidence must fail closed when inputs drift or outputs disappear. */
function coverageManifest(bool $drift = false, bool $missing = false, int $status = 0): array
{
    $parent = temporaryDirectory();
    $run = "{$parent}/run-example";
    writeFile("{$run}/source-before.json", json_encode(['engine.rs' => 'before']));
    writeFile("{$run}/source-after.json", json_encode(['engine.rs' => $drift ? 'after' : 'before']));
    if (!$missing) {
        writeFile("{$run}/coverage.json", '{"measured": true}');
        writeFile("{$run}/reports/b/one.gcov", 'b');
        writeFile("{$run}/reports/a-b/two.gcov", 'a');
    }
    $result = Process::run([
        ROOT . '/scripts/php-tool.sh',
        ROOT . '/scripts/coverage-run-manifest.php',
        $run,
        $parent,
        (string) $status,
        '--artifact',
        'coverage.json',
        '--artifact-directory',
        'reports',
    ]);
    $manifest = json_decode((string) file_get_contents("{$run}/run.json"), true);
    same($manifest, json_decode((string) file_get_contents("{$parent}/latest.json"), true));
    return [$result, $manifest];
}

return [
    'matching inputs and present artifacts preserve success' => static function (): void {
        [$result, $manifest] = coverageManifest();
        same(0, $result['status'], $result['stderr']);
        same(true, $manifest['source_snapshot_matches']);
        same(64, strlen($manifest['artifact_sha256']['coverage.json']));
        // Ordered like sorted() over pathlib paths, component by component.
        same(['coverage.json', 'reports/a-b/two.gcov', 'reports/b/one.gcov'], array_keys($manifest['artifact_sha256']));
    },
    'source drift invalidates an otherwise successful run' => static function (): void {
        [$result, $manifest] = coverageManifest(drift: true);
        same(1, $result['status']);
        same(false, $manifest['source_snapshot_matches']);
        same(['engine.rs'], $manifest['changed_inputs']);
    },
    'missing report invalidates success' => static function (): void {
        [$result, $manifest] = coverageManifest(missing: true);
        same(1, $result['status']);
        same(['coverage.json', 'reports'], $manifest['missing_artifacts']);
    },
    'tool failure is not erased by matching sources' => static function (): void {
        [$result, $manifest] = coverageManifest(status: 7);
        same(7, $result['status']);
        same(7, $manifest['exit_code']);
    },
];
