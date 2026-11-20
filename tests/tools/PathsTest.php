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
    'remove tree deletes special files and a dangling symlink, like a php-cgi socket' => static function (): void {
        $root = temporaryDirectory();
        mkdir("{$root}/tree");
        // A FIFO stands in for php-cgi's listening socket: is_file() is false for both, and a
        // socket path under a long temporary directory exceeds the 103-byte limit.
        same(true, posix_mkfifo("{$root}/tree/cgi.fifo", 0o600));
        symlink("{$root}/missing", "{$root}/tree/dangling");
        same(['cgi.fifo', 'dangling'], array_values(array_diff(scandir("{$root}/tree"), ['.', '..'])));
        Paths::removeTree("{$root}/tree");
        same(false, file_exists("{$root}/tree"));
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
        // Expected orders come from sorted() over pathlib.PurePosixPath.
        same(['a', 'a/b', 'a/b/c', 'a-b', 'a.b', 'b', 'x/10', 'x/9'], Paths::sort([
            'b',
            'a/b/c',
            'x/10',
            'x/9',
            'a',
            'a/b',
            'a-b',
            'a.b',
        ]));
        same(['/a', '/a/c', '/b'], Paths::sort(['/b', '/a/c', '/a']));
    },
];
