<?php

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use PhpProfiler\Tooling\Process;

/** CI labels must describe actual installed runtimes and matching headers. */
function environmentCheck(
    string $version = '8.3.36',
    bool $zts = false,
    string $header = '8.3.36',
    string $desired = 'nts',
    int $failure = 0,
): array {
    $root = temporaryDirectory();
    $metadata = json_encode(['php_version' => $version, 'zts' => $zts, 'sapi' => 'cli']);
    writeFile(
        "{$root}/php executable",
        "#!/bin/sh\nprintf '%s' " . escapeshellarg($metadata) . "\nexit {$failure}\n",
        0o755,
    );
    writeFile("{$root}/php-config executable", "#!/bin/sh\nprintf '%s\\n' " . escapeshellarg($header) . "\n", 0o755);
    return Process::run(
        [
            ROOT . '/scripts/php-tool.sh',
            ROOT . '/scripts/php-environment-check.php',
            '--version',
            '8.3',
            '--zts',
            $desired,
        ],
        withEnvironment(['PHP' => "{$root}/php executable", 'PHP_CONFIG' => "{$root}/php-config executable"]),
    );
}

return [
    'actual labels and exact header version pass' => static function (): void {
        $result = environmentCheck();
        same(0, $result['status'], $result['stderr']);
        same(
            '{"expected_minor": "8.3", "expected_zts": "nts", "php_config_version": "8.3.36", "php_version": "8.3.36", "sapi": "cli", "zts": false}'
            . "\n",
            $result['stdout'],
        );
    },
    'wrong minor is not accepted as the requested cell' => static function (): void {
        same(1, environmentCheck(version: '8.4.1')['status']);
    },
    'missing thread safety cannot duplicate the nts cell' => static function (): void {
        same(1, environmentCheck(desired: 'ts')['status']);
        same(0, environmentCheck(zts: true, desired: 'ts')['status']);
    },
    'different patch headers are rejected' => static function (): void {
        same(1, environmentCheck(header: '8.3.35')['status']);
    },
    'failed php command is not accepted even with valid stdout' => static function (): void {
        same(true, environmentCheck(failure: 73)['status'] !== 0);
    },
];
