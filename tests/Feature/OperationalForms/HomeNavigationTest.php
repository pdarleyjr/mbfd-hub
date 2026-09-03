<?php

declare(strict_types=1);

namespace Tests\Feature\OperationalForms;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_links_stations_and_operational_forms_to_the_exact_destinations(): void
    {
        $this->withoutVite();
        $this->actingAsCanonicalFixture();
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Station / Vehicles / Equipment');
        $response->assertSee('href="'.url('/daily/stations').'"', false);
        $response->assertSee('Station / Vehicles / Equipment');
        $response->assertSee('Apparatus checkout, vehicle inspections, station inventory, and station requests');
        $response->assertSee('href="'.url('/employee/forms').'"', false);
        $response->assertSee('ICS 214 &amp; F-ROC reports', false);
        $response->assertSee('Open operational forms');
        $response->assertSee('request approved uniform items');
        $response->assertSee('href="'.url('/employee/forms').'"', false);
    }
}
