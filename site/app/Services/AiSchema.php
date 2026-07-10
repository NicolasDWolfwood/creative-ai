<?php

namespace App\Services;

class AiSchema
{
    /**
     * Remove validation keywords that are not accepted by every provider's
     * constrained-output implementation. Application normalization still
     * enforces its own length and range limits after decoding.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function portable(array $schema): array
    {
        $unsupported = [
            'maxLength',
            'minLength',
            'minimum',
            'maximum',
            'minItems',
            'maxItems',
            'pattern',
            'format',
        ];

        foreach ($unsupported as $keyword) {
            unset($schema[$keyword]);
        }

        foreach ($schema as $key => $value) {
            if (is_array($value)) {
                $schema[$key] = self::portable($value);
            }
        }

        return $schema;
    }
}
