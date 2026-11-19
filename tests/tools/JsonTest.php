<?php

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use PhpProfiler\Tooling\Json;

return [
    'matches python json.dumps with sorted keys' => static function (): void {
        $value = ['b' => [1, true, null], 'a' => ['é/x', new stdClass(), []], '10' => 'ten'];
        same('{"10": "ten", "a": ["\u00e9/x", {}, []], "b": [1, true, null]}', Json::encode($value));
        same("{\n  \"10\": \"ten\",\n  \"a\": [\n    \"\\u00e9/x\",\n    {},\n    []\n  ],\n  \"b\": [\n    1,\n    true,\n    null\n  ]\n}", Json::encode(
            $value,
            indent: 2,
        ));
    },
    'non-finite floats are refused' => static function (): void {
        throws(InvalidArgumentException::class, static fn() => Json::encode(['x' => INF]));
        throws(InvalidArgumentException::class, static fn() => Json::encode(['x' => NAN]));
    },
];
