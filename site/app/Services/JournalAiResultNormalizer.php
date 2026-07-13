<?php

namespace App\Services;

use App\Enums\PostAiOperation;
use DomainException;
use JsonException;

final class JournalAiResultNormalizer
{
    public const MAX_JSON_BYTES = 131072;

    public const MAX_DEPTH = 12;

    public const MAX_STRINGS = 500;

    public const MAX_LIST_ITEMS = 100;

    public function __construct(private readonly JournalAiContractRegistry $contracts) {}

    /** @return array<string, mixed> */
    public function normalize(PostAiOperation $operation, mixed $result): array
    {
        try {
            $encoded = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            throw new DomainException('The AI result is not valid UTF-8 JSON data.');
        }

        if (strlen($encoded) > self::MAX_JSON_BYTES) {
            throw new DomainException('The AI result exceeds the maximum JSON size.');
        }

        $stringCount = 0;
        $normalized = $this->normalizeAgainstSchema(
            $result,
            $this->contracts->for($operation)->schema,
            '$',
            0,
            $stringCount,
        );

        if (! is_array($normalized) || array_is_list($normalized)) {
            throw new DomainException('The AI result must be a structured object.');
        }

        $normalizedBytes = strlen(json_encode(
            $normalized,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        if ($normalizedBytes > self::MAX_JSON_BYTES) {
            throw new DomainException('The normalized AI result exceeds the maximum JSON size.');
        }

        return $normalized;
    }

    /** @return array<string, mixed> */
    public function normalizeJson(PostAiOperation $operation, string $json): array
    {
        if (strlen($json) > self::MAX_JSON_BYTES || ! mb_check_encoding($json, 'UTF-8')) {
            throw new DomainException('The AI result exceeds the allowed JSON input limits.');
        }

        try {
            $decoded = json_decode($json, true, self::MAX_DEPTH + 2, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new DomainException('The AI result is not valid JSON.');
        }

        return $this->normalize($operation, $decoded);
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function normalizeAgainstSchema(
        mixed $value,
        array $schema,
        string $path,
        int $depth,
        int &$stringCount,
    ): mixed {
        if ($depth > self::MAX_DEPTH) {
            throw new DomainException('The AI result exceeds the maximum nesting depth.');
        }

        $types = is_array($schema['type'] ?? null)
            ? $schema['type']
            : [$schema['type'] ?? null];

        if ($value === null && in_array('null', $types, true)) {
            return null;
        }

        if (in_array('object', $types, true)) {
            if (! is_array($value) || (array_is_list($value) && $value !== [])) {
                throw new DomainException("The AI result has the wrong object type at {$path}.");
            }

            $properties = $schema['properties'] ?? [];
            $required = $schema['required'] ?? [];

            if (! is_array($properties) || ! is_array($required)) {
                throw new DomainException('The AI result contract is invalid.');
            }

            $unknown = array_diff(array_keys($value), array_keys($properties));
            $missing = array_diff($required, array_keys($value));

            if ($unknown !== []) {
                throw new DomainException("The AI result contains unknown fields at {$path}.");
            }

            if ($missing !== []) {
                throw new DomainException("The AI result is missing required fields at {$path}.");
            }

            $normalized = [];

            foreach ($properties as $key => $propertySchema) {
                if (array_key_exists($key, $value)) {
                    $normalized[$key] = $this->normalizeAgainstSchema(
                        $value[$key],
                        $propertySchema,
                        $path.'.'.$key,
                        $depth + 1,
                        $stringCount,
                    );
                }
            }

            return $normalized;
        }

        if (in_array('array', $types, true)) {
            if (! is_array($value) || ! array_is_list($value)) {
                throw new DomainException("The AI result has the wrong list type at {$path}.");
            }

            $limit = min((int) ($schema['maxItems'] ?? self::MAX_LIST_ITEMS), self::MAX_LIST_ITEMS);

            if (count($value) > $limit) {
                throw new DomainException("The AI result contains too many list items at {$path}.");
            }

            $items = $schema['items'] ?? null;

            if (! is_array($items)) {
                throw new DomainException('The AI result contract is invalid.');
            }

            return array_map(
                fn (mixed $item, int $index): mixed => $this->normalizeAgainstSchema(
                    $item,
                    $items,
                    $path.'.'.$index,
                    $depth + 1,
                    $stringCount,
                ),
                $value,
                array_keys($value),
            );
        }

        if (in_array('string', $types, true)) {
            if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
                throw new DomainException("The AI result has invalid text at {$path}.");
            }

            $value = str_replace(["\r\n", "\r"], "\n", $value);

            if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value)) {
                throw new DomainException("The AI result contains control characters at {$path}.");
            }

            $length = mb_strlen($value, 'UTF-8');

            if ($length < (int) ($schema['minLength'] ?? 0)
                || $length > (int) ($schema['maxLength'] ?? 20000)) {
                throw new DomainException("The AI result text has an invalid length at {$path}.");
            }

            if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
                throw new DomainException("The AI result contains an unsupported value at {$path}.");
            }

            if (++$stringCount > self::MAX_STRINGS) {
                throw new DomainException('The AI result contains too many text values.');
            }

            $this->assertSafeLinks($value, $path);

            return $value;
        }

        if (in_array('boolean', $types, true)) {
            if (! is_bool($value)) {
                throw new DomainException("The AI result has the wrong boolean type at {$path}.");
            }

            return $value;
        }

        if (in_array('integer', $types, true)) {
            if (! is_int($value)) {
                throw new DomainException("The AI result has the wrong integer type at {$path}.");
            }

            return $value;
        }

        throw new DomainException("The AI result has an unsupported value at {$path}.");
    }

    private function assertSafeLinks(string $value, string $path): void
    {
        $destinations = [];

        preg_match_all('/\]\(\s*<?([^\s)>]+)>?/u', $value, $markdown);
        preg_match_all('/^\s*\[[^\]]+\]:\s*<?([^\s>]+)>?/mu', $value, $references);
        preg_match_all('/\b(?:href|src|action)\s*=\s*["\']([^"\']+)["\']/iu', $value, $html);
        preg_match_all('/<([a-z][a-z0-9+.-]*:[^>]+)>/iu', $value, $autolinks);

        foreach ([$markdown[1] ?? [], $references[1] ?? [], $html[1] ?? [], $autolinks[1] ?? []] as $matches) {
            array_push($destinations, ...$matches);
        }

        foreach ($destinations as $destination) {
            $destination = html_entity_decode(trim((string) $destination), ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if (str_starts_with($destination, '//')) {
                throw new DomainException("The AI result contains an unsafe link at {$path}.");
            }

            if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $destination, $scheme)
                && ! in_array(strtolower($scheme[1]), ['http', 'https', 'mailto'], true)) {
                throw new DomainException("The AI result contains an unsafe link at {$path}.");
            }
        }
    }
}
