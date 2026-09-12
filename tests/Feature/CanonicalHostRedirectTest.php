<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class CanonicalHostRedirectTest extends TestCase
{
    public function test_daily_apex_requests_redirect_to_the_configured_canonical_origin_with_path_and_query(): void
    {
        config(['app.url' => 'https://www.mbfdhub.com']);

        $this->withHeader('Host', 'mbfdhub.com')
            ->get('/daily/stations?foo=bar')
            ->assertStatus(308)
            ->assertHeader('Location', 'https://www.mbfdhub.com/daily/stations?foo=bar');
    }

    public function test_daily_requests_on_the_canonical_host_are_not_canonical_redirected(): void
    {
        config(['app.url' => 'https://www.mbfdhub.com']);

        $response = $this->withHeader('Host', 'www.mbfdhub.com')
            ->get('/daily/stations?foo=bar');

        $response->assertStatus(302);
        $this->assertStringEndsWith('/login', (string) $response->headers->get('Location'));
    }

    public function test_localhost_remains_usable_for_daily_feature_tests(): void
    {
        config(['app.url' => 'https://www.mbfdhub.com']);

        $this->withHeader('Host', 'localhost')
            ->get('/daily/stations?foo=bar')
            ->assertRedirect('/login');
    }
}
