<?php

declare(strict_types=1);

require_once __DIR__ . '/support.php';
require_once ROOT . '/scripts/lib/Tar.php';

use PhpProfiler\Tooling\Paths;
use PhpProfiler\Tooling\Process;
use PhpProfiler\Tooling\Tar;

/** PIE source archive safety and reproducibility. */
function packageFixture(): array
{
    $root = temporaryDirectory();
    Paths::copyTree(ROOT . '/scripts/lib', "{$root}/scripts/lib");
    foreach (['php-tool.sh', 'package-source.php'] as $name) {
        Paths::copyFile(ROOT . "/scripts/{$name}", "{$root}/scripts/{$name}");
    }
    writeFile("{$root}/Cargo.toml", "[workspace.package]\nversion = \"0.1.0-alpha.1\"\n");
    foreach (['config.m4', 'Makefile.frag'] as $name) {
        writeFile("{$root}/packaging/{$name}", "fixture\n");
    }
    $git = static fn(string ...$arguments): string => Process::output(['git', '-C', $root, ...$arguments]);
    $git('init', '-q');
    $git('add', 'scripts', 'packaging', 'Cargo.toml');
    $git('-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid', 'commit', '-qm', 'feat: fixture');
    return [$root, $git];
}

function package(string $root, string $output = 'first.tgz'): array
{
    return Process::run([
        "{$root}/scripts/php-tool.sh",
        "{$root}/scripts/package-source.php",
        '--output',
        $output,
    ], directory: $root);
}

/** @return array<string, array{name: string, type: string, data: string, link: string, mode: int}> */
function archiveEntries(string $path): array
{
    $entries = [];
    foreach (Tar::read((string) gzdecode((string) file_get_contents($path))) as $entry) {
        $entries[$entry['name']] = $entry;
    }
    return $entries;
}

