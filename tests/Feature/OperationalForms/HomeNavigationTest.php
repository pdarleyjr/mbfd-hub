<?php

declare(strict_types=1);

namespace Tests\Feature\OperationalForms;

use Tests\TestCase;

class HomeNavigationTest extends TestCase
{
    public function test_home_links_stations_and_operational_forms_to_the_exact_destinations(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Stations / Vehicles');
        $response->assertSee('href="'.url('/daily/stations').'"', false);
        $response->assertSee('Stations / Vehicles');
        $response->assertSee('Apparatus checkout, vehicle inspections, station inventory, and station requests');
        $response->assertSee('href="'.url('/employee/forms').'"', false);
        $response->assertSee('ICS 214 &amp; F-ROC reports', false);
        $response->assertSee('Open operational forms');
        $response->assertSee('href="'.url('/employee/forms').'"', false);
    }
}
