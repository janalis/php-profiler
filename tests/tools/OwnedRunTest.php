<?php

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use PhpProfiler\Tooling\Process;

return [
    'owned run propagates failure and timeout and retires the group' => static function (): void {
        $tool = [ROOT . '/scripts/php-tool.sh', ROOT . '/scripts/owned-run.php'];
        same(0, Process::run([...$tool, '5', 'true'])['status']);
        same(1, Process::run([...$tool, '5', 'sh', '-c', 'exit 3'])['status']);
        $directory = temporaryDirectory();
        $started = hrtime(true);
        same(
            1,
            Process::run([
                ...$tool,
                '0.5',
                'sh',
                '-c',
                'sleep 30 & echo $! > "$1"; wait',
                'family',
                "{$directory}/pid",
            ])['status'],
        );
        same(true, (hrtime(true) - $started) < 10_000_000_000);
        $pid = (int) file_get_contents("{$directory}/pid");
        $deadline = hrtime(true) + 5_000_000_000;
        while (posix_kill($pid, 0) && hrtime(true) < $deadline) {
            usleep(10_000);
        }
        same(false, posix_kill($pid, 0), 'grandchild survived:');
        same(2, Process::run([...$tool, 'soon', 'true'])['status']);
    },
];
