<?php

namespace App\Modules\Generation\Support;

/**
 * A small JSON Schema checker for the subset the engine uses: type (including a nullable union), required, enum,
 * items, properties, additionalProperties:false. Vendors differ in what structured output enforces, and local models
 * enforce nothing, so we check every reply ourselves. Length and count limits are not in the schema; the quality
 * gates own those, because several vendors reject them.
 */
final class JsonShape
{
    /**
     * @param  array<string, mixed>  $schema
     * @return list<string> problems, empty when the value fits
     */
    public static function check(mixed $value, array $schema, string $path = '$'): array
    {
        $problems = [];
        $types = (array) ($schema['type'] ?? []);
        if ($types !== [] && ! self::typeMatches($value, $types)) {
            return ["{$path}: expected ".implode('|', $types).', got '.self::name($value)];
        }
        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $problems[] = "{$path}: must be one of ".implode(', ', array_map('strval', $schema['enum']));
        }
        $isObject = is_array($value) && (! array_is_list($value) || $value === []);
        if ($isObject && in_array('object', $types, true)) {
            foreach ($schema['required'] ?? [] as $key) {
                if (! array_key_exists($key, $value)) {
                    $problems[] = "{$path}.{$key}: missing";
                }
            }
            foreach ($schema['properties'] ?? [] as $key => $sub) {
                if (array_key_exists($key, $value)) {
                    array_push($problems, ...self::check($value[$key], $sub, "{$path}.{$key}"));
                }
            }
            if (($schema['additionalProperties'] ?? true) === false) {
                foreach (array_diff(array_keys($value), array_keys($schema['properties'] ?? [])) as $extra) {
                    $problems[] = "{$path}.{$extra}: not allowed";
                }
            }
        }
        if (is_array($value) && array_is_list($value) && in_array('array', $types, true) && isset($schema['items'])) {
            foreach ($value as $i => $item) {
                array_push($problems, ...self::check($item, $schema['items'], "{$path}[{$i}]"));
            }
        }

        return $problems;
    }

    /** @param list<string> $types */
    private static function typeMatches(mixed $value, array $types): bool
    {
        foreach ($types as $t) {
            $ok = match ($t) {
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'null' => $value === null,
                'array' => is_array($value) && array_is_list($value),
                'object' => is_array($value) && (! array_is_list($value) || $value === []),
                default => false,
            };
            if ($ok) {
                return true;
            }
        }

        return false;
    }

    private static function name(mixed $v): string
    {
        return match (true) {
            $v === null => 'null',
            is_array($v) => array_is_list($v) ? 'array' : 'object',
            default => get_debug_type($v),
        };
    }
}
