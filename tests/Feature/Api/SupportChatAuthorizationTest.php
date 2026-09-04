<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SupportChatAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_anonymous_request_cannot_query_admin_managed_knowledge_base(): void
    {
        $this->postJson('/api/public/support-chat', [
            'message' => 'Summarize the available documents.',
        ])->assertUnauthorized();
    }

    public function test_authenticated_session_reaches_the_support_chat_controller(): void
    {
        config(['cloudflare.worker_api_secret' => null]);

        $this->actingAs(User::factory()->create())
            ->postJson('/api/public/support-chat', [
                'message' => 'Summarize the available documents.',
            ])
            ->assertServiceUnavailable()
            ->assertJsonPath('error', 'Support chat is not configured.');
    }
}
