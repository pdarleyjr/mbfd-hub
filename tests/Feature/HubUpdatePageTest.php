<?php

namespace Tests\Feature;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class HubUpdatePageTest extends TestCase
{
    public function test_maintenance_mode_tells_members_the_hub_is_being_updated_and_reloads(): void
    {
        $page = view('errors.503', ['retryAfter' => null])->render();

        $this->assertStringContainsString('Updating MBFD Hub', $page);
        $this->assertStringContainsString('applying an update to improve MBFD Hub. Please try again shortly.', $page);
        $this->assertStringContainsString('If you have a form or other work in progress, keep your current page open.', $page);
        $this->assertStringContainsString('>Try again</a>', $page);
        $this->assertStringContainsString('mbfd-official-seal-256.png', $page);
        $this->assertStringContainsString('http-equiv="refresh"', $page);
        $this->assertStringNotContainsString('ERROR 503', $page);
    }

    public function test_the_edge_page_shown_while_the_container_is_unreachable_matches_the_hub_page(): void
    {
        $edge = (string) file_get_contents(base_path('infra/edge-maintenance/maintenance.html'));

        $this->assertStringContainsString('<h1>Updating MBFD Hub</h1>', $edge);
        $this->assertStringContainsString('If you have a form or other work in progress, keep your current page open.', $edge);
        $this->assertStringContainsString('/__mbfd-edge/mbfd-official-seal-256.png', $edge);
        $this->assertStringContainsString('#012766', $edge);
        // Self-contained: nothing may load from the backend that is down except the release probe.
        $this->assertDoesNotMatchRegularExpression('/<(link|script)[^>]+(href|src)=/i', $edge);

        $json = json_decode((string) file_get_contents(base_path('infra/edge-maintenance/unavailable.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('updating', $json['status']);

        $conf = (string) file_get_contents(base_path('infra/edge-maintenance/mbfd-hub-edge.conf'));
        $this->assertStringContainsString('proxy_intercept_errors off;', $conf, 'the Hub\'s own responses must never be replaced');
        $this->assertStringContainsString('error_page 502 504 =503 $mbfd_hub_edge_unavailable;', $conf);
    }

    public function test_a_real_outage_never_claims_to_be_an_update(): void
    {
        $page = view('errors.503', ['exception' => new HttpException(503)])->render();

        $this->assertStringContainsString('Hub temporarily unavailable', $page);
        $this->assertStringContainsString('ERROR 503', $page);
        $this->assertStringNotContainsString('being updated', $page);
        $this->assertStringNotContainsString('http-equiv="refresh"', $page);
    }
}
