<?php

declare(strict_types=1);

require_once __DIR__ . '/support.php';
require_once ROOT . '/scripts/lib/JsonSchema.php';

use PhpProfiler\Tooling\JsonSchema;

function schemaOf(string $json): JsonSchema
{
    return new JsonSchema(json_decode(
        '{"$schema": "https://json-schema.org/draft/2020-12/schema", ' . substr($json, 1),
        false,
        flags: JSON_THROW_ON_ERROR,
    ));
}

function verdict(JsonSchema $schema, string $instance): bool
{
    return $schema->errors(json_decode($instance, false, flags: JSON_THROW_ON_ERROR)) === [];
}

return [
    'the published schemas pass the strict keyword check' => static function (): void {
        foreach ([1, 2] as $version) {
            new JsonSchema(json_decode(
                (string) file_get_contents(ROOT . "/schema/report-v{$version}.json"),
                false,
                flags: JSON_THROW_ON_ERROR,
            ));
        }
    },
    'unknown keywords, remote references and other dialects are refused' => static function (): void {
        foreach ([
            '{"pattern": "a"}',
            '{"properties": {"a": {"format": "uri"}}}',
            '{"$ref": "https://example.test/schema"}',
            '{"type": "text"}',
            '{"$ref": "#/$defs/missing"}',
        ] as $schema) {
            throws(InvalidArgumentException::class, static fn() => schemaOf($schema));
        }
        throws(
            InvalidArgumentException::class,
            static fn() => new JsonSchema(json_decode('{"$schema": "http://json-schema.org/draft-07/schema#"}', false)),
        );
    },
    'objects, arrays and integers keep their JSON meaning' => static function (): void {
        $object = schemaOf(
            '{"type": "object", "required": ["n"], "properties": {"n": {"type": "integer", "minimum": 0, "maximum": 3}}, "additionalProperties": false}',
        );
        same([true, true, false, false, false, false, false], [
            verdict($object, '{"n": 3}'),
            verdict($object, '{"n": 1.0}'),
            verdict($object, '{"n": 1.5}'),
            verdict($object, '{"n": 4}'),
            verdict($object, '{}'),
            verdict($object, '[]'),
            verdict($object, '{"n": 1, "m": 2}'),
        ]);
        $array = schemaOf(
            '{"type": "array", "maxItems": 2, "uniqueItems": true, "items": {"type": ["string", "null"], "maxLength": 2}}',
        );
        same([true, false, false, false, true], [
            verdict($array, '["é", null]'),
            verdict($array, '["a", "a"]'),
            verdict($array, '["a", "b", "c"]'),
            verdict($array, '["abc"]'),
            verdict($array, '["éé"]'),
        ]);
    },
    'const, enum, allOf and if-then follow json equality' => static function (): void {
        $schema = schemaOf(
            '{"$defs": {"flag": {"enum": [1, "on", {"a": [1]}]}}, "allOf": [{"$ref": "#/$defs/flag"}], "if": {"const": 1}, "then": {"type": "number", "const": 1.0}}',
        );
        same([true, true, true, false, false], [
            verdict($schema, '1'),
            verdict($schema, '"on"'),
            verdict($schema, '{"a": [1.0]}'),
            verdict($schema, '{"a": [2]}'),
            verdict($schema, '"off"'),
        ]);
        $conditional = schemaOf(
            '{"type": "object", "if": {"properties": {"overflow": {"const": true}}}, "then": {"properties": {"status": {"const": "incomplete"}}}}',
        );
        same([true, false, true], [
            verdict($conditional, '{"overflow": true, "status": "incomplete"}'),
            verdict($conditional, '{"overflow": true, "status": "complete"}'),
            verdict($conditional, '{"overflow": false, "status": "complete"}'),
        ]);
    },
];
