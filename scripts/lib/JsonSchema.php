<?php

declare(strict_types=1);

namespace PhpProfiler\Tooling;

use InvalidArgumentException;
use stdClass;

/**
 * A strict JSON Schema draft 2020-12 validator for the keywords the published report
 * schemas use. Any other keyword is refused when the schema is checked, so a schema
 * change can never be silently ignored. Instances and schemas are decoded with
 * json_decode(..., false), which keeps JSON objects distinct from arrays.
 */
final class JsonSchema
{
    private const string DIALECT = 'https://json-schema.org/draft/2020-12/schema';
    private const array ANNOTATIONS = ['$schema', '$comment', 'title', 'description', '$defs'];
    private const array SCHEMA_MAPS = ['properties', '$defs'];
    private const array SCHEMA_LISTS = ['allOf'];
    private const array SUBSCHEMAS = ['items', 'if', 'then', 'additionalProperties'];
    private const array TYPES = ['object', 'array', 'string', 'integer', 'number', 'boolean', 'null'];

    public function __construct(
        private readonly stdClass $root,
    ) {
        if (($root->{'$schema'} ?? null) !== self::DIALECT) {
            throw new InvalidArgumentException('schema must declare the draft 2020-12 dialect');
        }
        $this->check($root, '#');
    }

    /** @return list<string> every violation, as "instance-path: message" */
    public function errors(mixed $instance): array
    {
        $errors = [];
        $this->validate($instance, $this->root, '$', $errors);
        return $errors;
    }

    /** Refuses unknown keywords and malformed keyword values: a strict metaschema check. */
    private function check(mixed $schema, string $location): void
    {
        if (is_bool($schema)) {
            return;
        }
        if (!$schema instanceof stdClass) {
            throw new InvalidArgumentException("{$location}: a schema must be an object or a boolean");
        }
        foreach (get_object_vars($schema) as $keyword => $value) {
            $here = "{$location}/{$keyword}";
            match (true) {
                in_array($keyword, self::SCHEMA_MAPS, true) => $value instanceof stdClass
                    ? array_map(fn(string $name) => $this->check(
                        $value->{$name},
                        "{$here}/{$name}",
                    ), array_keys(get_object_vars($value)))
                    : throw new InvalidArgumentException("{$here}: must map names to schemas"),
                in_array($keyword, self::SCHEMA_LISTS, true) => is_array($value) && $value !== []
                    ? array_map(fn(mixed $item) => $this->check($item, $here), $value)
                    : throw new InvalidArgumentException("{$here}: must list schemas"),
                in_array($keyword, self::SUBSCHEMAS, true) => $this->check($value, $here),
                in_array($keyword, ['$schema', '$comment', 'title', 'description'], true) => is_string($value)
                    ?: throw new InvalidArgumentException("{$here}: must be a string"),
                $keyword === 'type' => $this->checkType($value, $here),
                $keyword === 'required' => is_array($value)
                    && array_filter($value, is_string(...)) === $value
                    && count(array_unique($value)) === count($value)
                    ?: throw new InvalidArgumentException("{$here}: must list unique names"),
                $keyword === 'enum' => is_array($value) && $value !== []
                    ?: throw new InvalidArgumentException("{$here}: must be a non-empty array"),
                $keyword === 'const' => true,
                in_array($keyword, ['minimum', 'maximum'], true) => is_int($value) || is_float($value)
                    ?: throw new InvalidArgumentException("{$here}: must be a number"),
                in_array($keyword, ['maxItems', 'maxLength'], true) => is_int($value) && $value >= 0
                    ?: throw new InvalidArgumentException("{$here}: must be a non-negative integer"),
                $keyword === 'uniqueItems' => is_bool($value)
                    ?: throw new InvalidArgumentException("{$here}: must be a boolean"),
                $keyword === '$ref' => is_string($value) && str_starts_with($value, '#')
                    ? $this->resolve($value)
                    : throw new InvalidArgumentException("{$here}: only local references are supported"),
                default => throw new InvalidArgumentException("{$here}: unsupported keyword"),
            };
        }
    }

    private function checkType(mixed $value, string $location): bool
    {
        $types = is_array($value) ? $value : [$value];
        if ($types === [] || array_diff($types, self::TYPES) !== [] || count(array_unique($types)) !== count($types)) {
            throw new InvalidArgumentException("{$location}: unknown type");
        }
        return true;
    }

    private function resolve(string $reference): mixed
    {
        $target = $this->root;
        foreach (array_slice(explode('/', substr($reference, 1)), 1) as $token) {
            $token = str_replace(['~1', '~0'], ['/', '~'], rawurldecode($token));
            if (!$target instanceof stdClass || !property_exists($target, $token)) {
                throw new InvalidArgumentException("unresolvable reference {$reference}");
            }
            $target = $target->{$token};
        }
        return $target;
    }

