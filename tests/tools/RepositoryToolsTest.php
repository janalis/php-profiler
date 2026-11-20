<?php

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use PhpProfiler\Tooling\Process;

return [
    'clean preserves evidence and tools' => static function (): void {
        $root = temporaryDirectory();
        writeFile(
            $root . '/scripts/clean-builds.sh',
            (string) file_get_contents(ROOT . '/scripts/clean-builds.sh'),
            0o755,
        );
        foreach (['debug', 'release', 'package-build', 'package-smoke', 'tools', 'historical-evidence'] as $name) {
            writeFile("{$root}/target/{$name}/receipt", $name);
        }
        mkdir($root . '/outside');
        writeFile($root . '/outside/kept', 'kept');
        unlink($root . '/target/release/receipt');
        rmdir($root . '/target/release');
        symlink($root . '/outside', $root . '/target/release');
        same(0, Process::run([$root . '/scripts/clean-builds.sh'])['status']);
        $left = array_values(array_diff(scandir($root . '/target'), ['.', '..']));
        same(['historical-evidence', 'tools'], $left);
        same('kept', file_get_contents($root . '/outside/kept'), 'a symlinked output must be unlinked, not followed:');
    },
    'the tooling check refuses Python files, commands and dependency scans' => static function (): void {
        // Built at runtime, so this file holds none of the strings the check refuses.
        $py = 'py';
        $cases = [
            'README.md' => ['make check', 0],
            'install.sh' => ["{$py}thon3 -m venv x", 1],
            'setup.sh' => ["pi{$py[0]} install x", 1],
            "tool.{$py}" => ['', 1],
            '.github/dependabot.yml' => ["  - package-ecosystem: pi{$py[0]}\n", 1],
            'quoted/dependabot.yml' => ["  - package-ecosystem: \"pi{$py[0]}\"\n", 1],
            'other/dependabot.yml' => ["  - package-ecosystem: composer\n", 0],
        ];
        foreach ($cases as $path => [$contents, $expected]) {
            $root = temporaryDirectory();
            writeFile(
                $root . '/scripts/check-tooling.sh',
                (string) file_get_contents(ROOT . '/scripts/check-tooling.sh'),
                0o755,
            );
            writeFile("{$root}/{$path}", $contents);
            Process::check(['git', '-C', $root, 'init', '-q']);
            Process::check(['git', '-C', $root, 'add', '.']);
            $result = Process::run([$root . '/scripts/check-tooling.sh']);
            same($expected, $result['status'], $path . ': ' . $result['stderr']);
        }
    },
    'conventional titles' => static function (): void {
        $cases = [
            'fix(api): isolate requests' => true,
            'feat!: new API' => true,
            'chore(release): prepare 0.1.0-alpha.1' => true,
            'unstructured title' => false,
            "fix: okay\nfeat: injected" => false,
            "fix: okay\rinjected" => false,
            "fix(a\nb): scope" => false,
            'fix: ' => false,
            'fixes: wrong type' => false,
        ];
        foreach ($cases as $title => $accepted) {
            $result = Process::run([ROOT . '/scripts/check-pr-title.sh'], withEnvironment([
                'PR_TITLE' => (string) $title,
            ]));
            same($accepted, $result['status'] === 0, var_export($title, true) . ':');
        }
        same(1, Process::run([ROOT . '/scripts/check-pr-title.sh'], array_diff_key(Process::environment(), [
            'PR_TITLE' => true,
        ]))['status']);
    },
    'pinned tool versions match whole versions only' => static function (): void {
        $bin = temporaryDirectory();
        $tool = static fn(string $output): string => "#!/bin/sh\nprintf '%s\\n' " . escapeshellarg($output) . "\n";
        writeFile($bin . '/rustc', $tool('rustc 1.99.1 (abc 2026-01-01)'), 0o755);
        $check = static function (string $mago, string $clang) use ($bin, $tool): int {
            writeFile($bin . '/mago', $tool($mago), 0o755);
            writeFile($bin . '/clang-format', $tool($clang), 0o755);
            $environment = withEnvironment(['PATH' => $bin . ':' . getenv('PATH')]);
            return Process::run(
                [ROOT . '/scripts/check-tool-versions.sh', $bin . '/mago', $bin . '/clang-format'],
                $environment,
            )['status'];
        };
        same(0, $check('mago 1.28.0', 'clang-format version 23.1.3'));
        same(1, $check('mago 1.28.01', 'clang-format version 23.1.3'));
        same(1, $check('mago 11.28.0', 'clang-format version 23.1.3'));
        same(1, $check('mago 1.28.0', 'clang-format version 23.1.30'));
        same(1, $check('mago 1x28x0', 'clang-format version 23.1.3'));
    },
];
