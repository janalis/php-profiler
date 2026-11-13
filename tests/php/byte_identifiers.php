<?php

declare(strict_types=1);

use PhpProfiler\Profiler;

$one = 'raw_' . chr(128);
$two = 'raw_' . chr(129);
$classOne = 'RawClass_' . chr(128);
$classTwo = 'RawClass_' . chr(129);
eval('function ' . $one . '():void{} function ' . $two . '():void{}');
eval('class ' . $classOne . '{public function run():void{}} class ' . $classTwo . '{public function run():void{}}');
$objectOne = new $classOne();
$objectTwo = new $classTwo();
$session = Profiler::start(null, ['timeline' => true]);
$one();
$two();
$objectOne->run();
$objectTwo->run();
$session->stop();
$functions = $session->getReport()['functions'];
foreach ([$one, $two] as $name) {
    $key = '{bytes:' . bin2hex($name) . '}';
    if (($functions[$key]['calls'] ?? 0) !== 1) {
        throw new RuntimeException('byte function identities merged');
    }
}
foreach ([$classOne, $classTwo] as $name) {
    $key = '{bytes:' . bin2hex($name) . '}::run';
    if (($functions[$key]['calls'] ?? 0) !== 1) {
        throw new RuntimeException('byte class identities merged');
    }
}
// Oversized encoded names must be explicitly dropped, never shortened or merged.
$oversized = str_repeat(chr(128), 600);
eval('function ' . $oversized . '():void{}');
$limited = Profiler::start();
$oversized();
$limited->stop();
$report = $limited->getReport();
if ($report['status'] !== 'truncated' || $report['dropped_calls'] !== 1) {
    throw new RuntimeException('encoded display bound silently lost data');
}
Profiler::resetAll();
echo "lossless byte identifiers passed\n";
