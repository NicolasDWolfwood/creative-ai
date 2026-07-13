<?php

namespace App\Exceptions;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use RuntimeException;

class AiProviderException extends RuntimeException
{
    public const CATEGORY_INVALID_CONFIGURATION = 'invalid_configuration';

    public const CATEGORY_CONFIGURATION_CHANGED = 'configuration_changed';

    public const CATEGORY_AUTHORIZATION = 'authorization';

    public const CATEGORY_RATE_LIMITED = 'rate_limited';

    public const CATEGORY_TIMEOUT = 'timeout';

    public const CATEGORY_CONNECTION = 'connection';

    public const CATEGORY_PROVIDER_REJECTED = 'provider_rejected';

    public const CATEGORY_INVALID_OUTPUT = 'invalid_output';

    public function __construct(
        string $message,
        public readonly string $category,
    ) {
        parent::__construct($message);
    }

    public static function invalidConfiguration(): self
    {
        return new self(
            'Journal AI provider configuration is invalid.',
            self::CATEGORY_INVALID_CONFIGURATION,
        );
    }

    public static function requestFailed(string $category = self::CATEGORY_PROVIDER_REJECTED): self
    {
        return new self(
            'The AI provider could not complete the Journal request.',
            $category,
        );
    }

    public static function fromHttpStatus(int $status): self
    {
        return self::requestFailed(match (true) {
            in_array($status, [401, 403], true) => self::CATEGORY_AUTHORIZATION,
            $status === 429 => self::CATEGORY_RATE_LIMITED,
            in_array($status, [408, 504], true) => self::CATEGORY_TIMEOUT,
            default => self::CATEGORY_PROVIDER_REJECTED,
        });
    }

    public static function invalidStructuredOutput(): self
    {
        return new self(
            'The AI provider did not return valid structured output.',
            self::CATEGORY_INVALID_OUTPUT,
        );
    }

    public static function fromProviderFailure(\Throwable $exception): self
    {
        if ($exception instanceof RequestException) {
            return self::fromHttpStatus($exception->response->status());
        }

        if ($exception instanceof ConnectionException) {
            return self::requestFailed(
                self::transportTimedOut($exception) ? self::CATEGORY_TIMEOUT : self::CATEGORY_CONNECTION,
            );
        }

        return self::requestFailed();
    }

    public static function fromStreamFailure(\Throwable $exception): self
    {
        return self::requestFailed(
            self::transportTimedOut($exception) ? self::CATEGORY_TIMEOUT : self::CATEGORY_CONNECTION,
        );
    }

    private static function transportTimedOut(\Throwable $exception): bool
    {
        return str_contains(strtolower($exception->getMessage()), 'timed out')
            || str_contains($exception->getMessage(), 'cURL error 28');
    }
}
