<?php

declare(strict_types=1);

// Proves automatic capture isolation in real concurrent ZTS embed requests.
require_once __DIR__ . '/lib/EmbedHarness.php';
require_once __DIR__ . '/lib/ExceptionFixture.php';
require_once __DIR__ . '/lib/Paths.php';

use PhpProfiler\Tooling\EmbedHarness;
use PhpProfiler\Tooling\ExceptionFixture;
use PhpProfiler\Tooling\Paths;

use function PhpProfiler\Tooling\fail;
use function PhpProfiler\Tooling\requiredEnvironment;

$library = EmbedHarness::library();
$directory = ExceptionFixture::temporaryDirectory('profiler-automatic-zts-');
$output = "{$directory}/reports";
$receipt = "{$directory}/receipts";
mkdir($output);
mkdir($receipt);
file_put_contents("{$directory}/closure-worker-functions.php", '<?php // embed harness warmup companion');
$fixture = "{$directory}/request.php";
file_put_contents($fixture, '<?php function automatic_thread_leaf() { usleep(10000); } automatic_thread_leaf();');
$executable = "{$directory}/threads";
EmbedHarness::build($library, $executable);
$extension = Paths::resolve(requiredEnvironment('PROFILER_EXTENSION'));
file_put_contents(
    "{$directory}/php.ini",
    "extension=\"{$extension}\"\nphp_profiler.mode=always\nphp_profiler.output_dir=\"{$output}\"\ndisplay_errors=stderr\nlog_errors=1\nmax_execution_time=0\n",
);
EmbedHarness::run([$executable, "{$directory}/php.ini", $output, $fixture, $receipt]);
$paths = glob("{$output}/*.json") ?: [];
// 80 workers plus the embed startup and shutdown requests.
count($paths) === 82 || fail('expected 82 reports, got ' . count($paths));
$captures = 0;
foreach ($paths as $path) {
    $report = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    if (
        $report['termination_reason'] !== 'request_complete'
        || $report['status'] !== 'complete'
        || $report['runtime_metadata']['zts'] !== 'true'
    ) {
        fail('incomplete or non-ZTS automatic report: ' . $path);
    }
    $leaf = $report['functions']['automatic_thread_leaf'] ?? null;
    if ($leaf) {
        $leaf['calls'] === 1
            || fail('automatic thread leaf counted ' . json_encode($leaf['calls']) . ' calls in ' . $path);
        $captures++;
    }
}
$captures === 80 || fail("expected 80 worker captures, got {$captures}");
echo "automatic ZTS: 80 isolated worker captures across four concurrent pthreads plus two main requests\n";
