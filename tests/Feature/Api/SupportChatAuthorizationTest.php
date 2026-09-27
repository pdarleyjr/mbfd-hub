<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
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

    public function test_worker_connection_failure_returns_service_unavailable(): void
    {
        config([
            'cloudflare.worker_url' => 'https://worker.example.test',
            'cloudflare.worker_api_secret' => 'test-secret',
        ]);

        Http::fake(function (): never {
            throw new ConnectionException('Connection timed out');
        });

        $this->actingAs(User::factory()->create())
            ->postJson('/api/public/support-chat', ['message' => 'Test support chat.'])
            ->assertServiceUnavailable()
            ->assertJsonPath('error', 'Support chat is unavailable. Please try again later.');
    }
}
