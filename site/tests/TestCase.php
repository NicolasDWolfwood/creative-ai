<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    /** @return array<string, mixed> */
    protected function decodeStructuredData(TestResponse $response): array
    {
        $matched = preg_match(
            '/<script type="application\/ld\+json">\s*(.*?)\s*<\/script>/s',
            (string) $response->getContent(),
            $matches,
        );

        $this->assertSame(1, $matched, 'The response does not contain a JSON-LD script.');
        $json = $matches[1] ?? '';
        $this->assertFalse(
            str_starts_with(ltrim($json), 'JSON.parse('),
            'JSON-LD must contain literal JSON, not a JavaScript expression.',
        );

        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
