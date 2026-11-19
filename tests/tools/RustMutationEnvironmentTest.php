<?php

declare(strict_types=1);

require_once __DIR__ . '/support.php';
require_once ROOT . '/scripts/lib/Paths.php';

use PhpProfiler\Tooling\Paths;
use PhpProfiler\Tooling\Process;

/** Private Zend contracts belong to the DSO, never standalone Rust executables. */
const PRIVATE_FLAGS = ['PROFILER_ADAPTER_CONTRACT_TEST', 'PROFILER_C_UNIT_BOUNDARIES', 'PROFILER_SAMPLING_UNIT'];

return [
    'inherited private flags are removed from all cargo invocations' => static function (): void {
        $directory = temporaryDirectory();
        $root = Paths::resolve(ROOT);
        $metadata = json_encode([
            'workspace_members' => ['core', 'ext'],
            'packages' => [
                [
                    'name' => 'profiler-core',
                    'id' => 'core',
                    'manifest_path' => "{$root}/crates/profiler-core/Cargo.toml",
                ],
                ['name' => 'php-profiler', 'id' => 'ext', 'manifest_path' => "{$root}/crates/php-profiler/Cargo.toml"],
            ],
        ], JSON_UNESCAPED_SLASHES);
        $discovery = json_encode(
            array_map(
                static fn(string $path): array => ['file' => substr($path, strlen($root) + 1)],
                Paths::runtimeSources($root, 'rust'),
            ),
            JSON_UNESCAPED_SLASHES,
        );
        writeFile("{$directory}/metadata.json", $metadata);
        writeFile("{$directory}/discovery.json", $discovery);
        writeFile("{$directory}/cargo", <<<SH
            #!/bin/sh
            printf '%s|%s|%s|%s\\n' "\$1" "\${PROFILER_ADAPTER_CONTRACT_TEST-unset}" "\${PROFILER_C_UNIT_BOUNDARIES-unset}" "\${PROFILER_SAMPLING_UNIT-unset}" >> '{$directory}/calls'
            if [ "\$1" = metadata ]; then cat '{$directory}/metadata.json'; else cat '{$directory}/discovery.json'; fi

            SH, 0o755);
        $environment = withEnvironment([
            'PATH' => $directory . ':' . getenv('PATH'),
            ...array_fill_keys(PRIVATE_FLAGS, '1'),
        ]);
        unset($environment['CARGO_TARGET_DIR']);
        $result = Process::run([
            ROOT . '/scripts/php-tool.sh',
            ROOT . '/scripts/rust-mutation.php',
            '--list-only',
            '--output',
            "{$directory}/output",
        ], $environment);
        same(0, $result['status'], $result['stdout'] . $result['stderr']);
        $calls = array_filter(explode("\n", (string) file_get_contents("{$directory}/calls")));
        same(
            ['metadata|unset|unset|unset', 'mutants|unset|unset|unset', 'mutants|unset|unset|unset'],
            array_values($calls),
        );
        $manifest = json_decode((string) file_get_contents("{$directory}/output/latest.json"), true);
        same(array_fill_keys(PRIVATE_FLAGS, '1'), $manifest['private_contract_environment']['native_cdylib_build']);
        same(array_fill_keys(PRIVATE_FLAGS, null), $manifest['private_contract_environment']['standalone_unit_build']);
        same($manifest['all_workspace_runtime_sources'], array_keys($manifest['runtime_sources']));
        same([0, true, 'discovery-only'], [
            $manifest['exit_code'],
            $manifest['source_snapshot_matches'],
            $manifest['mode'],
        ]);
    },
];
