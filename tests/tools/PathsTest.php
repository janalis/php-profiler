<?php

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use PhpProfiler\Tooling\Paths;

return [
    'remove tree deletes nested files but never follows a symlink out' => static function (): void {
        $root = temporaryDirectory();
        writeFile("{$root}/outside/kept", 'kept');
        writeFile("{$root}/tree/a/b/file", 'x');
        symlink("{$root}/outside", "{$root}/tree/a/link");
        Paths::removeTree("{$root}/tree");
        same([false, 'kept'], [file_exists("{$root}/tree"), file_get_contents("{$root}/outside/kept")]);
    },
    'resolve matches pathlib for missing components and parent steps' => static function (): void {
        $root = temporaryDirectory();
        mkdir("{$root}/real");
        symlink("{$root}/real", "{$root}/link");
        same("{$root}/real/missing/file", Paths::resolve("{$root}/link/./missing//file"));
        same("{$root}/sibling", Paths::resolve("{$root}/link/../sibling"));
        same('/', Paths::resolve('/../..'));
    },
    'suffix and component ordering follow pathlib' => static function (): void {
        same(['.c', '', '', '.gz'], [
            Paths::suffix('a/b.c'),
            Paths::suffix('.json'),
            Paths::suffix('x.'),
            Paths::suffix('a.tar.gz'),
        ]);
        same(['a/x', 'a-b/x'], Paths::sort(['a-b/x', 'a/x']));
    },
];
