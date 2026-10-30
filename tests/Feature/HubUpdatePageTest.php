<?php

namespace Tests\Feature;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class HubUpdatePageTest extends TestCase
{
    public function test_maintenance_mode_tells_members_the_hub_is_being_updated_and_reloads(): void
    {
        $page = view('errors.503', ['retryAfter' => null])->render();

        $this->assertStringContainsString('MBFD Hub is being updated', $page);
        $this->assertStringContainsString('approximately 2 to 8 minutes', $page);
        $this->assertStringContainsString('http-equiv="refresh"', $page);
        $this->assertStringNotContainsString('ERROR 503', $page);
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
