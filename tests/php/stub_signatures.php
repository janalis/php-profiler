<?php

declare(strict_types=1);

// The published stub must describe the loaded extension's API. The stub is read by
// a child PHP process without the extension, because both declare the same classes.

/** @return array<string, mixed> Classes, methods and functions in the PhpProfiler namespace. */
function profiler_api_signatures(): array
{
    $type = static fn(?ReflectionType $type): ?string => $type === null ? null : (string) $type;
    $signatures = [];
    foreach (get_declared_classes() as $class) {
        if (!str_starts_with($class, 'PhpProfiler\\')) {
            continue;
        }
        $reflection = new ReflectionClass($class);
        $parent = $reflection->getParentClass();
        $methods = [];
        foreach ($reflection->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }
            $methods[$method->getName()] = [
                'static' => $method->isStatic(),
                'return' => $type($method->getReturnType()),
                'parameters' => array_map(static fn(ReflectionParameter $parameter): array => [
                    $parameter->getName(),
                    $type($parameter->getType()),
                    $parameter->isOptional(),
                ], $method->getParameters()),
            ];
        }
        ksort($methods);
        $signatures['class ' . $class] = [
            'final' => $reflection->isFinal(),
            'parent' => $parent === false ? null : $parent->getName(),
            'methods' => $methods,
        ];
    }
    $functions = get_defined_functions();
    foreach ([...$functions['internal'], ...$functions['user']] as $function) {
        // Internal helpers are private adapter plumbing, deliberately absent from the stub.
        if (str_starts_with($function, 'phpprofiler\\') && !str_starts_with($function, 'phpprofiler\\internal\\')) {
            $signatures['function ' . $function] = $type((new ReflectionFunction($function))->getReturnType());
        }
    }
    ksort($signatures);
    return $signatures;
}

if (($argv[1] ?? '') === '--stub') {
    require __DIR__ . '/../../php/PhpProfiler.stub.php';
    echo json_encode(profiler_api_signatures(), JSON_THROW_ON_ERROR);
    exit(0);
}
if (!extension_loaded('php-profiler') && !class_exists(PhpProfiler\Profiler::class, false)) {
    throw new RuntimeException('stub signature check requires the loaded extension');
}

$child = proc_open([PHP_BINARY, '-n', __FILE__, '--stub'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if ($child === false) {
    throw new RuntimeException('stub reader did not start');
}
$output = (string) stream_get_contents($pipes[1]);
$errors = (string) stream_get_contents($pipes[2]);
if (proc_close($child) !== 0) {
    throw new RuntimeException('stub reader failed: ' . $output . $errors);
}
$stub = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
$extension = profiler_api_signatures();

$problems = [];
foreach (array_keys($stub + $extension) as $name) {
    if (!isset($stub[$name]) || !isset($extension[$name])) {
        $problems[] = $name . (isset($stub[$name]) ? ' is missing from the extension' : ' is missing from the stub');
        continue;
    }
    if (!str_starts_with($name, 'class ')) {
        // ext-php-rs arginfo may widen a function's declared return type to mixed.
        if ($stub[$name] !== $extension[$name] && $extension[$name] !== 'mixed') {
            $problems[] = "{$name} returns {$extension[$name]}, the stub says {$stub[$name]}";
        }
        continue;
    }
    foreach (['final', 'parent'] as $property) {
        if ($stub[$name][$property] !== $extension[$name][$property]) {
            $problems[] = "{$name} {$property} differs";
        }
    }
    $stubMethods = $stub[$name]['methods'];
    $extensionMethods = $extension[$name]['methods'];
    foreach (array_keys($stubMethods + $extensionMethods) as $method) {
        $declared = $stubMethods[$method] ?? null;
        $actual = $extensionMethods[$method] ?? null;
        if ($declared === null) {
            // ext-php-rs gives every registered class a parameterless constructor.
            if ($method !== '__construct' || $actual['parameters'] !== []) {
                $problems[] = "{$name}::{$method}() is missing from the stub";
            }
            continue;
        }
        if ($actual === null) {
            $problems[] = "{$name}::{$method}() is missing from the extension";
            continue;
        }
        if ($declared['static'] !== $actual['static'] || $declared['parameters'] !== $actual['parameters']) {
            $problems[] = "{$name}::{$method}() parameters or static modifier differ";
        }
        // docs/report-format.md: arginfo reports some array and string returns as mixed.
        if ($declared['return'] !== $actual['return'] && $actual['return'] !== 'mixed') {
            $problems[] = "{$name}::{$method}() returns {$actual['return']}, the stub says {$declared['return']}";
        }
    }
}
if ($problems !== []) {
    throw new RuntimeException("stub drift:\n" . implode("\n", $problems));
}
