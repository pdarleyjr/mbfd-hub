<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class SecurityStandardsPageTest extends TestCase
{
    public function test_anonymous_user_can_view_security_standards_page(): void
    {
        $response = $this->get('/security-standards');

        $response->assertOk();
        $response->assertSee('Security & Standards', false);
        $response->assertSee('Standards alignment matrix', false);
    }

    public function test_landing_page_footer_links_to_security_standards(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('/security-standards', false);
        $response->assertSee('Security &amp; Standards', false);
    }

    public function test_security_standards_page_displays_nfpa_disclaimer(): void
    {
        $response = $this->get('/security-standards');

        $response->assertOk();
        $response->assertSee('NFPA does not approve, certify, or endorse this software', false);
        $response->assertSee('Authority Having Jurisdiction', false);
    }

    public function test_security_standards_page_does_not_leak_internals(): void
    {
        $response = $this->get('/security-standards');

        $html = $response->getContent();
        $this->assertIsString($html);

        $bannedSubstrings = [
            'APP_KEY',
            'DB_PASSWORD',
            'DB_HOST',
            'DB_DATABASE',
            'CLOUDFLARE_API_TOKEN',
            'AWS_SECRET_ACCESS_KEY',
            'AWS_ACCESS_KEY_ID',
            'REVERB_APP_SECRET',
            'PUSHER_APP_SECRET',
            'VAPID_PRIVATE_KEY',
            'MAILCHANNELS_API_KEY',
            'SENTRY_LARAVEL_DSN',
            'GITHUB_TOKEN',
            'BEGIN PRIVATE KEY',
            'BEGIN RSA PRIVATE KEY',
            // No fingerprinting:
            'Laravel/',
            'PHP/8',
            'Filament v3',
            // No hostnames / internal paths:
            '145.223.73.170',
            '/opt/mbfd',
            '/var/www/html',
            'mbfd-hub-pgsql',
            'mbfd-hub-laravel',
            'mbfd-hub-redis',
            'cloudflared',
            '.workers.dev',
        ];

        foreach ($bannedSubstrings as $needle) {
            $this->assertStringNotContainsStringIgnoringCase(
                $needle,
                $html,
                "Public Security & Standards page must not expose `{$needle}`."
            );
        }
    }

    public function test_security_standards_page_marks_unimplemented_standards_as_not_claimed(): void
    {
        $response = $this->get('/security-standards');

        $response->assertOk();
        // Standards we explicitly do NOT claim — must be flagged "Not claimed" in the matrix.
        $response->assertSee('NFPA 1561', false);
        $response->assertSee('NFPA 1225', false);
        $response->assertSee('NFPA 1710 / 1720', false);
        $response->assertSee('NERIS / NFIRS', false);
        $response->assertSee('Not claimed', false);
    }

    public function test_security_standards_page_is_anonymously_routable(): void
    {
        // No authentication, no CSRF — a GET to /security-standards must not redirect
        // to /admin/login or any auth-gated path.
        $response = $this->get('/security-standards');

        $response->assertStatus(200);
        $response->assertHeaderMissing('Location');
    }
}