return [
    'reproducible and complete' => static function (): void {
        [$root] = packageFixture();
        same(0, package($root)['status']);
        same(0, package($root, 'second.tgz')['status']);
        same(file_get_contents("{$root}/first.tgz"), file_get_contents("{$root}/second.tgz"));
        $entries = archiveEntries("{$root}/first.tgz");
        same(false, isset($entries['package.xml']));
        same(true, isset($entries['php-profiler-0.1.0-alpha.1/packaging/config.m4']));
    },
    'dirty source is rejected' => static function (): void {
        [$root] = packageFixture();
        file_put_contents("{$root}/Cargo.toml", 'changed');
        $result = package($root);
        same(2, $result['status']);
        same(true, str_contains($result['stderr'], 'Commit all tracked changes'));
    },
    'metadata and archive use the same committed version' => static function (): void {
        [$root, $git] = packageFixture();
        file_put_contents("{$root}/Cargo.toml", "[workspace.package]\nversion = \"0.2.0-alpha.1\"\n");
        $git('add', 'Cargo.toml');
        same(2, package($root)['status']);
        same(false, file_exists("{$root}/first.tgz"));
    },
    'development source is supported' => static function (): void {
        [$root, $git] = packageFixture();
        file_put_contents("{$root}/Cargo.toml", "[workspace.package]\nversion = \"0.1.0\"\n");
        $git('add', 'Cargo.toml');
        $git('-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid', 'commit', '-qm', 'chore: stable');
        same(0, package($root)['status']);
    },
    'git modes and vendored sources are preserved' => static function (): void {
        [$root, $git] = packageFixture();
        writeFile("{$root}/vendor/ext-php-rs/src/module.rs", 'patched source');
        writeFile("{$root}/scripts/probe", "#!/bin/sh\nexit 0\n", 0o755);
        symlink('probe', "{$root}/scripts/link");
        $git('add', 'vendor', 'scripts');
        $git(
            '-c',
            'user.name=Fixture',
            '-c',
            'user.email=fixture@example.invalid',
            'commit',
            '-qm',
            'test: source modes',
        );
        same(0, package($root)['status']);
        $entries = archiveEntries("{$root}/first.tgz");
        $prefix = 'php-profiler-0.1.0-alpha.1/';
        same(0o755, $entries[$prefix . 'scripts/probe']['mode']);
        same('symlink', $entries[$prefix . 'scripts/link']['type']);
        same('patched source', $entries[$prefix . 'vendor/ext-php-rs/src/module.rs']['data']);
    },
    'committed export rules exclude only marked files' => static function (): void {
        [$root, $git] = packageFixture();
        writeFile("{$root}/.gitattributes", "/private.txt export-ignore\n");
        writeFile("{$root}/private.txt", 'fixture excluded file');
        writeFile("{$root}/Cargo.lock", 'fixture lock');
        writeFile("{$root}/rust-toolchain.toml", "[toolchain]\nchannel = \"stable\"\n");
        $git('add', '.gitattributes', 'private.txt', 'Cargo.lock', 'rust-toolchain.toml');
        $git(
            '-c',
            'user.name=Fixture',
            '-c',
            'user.email=fixture@example.invalid',
            'commit',
            '-qm',
            'test: archive rules',
        );
        same(0, package($root)['status']);
        $entries = archiveEntries("{$root}/first.tgz");
        same([false, true, true], [
            isset($entries['php-profiler-0.1.0-alpha.1/private.txt']),
            isset($entries['php-profiler-0.1.0-alpha.1/Cargo.lock']),
            isset($entries['php-profiler-0.1.0-alpha.1/rust-toolchain.toml']),
        ]);
    },
    'smoke rejects an archive without a composer root' => static function (): void {
        $root = temporaryDirectory();
        writeFile(
            "{$root}/invalid.tar.gz",
            (string) gzencode(Tar::write([['name' => 'unexpected/README', 'data' => 'x']])),
        );
        writeFile("{$root}/pie.phar", 'unused');
        $environment = withEnvironment([
            'PIE_PHAR' => "{$root}/pie.phar",
            'PHP_CONFIG' => '/usr/bin/true',
            'PHPIZE' => '/usr/bin/true',
            'PHP_BINARY' => '/usr/bin/true',
            'PROFILER_TOOL_PHP' => PHP_BINARY,
        ]);
        $result = Process::run([
            'bash',
            ROOT . '/scripts/package-install-smoke.sh',
            "{$root}/invalid.tar.gz",
        ], $environment);
        same(true, $result['status'] !== 0);
        same(true, str_contains($result['stderr'], 'Expected one PIE source root'), $result['stderr']);
    },
    'smoke extraction refuses members and links that leave the destination' => static function (): void {
        $root = temporaryDirectory();
        foreach ([
            [['name' => '../escape', 'data' => 'x']],
            [['name' => 'source/link', 'type' => 'symlink', 'link' => '../../outside']],
            [['name' => 'source/device', 'type' => 'other']],
            // Each link is inside when written; the second makes the first leave.
            [
                ['name' => 'q', 'type' => 'directory'],
                ['name' => 'a', 'type' => 'symlink', 'link' => 'p/q/../..'],
                ['name' => 'p', 'type' => 'symlink', 'link' => '.'],
            ],
        ] as $index => $entries) {
            writeFile("{$root}/bad{$index}.tar.gz", (string) gzencode(Tar::write($entries)));
            $result = Process::run([
                ROOT . '/scripts/php-tool.sh',
                ROOT . '/scripts/extract-archive.php',
                "{$root}/bad{$index}.tar.gz",
                "{$root}/out{$index}",
            ]);
            same(1, $result['status'], $result['stderr']);
        }
        writeFile(
            "{$root}/good.tar.gz",
            (string) gzencode(Tar::write([
                ['name' => 'source/tool', 'data' => 'x', 'mode' => 0o4755],
                ['name' => 'source/link', 'type' => 'symlink', 'link' => 'tool'],
            ])),
        );
        same(
            0,
            Process::run([
                ROOT . '/scripts/php-tool.sh',
                ROOT . '/scripts/extract-archive.php',
                "{$root}/good.tar.gz",
                "{$root}/good",
            ])['status'],
        );
        same([0o755, 'tool'], [fileperms("{$root}/good/source/tool") & 0o7777, readlink("{$root}/good/source/link")]);
    },
    'smoke extraction replaces repeated members and stops on a failed write' => static function (): void {
        $root = temporaryDirectory();
        $extract = static function (string $name, array $entries) use ($root): array {
            writeFile("{$root}/{$name}.tar.gz", (string) gzencode(Tar::write($entries)));
            return Process::run([
                ROOT . '/scripts/php-tool.sh',
                ROOT . '/scripts/extract-archive.php',
                "{$root}/{$name}.tar.gz",
                "{$root}/{$name}",
            ]);
        };
        $repeated = $extract('repeated', [
            ['name' => 'source/tool', 'data' => 'first'],
            ['name' => 'source/tool', 'data' => 'second'],
            ['name' => 'source/other', 'data' => 'kept'],
            ['name' => 'source/entry', 'type' => 'symlink', 'link' => 'other'],
            ['name' => 'source/entry', 'data' => 'replaced the link'],
        ]);
        same(0, $repeated['status'], $repeated['stderr']);
        same('second', file_get_contents("{$root}/repeated/source/tool"));
        same(false, is_link("{$root}/repeated/source/entry"));
        same(['replaced the link', 'kept'], [
            file_get_contents("{$root}/repeated/source/entry"),
            file_get_contents("{$root}/repeated/source/other"),
        ]);
        $clash = $extract('clash', [
            ['name' => 'source/tool', 'type' => 'directory'],
            ['name' => 'source/tool', 'data' => 'x'],
        ]);
        same(1, $clash['status'], 'a file member replaced a directory');
        same(true, str_contains($clash['stderr'], 'archive member replaces a directory'), $clash['stderr']);
    },
];
