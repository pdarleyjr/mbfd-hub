<?php

declare(strict_types=1);

namespace Tests\Feature\OperationalForms;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class HomeNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_entitled_home_renders_the_exact_quick_access_stack_and_preserves_pulsepoint(): void
    {
        $this->withoutVite();
        $user = $this->actingAsCanonicalFixture();
        $user->assignRole(Role::findOrCreate('admin', 'web'));

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Quick Access');
        $response->assertSeeInOrder([
            'Station / Vehicles / Equipment',
            'Employee Portal',
            'ICS Forms',
            'Workgroup Dashboard',
            'Pump Panel',
            'Videos',
            'Media Control',
        ]);
        $response->assertSee('Apparatus checkout, vehicle inspections, station inventory, and station requests');
        $response->assertSee('View assigned gear, track requests, and request approved uniform items');
        $response->assertSee('ICS 214 &amp; F-ROC reports', false);
        $response->assertSee('Evaluations &amp; reviews', false);
        $response->assertSee('Training simulator');
        $response->assertSee('Training videos, support services content, and live media');
        $response->assertSee('Videowall controls, displays, and classroom media management');

        foreach ([
            url('/daily/stations'),
            url('/employee'),
            url('/employee/forms'),
            url('/workgroups'),
            'https://pdarleyjr.github.io/puc-sim-manual-ui/',
            'https://videos.mbfdhub.com',
            'https://media.mbfdhub.com/api/auth/hub/start',
        ] as $destination) {
            $response->assertSee('href="'.$destination.'"', false);
        }

        $response->assertDontSee('/workgroups/login', false);
        $response->assertDontSee('MBFD Support Assistant');
        $response->assertDontSee('aiChat()', false);
        $response->assertSee('x-data="pulsePointFeed()"', false);
        $response->assertSee('function pulsePointFeed()', false);
        $response->assertSee('/api/incidents', false);
    }

    public function test_admin_staying_user_sees_direct_admin_panel_link_without_legacy_login(): void
    {
        $this->withoutVite();
        $user = $this->actingAsCanonicalFixture();
        $user->assignRole(Role::findOrCreate('logistics_admin', 'web'));

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Admin Panel');
        $response->assertSee('href="'.url('/admin').'"', false);
        $response->assertDontSee('/admin/login', false);
        $response->assertDontSee('Admin Login');
    }

    public function test_training_only_user_does_not_see_misleading_admin_panel_control(): void
    {
        $this->withoutVite();
        $user = $this->actingAsCanonicalFixture();
        $user->assignRole(Role::findOrCreate('training_admin', 'web'));

        $response = $this->get('/');

        $response->assertOk();
        $response->assertDontSee('Admin Panel');
        $response->assertDontSee('/admin/login', false);
    }

    public function test_non_media_control_entitled_user_does_not_see_privileged_card(): void
    {
        $this->withoutVite();
        $this->actingAsCanonicalFixture();

        $response = $this->get('/');

        $response->assertOk();
        $response->assertDontSee('Media Control');
        $response->assertDontSee('https://media.mbfdhub.com/api/auth/hub/start', false);
    }

    public function test_anonymous_home_requires_canonical_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }
}
