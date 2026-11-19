<?php

declare(strict_types=1);

require_once __DIR__ . '/support.php';
require_once ROOT . '/scripts/lib/ValidationInputs.php';

use PhpProfiler\Tooling\ValidationInputs;

return [
    'recipes and dependencies are fingerprinted without build caches' => static function (): void {
        $root = temporaryDirectory();
        $expected = [
            'Makefile',
            'mago.toml',
            '.clang-format',
            'composer.json',
            'package-lock.json',
            '.githooks/pre-commit',
            'packaging/config.m4',
            'packaging/Makefile.frag',
            'php/Profiler.php',
            '.dockerignore',
            'Dockerfile',
            'tests/docker/Dockerfile',
            'tests/docker/ClangCoverage.Dockerfile',
            'crates/new/src/runtime.rs',
            '.github/workflows/ci.yaml',
            'tests/native/c-equivalents.json',
            'schema/new-version.json',
            'scripts/php-tool.sh',
        ];
        $ignored = [
            'tests/support/example/target/build.rs',
            'scripts/__pycache__/cached.php',
            'tests/mutants.out/copy.rs',
            'scripts/.json',
            'scripts/notes.txt',
        ];
        foreach ([...$expected, ...$ignored] as $name) {
            writeFile($root . '/' . $name, $name);
        }
        $before = ValidationInputs::snapshot($root);
        $sorted = $expected;
        sort($sorted, SORT_STRING);
        same($sorted, array_keys($before));
        same(hash('sha256', 'Makefile'), $before['Makefile']);
        writeFile($root . '/tests/docker/Dockerfile', 'new compiler');
        same(
            false,
            ValidationInputs::snapshot($root)['tests/docker/Dockerfile'] === $before['tests/docker/Dockerfile'],
        );
    },
];
