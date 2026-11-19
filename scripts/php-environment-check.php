<?php

declare(strict_types=1);

// Verifies an actual PHP and SDK installation against the requested CI matrix label.
require __DIR__ . '/lib/Arguments.php';
require __DIR__ . '/lib/Json.php';
require __DIR__ . '/lib/Process.php';

use PhpProfiler\Tooling\Arguments;
use PhpProfiler\Tooling\Json;
use PhpProfiler\Tooling\Process;

$usage = 'php-environment-check.php --version <major.minor> --zts nts|ts';
['options' => $options, 'positionals' => $positionals] = Arguments::parse($argv, ['version', 'zts'], usage: $usage);
$expectedMinor = $options['version'] ?? null;
$expectedZts = $options['zts'] ?? null;
if ($positionals !== [] || !is_string($expectedMinor) || !in_array($expectedZts, ['nts', 'ts'], true)) {
    Arguments::fail('--version and --zts nts|ts are required', $usage);
}
$php = getenv('PHP') ?: 'php';
$config = getenv('PHP_CONFIG') ?: 'php-config';
$metadata = json_decode(
    Process::output([
        $php,
        '-n',
        '-r',
        "echo json_encode(['php_version'=>PHP_VERSION,'zts'=>(bool)PHP_ZTS,'sapi'=>PHP_SAPI],JSON_THROW_ON_ERROR);",
    ]),
    true,
    flags: JSON_THROW_ON_ERROR,
);
$headerVersion = trim(Process::output([$config, '--version']));
$fail = static function (string $message): never {
    fwrite(STDERR, $message . "\n");
    exit(1);
};
if (!is_array($metadata) || !is_string($metadata['php_version'] ?? null)) {
    $fail('PHP did not report its version');
}
$actualMinor = implode('.', array_slice(explode('.', $metadata['php_version']), 0, 2));
if ($actualMinor !== $expectedMinor) {
    $fail("PHP minor label mismatch: requested {$expectedMinor}, actual {$metadata['php_version']}");
}
if (!is_bool($metadata['zts'] ?? null) || $metadata['zts'] !== ($expectedZts === 'ts')) {
    $fail('PHP ZTS label mismatch: requested ' . $expectedZts . ', actual ' . json_encode($metadata['zts'] ?? null));
}
if (($metadata['sapi'] ?? null) !== 'cli') {
    $fail('Environment preflight requires the CLI companion binary');
}
if ($headerVersion !== $metadata['php_version']) {
    $fail("php-config version mismatch: headers {$headerVersion}, runtime {$metadata['php_version']}");
}
$metadata += [
    'expected_minor' => $expectedMinor,
    'expected_zts' => $expectedZts,
    'php_config_version' => $headerVersion,
];
echo Json::encode($metadata), "\n";
