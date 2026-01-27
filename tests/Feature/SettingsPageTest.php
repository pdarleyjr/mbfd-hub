<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsPageTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    public function test_settings_page_renders_for_authenticated_user(): void
    {
        $this->actingAs($this->admin);

        $response = $this->get('/admin/settings');

        $response->assertOk();
    }

    public function test_settings_page_contains_push_notifications_section(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(Settings::class)
            ->assertSee('Push Notifications')
            ->assertSee('Enable Push');
    }

    public function test_settings_page_contains_user_management_section(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(Settings::class)
            ->assertSee('User Management')
            ->assertSee('Manage Users');
    }

    public function test_settings_page_contains_profile_section(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(Settings::class)
            ->assertSee('Profile')
            ->assertSee($this->admin->email);
    }

    public function test_unauthenticated_user_cannot_access_settings(): void
    {
        $response = $this->get('/admin/settings');

        $response->assertRedirect('/admin/login');
    }

    public function test_user_resource_not_in_sidebar(): void
    {
        $this->actingAs($this->admin);

        $response = $this->get('/admin');

        // Users link should not appear in sidebar navigation
        $response->assertDontSee('href="/admin/users"');
    }
}
