<?php

declare(strict_types=1);

// Verifies PHP filesystem policy against real writes, including symlinks and tightening.
require_once __DIR__ . '/lib/ExceptionFixture.php';
require_once __DIR__ . '/lib/Fail.php';
require_once __DIR__ . '/lib/Process.php';

use PhpProfiler\Tooling\ExceptionFixture;
use PhpProfiler\Tooling\Process;

use function PhpProfiler\Tooling\fail;
use function PhpProfiler\Tooling\requiredEnvironment;

$extension = requiredEnvironment('PROFILER_EXTENSION');
$code = (string) file_get_contents(dirname(__DIR__) . '/tests/native/basedir.php');
if (str_starts_with($code, '<?php')) {
    $code = substr($code, 5);
}
$environment = Process::childEnvironment();
$root = ExceptionFixture::temporaryDirectory('php-profiler-basedir-');
$allowed = "{$root}/allowed";
$outside = "{$root}/outside";
mkdir($allowed);
mkdir($outside);
symlink($outside, "{$allowed}/link");
$targets = [
    'allowed' => "{$allowed}/allowed.json",
    'outside' => "{$outside}/outside.json",
    'symlink' => "{$allowed}/link/symlink.json",
    'tightening' => "{$outside}/tightening.json",
    'relative' => "{$allowed}/relative.json",
    'parent-denied' => "{$allowed}/parent-denied.json",
];
foreach ($targets as $mode => $target) {
    if ($mode === 'parent-denied') {
        file_put_contents($target, 'existing-target-payload');
    }
    $initial = match ($mode) {
        'parent-denied' => $target,
        'tightening' => $root,
        default => $allowed,
    };
    $result = Process::run(
        [
            'php',
            '-n',
            '-d',
            'extension=' . $extension,
            '-d',
            'open_basedir=' . $initial,
            '-r',
            $code,
            $mode === 'relative' ? basename($target) : $target,
            $allowed,
            $mode,
        ],
        $environment,
        $outside,
    );
    if ($result['status'] !== 0 || $result['stderr'] !== '' || str_contains($result['stdout'], 'Warning:')) {
        fail("{$mode}: policy test failed: {$result['stdout']}{$result['stderr']}");
    }
    if ($mode === 'parent-denied' && file_get_contents($target) !== 'existing-target-payload') {
        fail('exact-file policy failed to preserve existing target');
    }
    if (file_exists($target) !== in_array($mode, ['allowed', 'relative', 'parent-denied'], true)) {
        fail("{$mode}: OS filesystem disagrees with PHP policy");
    }
    if ($mode === 'relative' && file_exists("{$outside}/relative.json")) {
        fail('relative report bypassed PHP virtual CWD');
    }
    echo trim($result['stdout']), "\n";
}
