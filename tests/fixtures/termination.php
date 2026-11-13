<?php

declare(strict_types=1);

use PhpProfiler\Profiler;

if (class_exists(Profiler::class, false)) {
    Profiler::start($argv[1]);
}
function termination_leaf(): void
{
    usleep(1);
}

termination_leaf();
echo "application output\n";
switch ($argv[2]) {
    case 'exit':
        exit(7);
    case 'exception':
        throw new RuntimeException('application failure');
    case 'normal':
        break;
}
