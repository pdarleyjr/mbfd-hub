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
        
        // Guests should be redirected (to login)
        $this->assertTrue(
            $response->isRedirect() || in_array($response->status(), [401, 403]),
            "Guests should be redirected or denied from chatify. Got: {$response->status()}"
        );
    }

    public function test_allows_authenticated_users_to_access_chatify(): void
    {
        $user = User::factory()->create();
        
        $response = $this->actingAs($user)->get('/internal/chatify');
        
        // Should not be a redirect to login, should be accessible
        $this->assertFalse(
            $response->isRedirect() && str_contains($response->headers->get('Location', ''), 'login'),
            "Authenticated users should be able to access chatify. Got: {$response->status()}"
        );
    }

    public function test_guests_cannot_access_chatify_api_routes(): void
    {
        // Test the search route
        $response = $this->get('/internal/chatify/search');
        
        // Guests should be redirected or denied
        $this->assertTrue(
            $response->isRedirect() || in_array($response->status(), [401, 403]),
            "Guests should be denied from chatify API. Got: {$response->status()}"
        );
    }

    public function test_authenticated_users_can_access_chatify_api_routes(): void
    {
        $user = User::factory()->create();
        
        // Test the search route
        $response = $this->actingAs($user)->get('/internal/chatify/search');
        
        // Should return 200 (or 422 for validation, but not redirect to login)
        $this->assertNotEquals(302, $response->getStatusCode());
    }
}
