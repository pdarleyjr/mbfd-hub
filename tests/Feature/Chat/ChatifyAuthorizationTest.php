<?php

namespace Tests\Feature\Chat;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatifyAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_redirects_guests_from_chatify_routes(): void
    {
        $response = $this->get('/internal/chatify');
        
        $response->assertRedirect('/login');
    }

    public function test_allows_authenticated_users_to_access_chatify(): void
    {
        $user = User::factory()->create();
        
        $response = $this->actingAs($user)->get('/internal/chatify');
        
        $response->assertStatus(200);
    }

    public function test_guests_cannot_access_chatify_api_routes(): void
    {
        // Test the search route
        $response = $this->get('/internal/chatify/search');
        
        $response->assertRedirect('/login');
    }

    public function test_authenticated_users_can_access_chatify_api_routes(): void
    {
        $user = User::factory()->create();
        
        // Test the search route
        $response = $this->actingAs($user)->get('/internal/chatify/search');
        
        // Should return 200 (or 422 for validation, but not redirect)
        $this->assertNotEquals(302, $response->getStatusCode());
    }
}
