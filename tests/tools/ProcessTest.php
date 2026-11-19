<?php

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use PhpProfiler\Tooling\Process;
use PhpProfiler\Tooling\ProcessFailedStatus;

return [
    'status streams and input are kept apart' => static function (): void {
        $result = Process::run(['sh', '-c', 'cat; printf err >&2; exit 3'], input: 'in');
        same([3, 'in', 'err'], [$result['status'], $result['stdout'], $result['stderr']]);
    },
    'merged errors interleave into standard output' => static function (): void {
        $result = Process::run(['sh', '-c', 'printf a; printf b >&2; printf c'], mergeErrors: true);
        same(['abc', ''], [$result['stdout'], $result['stderr']]);
    },
    'a signal death is a negative status' => static function (): void {
        same(-SIGTERM, Process::run(['sh', '-c', 'kill -TERM $$'])['status']);
    },
    'a timeout kills the child and keeps partial output' => static function (): void {
        $started = hrtime(true);
        $result = Process::run(['sh', '-c', 'printf partial; exec sleep 30'], timeout: 0.5);
        same([null, 'partial'], [$result['status'], $result['stdout']]);
        same(true, (hrtime(true) - $started) < 5_000_000_000);
    },
    'check output refuses a failed command' => static function (): void {
        same(9, throws(ProcessFailedStatus::class, static fn() => Process::output(['sh', '-c', 'exit 9']))->status);
    },
];
