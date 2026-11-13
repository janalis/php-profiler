<?php

declare(strict_types=1);

use PhpProfiler\Profiler;
use PhpProfiler\Session;
use PhpProfiler\StateException;

$session = Profiler::start();
$id = $session->getId();
for ($attempt = 0; $attempt < 50; $attempt++) {
    // Deliberately keep only this PHP variable as the handle's owning reference.
    // A clone error must release a fresh placeholder, never the original object.
    try {
        $copy = clone $session;
        throw new RuntimeException('Session unexpectedly cloneable');
    } catch (Error $error) {
        if ($error->getMessage() !== 'Trying to clone an uncloneable object of class ' . Session::class) {
            throw $error;
        }
    }
    unset($error);
    gc_collect_cycles();
    if ($session->getId() !== $id) {
        throw new RuntimeException('clone error corrupted original handle');
    }
}
try {
    serialize($session);
    throw new RuntimeException('Session unexpectedly serializable');
} catch (Exception $error) {
    if (!str_contains($error->getMessage(), 'not allowed')) {
        throw $error;
    }
}
$wire =
    'O:'
    . strlen(Session::class)
    . ':"'
    . Session::class
    . '":2:{s:2:"id";i:'
    . $id
    . ';s:6:"secret";s:20:"SESSION_FORGE_SECRET";}';
try {
    unserialize($wire);
    throw new RuntimeException('Session unexpectedly reconstructed');
} catch (Exception $error) {
    if (!str_contains($error->getMessage(), 'not allowed')) {
        throw $error;
    }
}
try {
    (new ReflectionClass(Session::class))->newInstanceWithoutConstructor();
    throw new RuntimeException('reflection bypassed Session constructor');
} catch (ReflectionException $expected) {
}
usleep(1);
$session->stop();
if ($session->getId() !== $id || $session->getReport()['status'] !== 'complete') {
    throw new RuntimeException('rejected handle operations changed session state');
}
if (str_contains($session->getReportJson(), 'SESSION_FORGE_SECRET')) {
    throw new RuntimeException('rejected object payload was collected');
}
$session->reset();
try {
    $session->getReport();
    throw new RuntimeException('reset handle retained access');
} catch (StateException $expected) {
}
$fresh = Profiler::start();
usleep(1);
$fresh->stop();
if ($fresh->getId() === $id || $fresh->getReport()['status'] !== 'complete') {
    throw new RuntimeException('rejected handles corrupted later sessions');
}
Profiler::resetAll();
echo "Session clone/serialization/reflection rejection preserves original and future sessions\n";