    /** @param list<string> $errors */
    private function validate(mixed $instance, mixed $schema, string $path, array &$errors): void
    {
        if ($schema === true) {
            return;
        }
        if ($schema === false) {
            $errors[] = "{$path}: no value is allowed here";
            return;
        }
        if (isset($schema->{'$ref'})) {
            $this->validate($instance, $this->resolve($schema->{'$ref'}), $path, $errors);
        }
        if (isset($schema->type)) {
            $types = is_array($schema->type) ? $schema->type : [$schema->type];
            if (array_filter($types, static fn(string $type): bool => self::hasType($instance, $type)) === []) {
                $errors[] = "{$path}: expected " . implode(' or ', $types);
                return;
            }
        }
        if (property_exists($schema, 'const') && !self::equal($instance, $schema->const)) {
            $errors[] = "{$path}: expected the constant " . json_encode($schema->const);
        }
        if (isset($schema->enum) && array_filter($schema->enum, static fn(mixed $item): bool => self::equal(
            $instance,
            $item,
        )) === []) {
            $errors[] = "{$path}: not one of the enumerated values";
        }
        if ((is_int($instance) || is_float($instance)) && isset($schema->minimum) && $instance < $schema->minimum) {
            $errors[] = "{$path}: below the minimum {$schema->minimum}";
        }
        if ((is_int($instance) || is_float($instance)) && isset($schema->maximum) && $instance > $schema->maximum) {
            $errors[] = "{$path}: above the maximum {$schema->maximum}";
        }
        if (
            is_string($instance)
            && isset($schema->maxLength)
            && preg_match_all('/./su', $instance) > $schema->maxLength
        ) {
            $errors[] = "{$path}: longer than {$schema->maxLength} characters";
        }
        if (is_array($instance)) {
            if (isset($schema->maxItems) && count($instance) > $schema->maxItems) {
                $errors[] = "{$path}: more than {$schema->maxItems} items";
            }
            if (($schema->uniqueItems ?? false) === true) {
                foreach ($instance as $left => $first) {
                    foreach (array_slice($instance, $left + 1) as $second) {
                        if (self::equal($first, $second)) {
                            $errors[] = "{$path}: items are not unique";
                            break 2;
                        }
                    }
                }
            }
            if (isset($schema->items)) {
                foreach ($instance as $index => $item) {
                    $this->validate($item, $schema->items, "{$path}[{$index}]", $errors);
                }
            }
        }
        if ($instance instanceof stdClass) {
            $properties = get_object_vars($instance);
            foreach ($schema->required ?? [] as $name) {
                if (!array_key_exists($name, $properties)) {
                    $errors[] = "{$path}: missing required property {$name}";
                }
            }
            $declared = isset($schema->properties) ? get_object_vars($schema->properties) : [];
            foreach ($properties as $name => $value) {
                $name = (string) $name;
                if (array_key_exists($name, $declared)) {
                    $this->validate($value, $declared[$name], "{$path}.{$name}", $errors);
                } elseif (property_exists($schema, 'additionalProperties')) {
                    $this->validate($value, $schema->additionalProperties, "{$path}.{$name}", $errors);
                }
            }
        }
        foreach ($schema->allOf ?? [] as $part) {
            $this->validate($instance, $part, $path, $errors);
        }
        if (property_exists($schema, 'if')) {
            $condition = [];
            $this->validate($instance, $schema->if, $path, $condition);
            if ($condition === [] && property_exists($schema, 'then')) {
                $this->validate($instance, $schema->then, $path, $errors);
            }
        }
    }

    private static function hasType(mixed $instance, string $type): bool
    {
        return match ($type) {
            'object' => $instance instanceof stdClass,
            'array' => is_array($instance),
            'string' => is_string($instance),
            // A number with a zero fractional part is an integer in draft 2020-12.
            'integer' => is_int($instance)
                || is_float($instance) && is_finite($instance) && floor($instance) === $instance,
            'number' => is_int($instance) || is_float($instance),
            'boolean' => is_bool($instance),
            'null' => $instance === null,
        };
    }

    /** JSON equality: numbers by value, objects regardless of member order. */
    private static function equal(mixed $left, mixed $right): bool
    {
        if ((is_int($left) || is_float($left)) && (is_int($right) || is_float($right))) {
            return $left == $right;
        }
        if ($left instanceof stdClass && $right instanceof stdClass) {
            $leftMembers = get_object_vars($left);
            $rightMembers = get_object_vars($right);
            if (count($leftMembers) !== count($rightMembers)) {
                return false;
            }
            foreach ($leftMembers as $name => $value) {
                if (!array_key_exists($name, $rightMembers) || !self::equal($value, $rightMembers[$name])) {
                    return false;
                }
            }
            return true;
        }
        if (is_array($left) && is_array($right)) {
            return (
                count($left) === count($right)
                && array_filter(
                    array_keys($left),
                    static fn(int $index): bool => !self::equal($left[$index], $right[$index]),
                ) === []
            );
        }
        return $left === $right;
    }
}
