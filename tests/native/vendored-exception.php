<?php

declare(strict_types=1);

$expected = [
    0 => [Exception::class, 'ordinary exception'],
    1 => [Error::class, 'invalid\\0message'],
    2 => [Error::class, 'non-object attachment'],
    3 => [Exception::class, 'original pending exception'],
    4 => [Exception::class, 'attached exception'],
    5 => [Error::class, 'abstract exception'],
];
foreach ($expected as $case => [$class, $fragment]) {
    try {
        profiler_fixture_throw($case);
        throw new RuntimeException('no fixture exception');
    } catch (Throwable $error) {
        if ($error::class !== $class || !str_contains($error->getMessage(), $fragment)) {
            throw new RuntimeException(
                'wrong exception behavior case ' . $case . ': ' . $error::class . ': ' . $error->getMessage(),
            );
        }
    }
}
echo "vendored exception normal/pending/object/fallback paths passed\n";
