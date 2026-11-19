<?php

declare(strict_types=1);

require_once __DIR__ . '/support.php';
require_once ROOT . '/scripts/bootstrap-tools.php';
require_once ROOT . '/scripts/lib/Tar.php';

use PhpProfiler\Tooling\Tar;

/** Pinned tool downloads reject ambiguous archives and preserve prior installs. */
function toolArchive(array $names, bool $symbolic = false): string
{
    return (string) gzencode(Tar::write(array_map(static fn(string $name): array => (
        $symbolic ? ['name' => $name, 'type' => 'symlink', 'link' => '/bin/sh'] : ['name' => $name, 'data' => 'tool']
    ), $names)));
}

function installTool(string $destination, string $data, ?string $digest = null, ?callable $replace = null): void
{
    installArchive(
        'tool',
        ['url' => 'https://example.invalid/tool', 'sha256' => $digest ?? hash('sha256', $data)],
        $destination,
        static fn(): string => $data,
        $replace,
    );
}

function listing(string $directory): array
{
    return array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
}

return [
    'install only the named member' => static function (): void {
        $destination = temporaryDirectory();
        installTool($destination, toolArchive(['../../tool', 'ignored']));
        same(['tool', 0o755], [file_get_contents("{$destination}/tool"), fileperms("{$destination}/tool") & 0o777]);
    },
    'checksum mismatch installs nothing' => static function (): void {
        $destination = temporaryDirectory();
        $error = throws(RuntimeException::class, static fn() => installTool(
            $destination,
            toolArchive(['tool']),
            str_repeat('0', 64),
        ));
        same([true, []], [str_contains($error->getMessage(), 'checksum mismatch'), listing($destination)]);
    },
    'missing, ambiguous and symlink members are refused' => static function (): void {
        foreach ([toolArchive(['other']), toolArchive(['tool', 'nested/tool']), toolArchive(['tool'], true)] as $data) {
            $error = throws(RuntimeException::class, static fn() => installTool(temporaryDirectory(), $data));
            same(true, str_contains($error->getMessage(), 'one regular'), $error->getMessage());
        }
    },
    'replace failure preserves the previous binary and cleans the temporary file' => static function (): void {
        $destination = temporaryDirectory();
        file_put_contents("{$destination}/tool", 'previous');
        throws(RuntimeException::class, static fn() => installTool(
            $destination,
            toolArchive(['tool']),
            replace: static fn(): bool => false,
        ));
        same(['previous', ['tool']], [file_get_contents("{$destination}/tool"), listing($destination)]);
    },
    'unsupported platform is refused' => static function (): void {
        $error = throws(RuntimeException::class, static fn() => cohort([], 'Windows', 'arm64'));
        same(true, str_contains($error->getMessage(), 'Unsupported'));
    },
    'download failure installs nothing' => static function (): void {
        $destination = temporaryDirectory();
        throws(RuntimeException::class, static fn() => installArchive(
            'tool',
            ['url' => 'invalid', 'sha256' => 'unused'],
            $destination,
            static fn(): string => throw new RuntimeException('fixture'),
        ));
        same([], listing($destination));
    },
    'a wheel member is extracted exactly' => static function (): void {
        $directory = temporaryDirectory();
        writeFile("{$directory}/clang_format/data/bin/clang-format", 'binary');
        writeFile("{$directory}/clang_format/README", '');
        exec('cd ' . escapeshellarg($directory) . ' && zip -qr wheel.whl clang_format', $output, $status);
        same(0, $status);
        $wheel = (string) file_get_contents("{$directory}/wheel.whl");
        $destination = temporaryDirectory();
        installArchive(
            'clang-format',
            [
                'url' => 'https://example.invalid/wheel',
                'sha256' => hash('sha256', $wheel),
                'member' => 'clang_format/data/bin/clang-format',
            ],
            $destination,
            static fn(): string => $wheel,
        );
        same('binary', file_get_contents("{$destination}/clang-format"));
        throws(RuntimeException::class, static fn() => installArchive(
            'clang-format',
            ['url' => 'x', 'sha256' => hash('sha256', $wheel), 'member' => 'missing'],
            $destination,
            static fn(): string => $wheel,
        ));
    },
    'the manifest pins every tool for every platform' => static function (): void {
        $manifest = json_decode((string) file_get_contents(ROOT . '/scripts/tool-manifest.json'), true);
        foreach ($manifest as $platform => $tools) {
            same(['mago', 'shellcheck', 'actionlint', 'clang-format'], array_keys($tools), $platform);
            foreach ($tools as $tool) {
                same(1, preg_match('/\A[0-9a-f]{64}\z/', $tool['sha256']));
                same(true, str_starts_with($tool['url'], 'https://'));
            }
        }
    },
];
