<?php

declare(strict_types=1);

use PhpProfiler\Profiler;

json_decode('{');
$jsonError = [json_last_error(), json_last_error_msg()];
$reporting = error_reporting(0);
trigger_error('application-private-notice', E_USER_NOTICE);
$lastError = error_get_last();
$cwd = getcwd();
$mask = umask();
set_error_handler(static function (): never {
    throw new RuntimeException('profiler invoked the application error handler');
});
try {
    $session = Profiler::start();
    usleep(1);
    $session->stop();
    $session->getReport();
    $json = $session->getReportJson();
    $session->getReportPath();
    $session->reset();
    Profiler::disable();
    if (
        [json_last_error(), json_last_error_msg()] !== $jsonError
        || error_get_last() !== $lastError
        || error_reporting() !== 0
        || getcwd() !== $cwd
        || umask() !== $mask
    ) {
        throw new RuntimeException('profiler changed application runtime state');
    }
    if (str_contains($json, 'application-private-notice')) {
        throw new RuntimeException('application error text was collected');
    }
} finally {
    restore_error_handler();
    error_reporting($reporting);
}
echo "PHP JSON/error/working-directory/umask state preserved\n";
