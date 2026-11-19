<?php

declare(strict_types=1);

/** Dependency-free runner: each *Test.php file returns a map of test name to closure. */
final class AssertionFailed extends RuntimeException {}

function same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new AssertionFailed(trim(
            $message . ' expected ' . var_export($expected, true) . ', got ' . var_export($actual, true),
        ));
    }
}

/** @param class-string<Throwable> $type */
function throws(string $type, Closure $action): Throwable
{
    try {
        $action();
    } catch (Throwable $thrown) {
        if ($thrown instanceof $type) {
            return $thrown;
        }
        throw $thrown;
    }
    throw new AssertionFailed('expected ' . $type);
}

$files = array_slice($argv, 1) ?: glob(__DIR__ . '/*Test.php');
$passed = 0;
$failures = 0;
foreach ($files as $file) {
    foreach (require $file as $name => $test) {
        try {
            $test();
            $passed++;
        } catch (Throwable $failure) {
            $failures++;
            fprintf(
                STDERR,
                "FAILED %s: %s\n%s\n",
                basename($file) . '::' . $name,
                $failure->getMessage(),
                $failure->getTraceAsString(),
            );
        }
    }
}
printf("%d passed, %d failed\n", $passed, $failures);
exit($passed > 0 && $failures === 0 ? 0 : 1);
