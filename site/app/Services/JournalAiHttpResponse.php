<?php

namespace App\Services;

use App\Exceptions\AiProviderException;
use Illuminate\Http\Client\Response;
use JsonException;
use Throwable;

final class JournalAiHttpResponse
{
    public const MAX_BYTES = 262_144;

    private const READ_BYTES = 8192;

    /** @return array<string, mixed> */
    public static function decode(Response $response): array
    {
        $status = $response->status();

        if ($status < 200 || $status >= 300) {
            throw AiProviderException::fromHttpStatus($status);
        }

        try {
            $stream = $response->toPsrResponse()->getBody();

            if ($stream->isSeekable()) {
                $stream->rewind();
            }

            $body = '';

            while (! $stream->eof()) {
                $remaining = self::MAX_BYTES + 1 - strlen($body);

                if ($remaining <= 0) {
                    throw AiProviderException::invalidStructuredOutput();
                }

                $chunk = $stream->read(min(self::READ_BYTES, $remaining));

                if ($chunk === '' && ! $stream->eof()) {
                    throw AiProviderException::invalidStructuredOutput();
                }

                $body .= $chunk;
            }

            if (strlen($body) > self::MAX_BYTES) {
                throw AiProviderException::invalidStructuredOutput();
            }

        } catch (AiProviderException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw AiProviderException::fromStreamFailure($exception);
        }

        try {
            $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw AiProviderException::invalidStructuredOutput();
        }

        if (! is_array($decoded)) {
            throw AiProviderException::invalidStructuredOutput();
        }

        return $decoded;
    }
}
