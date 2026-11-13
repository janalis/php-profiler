<?php

declare(strict_types=1);

function profiler_mapped_cache_leaf(): int
{
    usleep(1);
    return 31;
}

if (!function_exists('opcache_get_status') || !opcache_get_status(false))
    throw new RuntimeException('actual OPcache required for mapped-cache contract');
if (profiler_mapped_cache_leaf() !== 31)
    throw new RuntimeException('mapped function semantics changed');
if (!profiler_test_named_cache('profiler_mapped_cache_leaf'))
    throw new RuntimeException('real OPcache tagged runtime-cache identity contract failed');
echo "actual OPcache mapped runtime-cache branch and stable reserved UID passed\n";
