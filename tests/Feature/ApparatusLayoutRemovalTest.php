<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApparatusLayoutRemovalTest extends TestCase
{
    public function test_planner_routes_are_removed_while_general_apparatus_routes_remain(): void
    {
        $plannerRoutes = collect(Route::getRoutes())->filter(
            fn ($route) => str_contains($route->uri(), 'apparatus-layout')
        );

        $this->assertCount(0, $plannerRoutes);

        $generalApparatusRoute = Route::getRoutes()->match(Request::create('/api/public/apparatuses'));

        $this->assertSame('api/public/apparatuses', $generalApparatusRoute->uri());
        $this->assertSame(
            'App\\Http\\Controllers\\Api\\ApparatusController@index',
            $generalApparatusRoute->getActionName()
        );
    }

    public function test_home_does_not_expose_the_removed_planner_and_its_retired_route_returns_404(): void
    {
        $this->withoutVite();

        $this->get('/')
            ->assertOk()
            ->assertDontSee('href="'.url('/apparatus-layout').'"', false)
            ->assertDontSee('Apparatus Equipment Planner');

        $this->get('/apparatus-layout')->assertNotFound();
        $this->getJson('/api/public/apparatus-layout/tools')->assertNotFound();
    }
}
