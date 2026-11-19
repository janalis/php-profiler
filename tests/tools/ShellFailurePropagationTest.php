<?php

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use PhpProfiler\Tooling\Process;

/** Validation must stop when a prerequisite fails, even when it printed output. */
function shellFixture(): array
{
    $directory = temporaryDirectory();
    $tools = "{$directory}/tool commands";
    $runtime = "{$directory}/runtime";
    mkdir($tools);
    mkdir("{$directory}/output");
    mkdir("{$runtime}/clang/23", recursive: true);
    // Coherent discovery fixture only; failures fire before anything loads these.
    writeFile("{$runtime}/libclang_rt.profile-aarch64.a", '');
    writeFile("{$runtime}/libclang.so", '');
    $executable = static fn(string $name, string $body) => writeFile(
        "{$tools}/{$name}",
        "#!/bin/bash\nset -eu\n{$body}",
        0o755,
    );
    $executable('cargo', "touch \"\$PROFILER_FAULT_ROOT/cargo-called\"\nexit 0\n");
    $executable('php', "touch \"\$PROFILER_FAULT_ROOT/php-called\"\nexit 91\n");
    $executable('rustc', <<<'SH'
        if [[ "$*" == *"--print target-libdir"* ]]; then
          printf '%s\n' "$PROFILER_FAULT_RUNTIME"
          exit 73
        fi
        printf 'host: aarch64-apple-darwin\nLLVM version: 23.1.3\n'

        SH);
    $executable('cc', <<<'SH'
        touch "$PROFILER_FAULT_ROOT/compiler-called"
        case "${1:-}" in
          --print-resource-dir) printf '%s\n' "$PROFILER_FAULT_RUNTIME/clang/23" ;;
          --print-runtime-dir) printf '%s\n' "$PROFILER_FAULT_RUNTIME" ;;
          -print-target-triple) printf 'aarch64-unknown-linux-gnu\n' ;;
          -print-file-name=*) printf '%s\n' "$PROFILER_FAULT_RUNTIME/missing.so"; exit 73 ;;
          *) printf '%s\n' "${PROFILER_FAULT_CLANG:-clang version 23.1.3}" ;;
        esac

        SH);
    $environment = withEnvironment([
        'PATH' => $tools . ':' . getenv('PATH'),
        'CARGO_TARGET_DIR' => "{$directory}/output",
        'CC' => "{$tools}/cc",
        'LLVM_COV' => "{$tools}/cc",
        'PROFILER_FAULT_OUTPUT' => "{$directory}/output",
        'PROFILER_FAULT_RUNTIME' => $runtime,
        'PROFILER_FAULT_ROOT' => $directory,
        // Tooling runs on the real PHP; the fake php only observes direct launches.
        'PROFILER_TOOL_PHP' => PHP_BINARY,
    ]);
    unset(
        $environment['RUSTFLAGS'],
        $environment['CARGO_ENCODED_RUSTFLAGS'],
        $environment['LIBCLANG_PATH'],
        $environment['CLANG_PATH'],
    );
    return [$directory, $tools, $environment];
}

function runShell(string $script, array $environment): array
{
    return Process::run(['bash', ROOT . '/scripts/' . $script], $environment);
}

return [
    'failed temporary directory is not exported as success' => static function (): void {
        [$directory, $tools, $environment] = shellFixture();
        writeFile("{$tools}/mktemp", "#!/bin/bash\nprintf '%s\\n' \"\$PROFILER_FAULT_OUTPUT\"\nexit 73\n", 0o755);
        $result = runShell('clang-gcov.sh', $environment);
        same(73, $result['status'], $result['stdout'] . $result['stderr']);
        same(false, file_exists("{$directory}/compiler-called"));
        same(false, file_exists("{$directory}/cargo-called"));
    },
    'a mismatched coverage toolchain never reaches cargo' => static function (): void {
        [$directory, , $environment] = shellFixture();
        $result = runShell('native-coverage.sh', [...$environment, 'PROFILER_FAULT_CLANG' => 'clang version 21.0.0']);
        same(1, $result['status'], $result['stdout'] . $result['stderr']);
        same(true, str_contains($result['stderr'], 'rustc uses 23, Clang uses 21'), $result['stderr']);
        same(false, file_exists("{$directory}/cargo-called"));
    },
    'a missing libclang beside the compiler never reaches cargo' => static function (): void {
        [$directory, , $environment] = shellFixture();
        unlink("{$directory}/runtime/libclang.so");
        $result = runShell('native-coverage.sh', $environment);
        same(1, $result['status'], $result['stdout'] . $result['stderr']);
        same(true, str_contains($result['stderr'], 'Matching libclang missing'), $result['stderr']);
        same(false, file_exists("{$directory}/cargo-called"));
    },
    'a missing coverage runtime never reaches cargo' => static function (): void {
        [$directory, , $environment] = shellFixture();
        unlink("{$directory}/runtime/libclang_rt.profile-aarch64.a");
        $result = runShell('clang-gcov.sh', $environment);
        same(1, $result['status'], $result['stdout'] . $result['stderr']);
        same(true, str_contains($result['stderr'], 'Native Clang coverage runtime missing'), $result['stderr']);
        same(false, file_exists("{$directory}/cargo-called"));
    },
    'the coverage runtime and flags reach cargo through the receipt' => static function (): void {
        [$directory, , $environment] = shellFixture();
        $result = runShell('clang-gcov.sh', [...$environment, 'RUSTFLAGS' => '-C opt-level=1']);
        same(true, file_exists("{$directory}/cargo-called"), $result['stderr']);
        $runs = glob("{$directory}/output/run-*/flags.json");
        same(1, count($runs));
        $receipt = json_decode((string) file_get_contents($runs[0]), true);
        same(['CC', 'CFLAGS', 'CARGO_ENCODED_RUSTFLAGS', 'runtime', 'runtime_sha256'], array_keys($receipt));
        $runtime = realpath("{$directory}/runtime/libclang_rt.profile-aarch64.a");
        same(implode("\x1f", [
            '-C',
            'opt-level=1',
            '-C',
            "link-arg={$runtime}",
            '-C',
            'link-arg=-lc',
        ]), $receipt['CARGO_ENCODED_RUSTFLAGS']);
        same([$runtime, hash('sha256', '')], [$receipt['runtime'], $receipt['runtime_sha256']]);
    },
    'failed sanitizer runtime lookup never launches php' => static function (): void {
        [$directory, , $environment] = shellFixture();
        $result = runShell('native-sanitizers.sh', $environment);
        same(73, $result['status'], $result['stdout'] . $result['stderr']);
        same(true, file_exists("{$directory}/cargo-called"));
        same(false, file_exists("{$directory}/php-called"));
    },
];
