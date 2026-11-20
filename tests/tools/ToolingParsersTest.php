<?php

declare(strict_types=1);

require_once __DIR__ . '/support.php';
require_once dirname(__DIR__, 2) . '/scripts/lib/Arguments.php';
require_once dirname(__DIR__, 2) . '/scripts/lib/Tar.php';
require_once dirname(__DIR__, 2) . '/scripts/lib/Toml.php';

use PhpProfiler\Tooling\Arguments;
use PhpProfiler\Tooling\Json;
use PhpProfiler\Tooling\Process;
use PhpProfiler\Tooling\Tar;
use PhpProfiler\Tooling\Toml;

return [
    'a repeated option keeps its last value unless it collects, like argparse' => static function (): void {
        $parsed = Arguments::parse(
            ['tool', '--output', 'a', 'positional', '--output=b', '--package', 'x', '--package', 'y', '--only', 'z'],
            ['output', 'package', 'only'],
            repeated: ['package', 'only'],
        );
        same(['output' => 'b', 'package' => ['x', 'y'], 'only' => ['z']], $parsed['options']);
        same(['positional'], $parsed['positionals']);
    },
    'TOML refuses a table defined by two headers, like tomllib' => static function (): void {
        foreach (["[a]\nx = 1\n[a]\ny = 2\n", "[a]\n[a.b]\n[a.b]\n", "[[p]]\n[p.m]\n[p.m]\n"] as $text) {
            throws(InvalidArgumentException::class, static fn() => Toml::parse($text));
        }
        // Each [[array]] element opens its own scope, and a parent may follow its child.
        same([
            'p' => [['n' => 1, 'm' => ['x' => 1]], ['n' => 2, 'm' => ['x' => 2]]],
        ], Toml::parse("[[p]]\nn = 1\n[p.m]\nx = 1\n[[p]]\nn = 2\n[p.m]\nx = 2\n"));
        same(['a' => ['b' => ['x' => 1], 'y' => 2]], Toml::parse("[a.b]\nx = 1\n[a]\ny = 2\n"));
    },
    'snapshots of no inputs are JSON objects, as Python wrote them' => static function (): void {
        same('{}', Json::encode((object) []));
        $root = temporaryDirectory();
        $result = Process::run([ROOT . '/scripts/php-tool.sh', ROOT . '/scripts/coverage-snapshot.php', $root]);
        same([0, "{}\n"], [$result['status'], $result['stdout']], $result['stderr']);
    },
    'a pax record must advance past its length prefix and end in a newline, like tarfile' => static function (): void {
        // Tar::write emits file data only, so a pax header is a file entry retyped to 'x'.
        $archive = static function (string $records): string {
            $pax = Tar::write([['name' => 'pax', 'data' => $records]]);
            $header = substr_replace(substr($pax, 0, 512), 'x', 156, 1);
            $header = substr_replace($header, '        ', 148, 8);
            $header = substr_replace($header, sprintf('%06o', array_sum(unpack('C*', $header))) . "\0 ", 148, 8);
            return $header . substr($pax, 512, -1024) . Tar::write([['name' => 'original', 'data' => 'x']]);
        };
        same(['renamed'], array_column(Tar::read($archive("16 path=renamed\n")), 'name'));
        foreach (["0 x=y\n", "2 x=y\n", "99 x=y\n", "7 x=yz \n7 a=b \n", "6 abc\n"] as $records) {
            throws(InvalidArgumentException::class, static fn() => Tar::read($archive($records)));
        }
    },
];
