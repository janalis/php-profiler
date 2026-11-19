<?php

declare(strict_types=1);

require_once __DIR__ . '/support.php';
require_once ROOT . '/scripts/lib/MutationInventory.php';

use PhpProfiler\Tooling\MutationInventory;

/** Native inventory identities retain every ordinary source callsite. */
function inventoryFixture(): array
{
    $root = temporaryDirectory();
    writeFile("{$root}/crates/adapter/src/bridge.c", 'native source');
    return [$root, "{$root}/crates/adapter/src/bridge.c"];
}

return [
    'function identities preserve source and exact span' => static function (): void {
        [$root, $source] = inventoryFixture();
        $identity = "cxx_replace_scalar_call:{$source}:64:8:64:44";
        same(
            ['cxx_replace_scalar_call:crates/adapter/src/bridge.c:64:8:64:44'],
            array_keys(MutationInventory::identities("0000 t {$identity}\n0000 T {$identity}", $root, [
                $source => true,
            ])),
        );
    },
    'data symbols and other functions are not mutation clones' => static function (): void {
        [$root, $source] = inventoryFixture();
        same([], MutationInventory::identities("0000 r cxx_test:constant.1\n0000 t native_function\n", $root, [
            $source => true,
        ]));
    },
    'malformed spans are rejected' => static function (): void {
        [$root, $source] = inventoryFixture();
        foreach (['64:0:64:44', '64:8:64', '64:8:64:bad'] as $span) {
            throws(InvalidArgumentException::class, static fn() => MutationInventory::identities(
                "0000 t cxx_test:{$source}:{$span}",
                $root,
                [$source => true],
            ));
        }
    },
    'unregistered mutation sources are rejected' => static function (): void {
        [$root, $source] = inventoryFixture();
        throws(InvalidArgumentException::class, static fn() => MutationInventory::identities(
            "0000 t cxx_test:{$root}/tests/probe.c:1:1:1:2",
            $root,
            [$source => true],
        ));
    },
];
