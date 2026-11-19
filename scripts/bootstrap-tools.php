<?php

declare(strict_types=1);

// Installs checksum-pinned development tools locally, never into host directories.
require_once __DIR__ . '/lib/Fail.php';
require_once __DIR__ . '/lib/Process.php';
require_once __DIR__ . '/lib/Tar.php';

use PhpProfiler\Tooling\Process;
use PhpProfiler\Tooling\Tar;

/** @param array<string, mixed> $manifest */
function cohort(array $manifest, string $system, string $machine): array
{
    $key = "{$system}/{$machine}";
    return $manifest[$key] ?? throw new RuntimeException("Unsupported development tool platform: {$key}");
}

/**
 * Downloads a pinned archive, verifies its checksum and installs exactly one executable:
 * the single regular member named $name of a .tar.gz, or the given member of a wheel.
 *
 * @param array{url: string, sha256: string, member?: string} $source
 * @param (callable(array): string)|null $download
 */
function installArchive(
    string $name,
    array $source,
    string $destination,
    ?callable $download = null,
    ?callable $replace = null,
): void {
    $download ??= static fn(array $command): string => Process::output($command);
    $replace ??= rename(...);
    $data = $download(['curl', '--fail', '--show-error', '--location', '--max-time', '60', $source['url']]);
    if (hash('sha256', $data) !== $source['sha256']) {
        throw new RuntimeException("{$name} archive checksum mismatch");
    }
    if (isset($source['member'])) {
        $executable = wheelMember($data, $source['member']);
    } else {
        $candidates = array_values(array_filter(
            Tar::read((string) gzdecode($data)),
            static fn(array $entry): bool => basename($entry['name']) === $name,
        ));
        if (count($candidates) !== 1 || $candidates[0]['type'] !== 'file') {
            throw new RuntimeException("Expected exactly one regular {$name} executable");
        }
        $executable = $candidates[0]['data'];
    }
    if (!is_dir($destination)) {
        mkdir($destination, 0o777, true);
    }
    // Atomic replacement leaves an existing installation intact on failure.
    $temporary = tempnam($destination, ".{$name}-") ?: throw new RuntimeException('create temporary file');
    try {
        file_put_contents($temporary, $executable);
        chmod($temporary, 0o755);
        $replace($temporary, "{$destination}/{$name}") || throw new RuntimeException("install {$name}");
    } finally {
        if (file_exists($temporary)) {
            unlink($temporary);
        }
    }
}

/** Reads one member of a wheel (a zip archive) through unzip, which needs no PHP extension. */
function wheelMember(string $wheel, string $member): string
{
    $file = tempnam(sys_get_temp_dir(), 'php-profiler-wheel-') ?: throw new RuntimeException('create temporary wheel');
    try {
        file_put_contents($file, $wheel);
        $listing = Process::output(['unzip', '-Z1', $file]);
        if (!in_array($member, explode("\n", $listing), true)) {
            throw new RuntimeException("Expected the wheel member {$member}");
        }
        return Process::output(['unzip', '-p', $file, $member]);
    } finally {
        unlink($file);
    }
}

if (realpath($argv[0] ?? '') === __FILE__) {
    $root = dirname(__DIR__);
    $manifest = json_decode(
        (string) file_get_contents("{$root}/scripts/tool-manifest.json"),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $tools = cohort($manifest, php_uname('s'), php_uname('m'));
    $destination = "{$root}/target/tools/bin";
    foreach ($tools as $name => $source) {
        installArchive($name, $source, $destination);
    }
    foreach (array_keys($tools) as $name) {
        Process::check(["{$destination}/{$name}", $name === 'actionlint' ? '-version' : '--version']);
    }
}
