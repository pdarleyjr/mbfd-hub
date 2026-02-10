<?php

namespace Tests\Feature\Panels;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PanelAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_panel_loads_for_authenticated_users(): void
    {
        $user = User::factory()->create();
        
        $response = $this->actingAs($user)->get('/admin');
        
        $response->assertStatus(200);
        // Check for push notification widget marker
        $response->assertSee('push-notification-widget', false);
    }

    public function test_training_panel_loads_for_authenticated_users(): void
    {
        $user = User::factory()->create();
        
        $response = $this->actingAs($user)->get('/training');
        
        $response->assertStatus(200);
        // Check for push notification widget marker
        $response->assertSee('push-notification-widget', false);
    }

    public function test_guests_are_redirected_from_admin_panel(): void
    {
        $response = $this->get('/admin');
        
        $response->assertRedirect('/login');
    }

    public function test_guests_are_redirected_from_training_panel(): void
    {
        $response = $this->get('/training');
        
        $response->assertRedirect('/login');
    }

    public function test_admin_panel_has_chatify_integration(): void
    {
        $user = User::factory()->create();
        
        $response = $this->actingAs($user)->get('/admin');
        
        $response->assertStatus(200);
        // Check for chatify link or widget
        $response->assertSeeInOrder(['chatify', 'chat'], false);
    }

    public function test_training_panel_has_chatify_integration(): void
    {
        $user = User::factory()->create();
        
        $response = $this->actingAs($user)->get('/training');
        
        $response->assertStatus(200);
        // Check for chatify link or widget
        $response->assertSeeInOrder(['chatify', 'chat'], false);
    }

    public function test_admin_panel_includes_notification_script(): void
    {
        $user = User::factory()->create();
        
        $response = $this->actingAs($user)->get('/admin');
        
        $response->assertStatus(200);
        // Check for notification script inclusion
        $response->assertSee('notifications', false);
    }

    public function test_training_panel_includes_notification_script(): void
    {
        $user = User::factory()->create();
        
        $response = $this->actingAs($user)->get('/training');
        
        $response->assertStatus(200);
        // Check for notification script inclusion
        $response->assertSee('notifications', false);
    }
}
