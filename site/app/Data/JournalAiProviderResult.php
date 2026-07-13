<?php

namespace App\Data;

final readonly class JournalAiProviderResult
{
    public const MAX_REQUEST_ID_LENGTH = 191;

    public const MAX_TOKEN_COUNT = 1_000_000_000;

    /** @var array<string, mixed> */
    public array $payload;

    public ?string $providerRequestId;

    public ?int $inputTokens;

    public ?int $outputTokens;

    /** @param array<string, mixed> $payload */
    public function __construct(
        array $payload,
        mixed $providerRequestId = null,
        mixed $inputTokens = null,
        mixed $outputTokens = null,
    ) {
        $this->payload = $payload;
        $this->providerRequestId = self::sanitizeRequestId($providerRequestId);
        $this->inputTokens = self::sanitizeTokenCount($inputTokens);
        $this->outputTokens = self::sanitizeTokenCount($outputTokens);
    }

    private static function sanitizeRequestId(mixed $requestId): ?string
    {
        if (! is_string($requestId)) {
            return null;
        }

        $requestId = trim($requestId);

        if (strlen($requestId) > self::MAX_REQUEST_ID_LENGTH
            || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]*\z/D', $requestId)) {
            return null;
        }

        return $requestId;
    }

    private static function sanitizeTokenCount(mixed $tokens): ?int
    {
        if (! is_int($tokens) || $tokens < 0 || $tokens > self::MAX_TOKEN_COUNT) {
            return null;
        }

        return $tokens;
    }
}
