<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class SecurityHeadersSchemeTest extends TestCase
{
    public function test_http_loopback_response_does_not_upgrade_its_own_form_submission_to_https(): void
    {
        $response = $this->get('http://127.0.0.1/login');

        $response->assertOk();
        $this->assertStringNotContainsString(
            'upgrade-insecure-requests',
            (string) $response->headers->get('Content-Security-Policy'),
        );
    }

    public function test_https_response_keeps_upgrade_insecure_requests_enforcement(): void
    {
        $response = $this->get('https://www.mbfdhub.com/login');

        $response->assertOk();
        $this->assertStringContainsString(
            'upgrade-insecure-requests',
            (string) $response->headers->get('Content-Security-Policy'),
        );
    }
}
