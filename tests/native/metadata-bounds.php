<?php

declare(strict_types=1);

use PhpProfiler\Profiler;

if (ini_set('opcache.enable', chr(128)) === false) {
    throw new RuntimeException('byte configuration test setup');
}
$bytes = Profiler::start();
$bytes->stop();
if ($bytes->getReport()['runtime_metadata']['opcache.enable_configured'] !== '{bytes:80}') {
    throw new RuntimeException('byte-valued configuration changed silently');
}
$bytes->reset();
$nulValue = "0\0config";
if (ini_set('opcache.enable', $nulValue) === false) {
    throw new RuntimeException('NUL configuration test setup');
}
$nul = Profiler::start();
$nul->stop();
if ($nul->getReport()['runtime_metadata']['opcache.enable_configured'] !== $nulValue) {
    throw new RuntimeException('length-aware configuration metadata was truncated at NUL');
}
$nul->reset();
foreach ([128, 129, 8192] as $size) {
    if (ini_set('opcache.enable', str_repeat('0', $size)) === false) {
        throw new RuntimeException('test requires runtime OPcache boolean configuration');
    }
    $session = Profiler::start();
    $session->stop();
    $metadata = $session->getReport()['runtime_metadata'];
    if ($size <= 128) {
        if ($metadata['opcache.enable_configured'] !== str_repeat('0', $size)) {
            throw new RuntimeException('bounded INI value changed');
        }
    } elseif (
        $metadata['opcache.enable_configured'] !== '<omitted: configured value exceeds 128 bytes>'
        || $metadata['configuration_metadata_complete'] !== 'false'
    ) {
        throw new RuntimeException('unbounded INI text was copied or omission was hidden');
    }
    $session->reset();
}
echo "configured metadata storage bounds passed\n";
