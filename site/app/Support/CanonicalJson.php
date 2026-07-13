<?php

namespace App\Support;

use JsonException;

final class CanonicalJson
{
    /** @throws JsonException */
    public static function encode(mixed $value): string
    {
        return json_encode(
            self::canonicalize($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    /** @throws JsonException */
    public static function hash(mixed $value): string
    {
        return hash('sha256', self::encode($value));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return $value;
    }
}
