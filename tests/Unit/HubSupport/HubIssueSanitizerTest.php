<?php

declare(strict_types=1);

namespace Tests\Unit\HubSupport;

use App\Services\HubSupport\HubIssueSanitizer;
use PHPUnit\Framework\TestCase;

final class HubIssueSanitizerTest extends TestCase
{
    public function test_it_normalizes_paths_and_redacts_credentials_without_preserving_content(): void
    {
        $sanitizer = new HubIssueSanitizer;
        $jwt = implode('.', ['eyJhbGciOiJIUzI1NiJ9', 'eyJzdWIiOiIxMjM0NTY3ODkwIn0', 'synthetic-signature']);

        $sanitized = $sanitizer->sanitizeDiagnostics([
            'path' => 'https://www.mbfdhub.com/forms/123?token=secret#section',
            'message' => 'Authorization: Bearer super-secret-token password=hunter2 '.$jwt,
            'request_body' => 'must never persist',
            'response_body' => 'must never persist',
            'headers' => [
                'Cookie' => 'session=private',
                'X-CSRF-TOKEN' => 'csrf-secret',
            ],
            'email' => 'member@example.test',
        ]);

        $encoded = json_encode($sanitized, JSON_THROW_ON_ERROR);

        self::assertSame('/forms/123', $sanitized['path']);
        self::assertStringNotContainsString('super-secret-token', $encoded);
        self::assertStringNotContainsString('hunter2', $encoded);
        self::assertStringNotContainsString($jwt, $encoded);
        self::assertStringNotContainsString('must never persist', $encoded);
        self::assertStringNotContainsString('session=private', $encoded);
        self::assertStringNotContainsString('csrf-secret', $encoded);
        self::assertStringNotContainsString('member@example.test', $encoded);
    }

    public function test_it_enforces_the_server_side_payload_limit(): void
    {
        $sanitizer = new HubIssueSanitizer;
        $events = [];

        for ($index = 0; $index < 100; $index++) {
            $events[] = [
                'type' => 'error',
                'message' => str_repeat('safe diagnostic text ', 500),
            ];
        }

        $sanitized = $sanitizer->sanitizeDiagnostics(['events' => $events]);

        self::assertLessThanOrEqual(65_536, strlen(json_encode($sanitized, JSON_THROW_ON_ERROR)));
        self::assertLessThanOrEqual(25, count($sanitized['events']));
    }

    public function test_it_removes_relative_url_values_and_header_credentials(): void
    {
        $sanitizer = new HubIssueSanitizer;
        $sanitized = $sanitizer->sanitizeDiagnostics([
            'path' => '/'.str_repeat('a', 2_000),
            'message' => 'GET /employee/search?term=John+Smith&station=1 failed Authorization: Basic dXNlcjpwYXNz',
            'events' => [[
                'type' => 'error',
                'stack' => 'at request (/api/items?name=PrivateValue#result:1:1) Cookie: session=abc123; other=xyz',
            ]],
        ]);
        $encoded = json_encode($sanitized, JSON_THROW_ON_ERROR);

        foreach (['John+Smith', 'station=1', 'PrivateValue', '#result', 'dXNlcjpwYXNz', 'abc123', 'other=xyz'] as $secret) {
            self::assertStringNotContainsString($secret, $encoded);
        }
        self::assertStringContainsString('/employee/search', $sanitized['message']);
        self::assertStringContainsString('/api/items', $sanitized['events'][0]['stack']);
    }

    public function test_it_enforces_the_global_server_side_payload_limit(): void
    {
        $sanitizer = new HubIssueSanitizer;
        $events = [];
        for ($index = 0; $index < 25; $index++) {
            $events[] = ['type' => 'error', 'stack' => str_repeat('safe diagnostic text ', 100)];
        }

        $sanitized = $sanitizer->sanitizeDiagnostics([
            'path' => '/'.str_repeat('a', 2_000),
            'source' => '/'.str_repeat('b', 2_000),
            'message' => str_repeat('safe diagnostic text ', 100),
            'events' => $events,
        ]);

        self::assertLessThanOrEqual(65_536, strlen(json_encode($sanitized, JSON_THROW_ON_ERROR)));
    }
}
