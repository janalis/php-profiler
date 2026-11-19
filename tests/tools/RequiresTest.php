<?php

declare(strict_types=1);

require_once __DIR__ . '/support.php';

/**
 * Every script and library loads the classes it uses itself. The test suite preloads some
 * libraries, so a missing require would otherwise pass here and fail in production.
 */
function requiredFiles(string $file, array &$seen = []): array
{
    preg_match_all("#require_once __DIR__ \\. '(?:/lib)?/(\\w+\\.php)'#", (string) file_get_contents($file), $matches);
    foreach ($matches[1] as $name) {
        if (!isset($seen[$name])) {
            $seen[$name] = true;
            requiredFiles(ROOT . "/scripts/lib/{$name}", $seen);
        }
    }
    return $seen;
}

return [
    'every script and library loads the classes it uses' => static function (): void {
        $owners = [];
        foreach (glob(ROOT . '/scripts/lib/*.php') as $library) {
            preg_match_all(
                '/^(?:final |abstract |readonly )*(?:class|interface) (\w+)/m',
                (string) file_get_contents($library),
                $declared,
            );
            foreach ($declared[1] as $class) {
                $owners[$class] = basename($library);
            }
        }
        $problems = [];
        foreach ([...glob(ROOT . '/scripts/*.php'), ...glob(ROOT . '/scripts/lib/*.php')] as $file) {
            $text = (string) file_get_contents($file);
            $loaded = requiredFiles($file);
            $loaded[basename($file)] = str_contains($file, '/scripts/lib/');
            foreach ($owners as $class => $owner) {
                if (($loaded[$owner] ?? false) === true) {
                    continue;
                }
                if (preg_match(
                    '/(?<![\w$\\\\])'
                    . $class
                    . '::|new '
                    . $class
                    . '\b|instanceof '
                    . $class
                    . '\b|\b'
                    . $class
                    . ' \$/',
                    $text,
                )) {
                    $problems[] = basename($file) . " uses {$class} without loading {$owner}";
                }
            }
        }
        same([], $problems);
    },
];
