<?php

declare(strict_types=1);

namespace Tests\Feature\MediaControl;

use App\Models\User;
use App\Services\MediaControl\MediaControlMediaClient;
use App\Services\MediaControl\MediaControlRequestFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class MediaControlMediaClientTest extends TestCase
{
    private const TOKEN = 'test-mc-ingest-token-not-real';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.media_control.base_url', 'https://media.example.test/');
        config()->set('services.media_control.ingest_token', self::TOKEN);
        config()->set('services.media_control.ingest_timeout', 10);
    }

    public function test_requests_carry_bearer_token_and_actor_header(): void
    {
        Http::fake(['media.example.test/api/hub-ingest/v1/grants' => Http::response([
            'grant_id' => 'g-1', 'upload_url' => 'https://media.example.test/api/hub-ingest/v1/upload',
            'upload_token' => 'once', 'expires_at' => '2026-10-15T12:00:00Z', 'max_bytes' => 10, 'chunk_size' => 52428800,
        ], 201)]);

        $grant = app(MediaControlMediaClient::class)->createGrant($this->actor(), [
            'filename' => 'EMS_VW1.png', 'size' => 10, 'mime' => 'image/png', 'title' => 'EMS', 'display_span' => 3,
        ]);

        self::assertSame('g-1', $grant['grant_id']);
        Http::assertSent(function (Request $request): bool {
            $actor = json_decode((string) base64_decode(strtr($request->header('X-MBFD-Hub-Actor')[0], '-_', '+/'), true), true);

            return $request->method() === 'POST'
                && $request->url() === 'https://media.example.test/api/hub-ingest/v1/grants'
                && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN)
                && $request['display_span'] === 3
                && $actor === ['hub_user_id' => 42, 'name' => 'Pat Example', 'email' => 'pat@example.test']
                && ! str_contains($request->header('X-MBFD-Hub-Actor')[0], '=');
        });
    }

    public function test_list_sends_only_present_filters(): void
    {
        Http::fake(['media.example.test/*' => Http::response(['items' => [], 'next_cursor' => null])]);

        app(MediaControlMediaClient::class)->list($this->actor(), ['kind' => 'image', 'q' => '', 'limit' => 50, 'cursor' => null]);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://media.example.test/api/hub-ingest/v1/content?kind=image&limit=50');
    }

    public function test_unconfigured_client_fails_without_calling_media_control(): void
    {
        config()->set('services.media_control.ingest_token', '');
        Http::fake();

        $failure = $this->failure(fn () => app(MediaControlMediaClient::class)->health($this->actor()));

        self::assertSame(MediaControlRequestFailed::NOT_CONFIGURED, $failure->kind);
        self::assertStringContainsString('MEDIA_CONTROL_INGEST_TOKEN', $failure->getMessage());
        Http::assertNothingSent();
    }

    public function test_unauthorized_response_explains_the_credential_mismatch(): void
    {
        Http::fake(['media.example.test/*' => Http::response(['error' => 'Unauthorized', 'code' => 'unauthorized'], 401)]);

        $failure = $this->failure(fn () => app(MediaControlMediaClient::class)->health($this->actor()));

        self::assertSame(401, $failure->status);
        self::assertSame(MediaControlRequestFailed::REJECTED, $failure->kind);
        self::assertStringContainsString('ingest credential', $failure->getMessage());
    }

    public function test_conflict_surfaces_media_control_error_text(): void
    {
        Http::fake(['media.example.test/*' => Http::response([
            'error' => 'This item is showing on Display 2.', 'code' => 'content_in_use', 'usage' => ['displays' => 1],
        ], 409)]);

        $failure = $this->failure(fn () => app(MediaControlMediaClient::class)->archive($this->actor(), 'c-9'));

        self::assertTrue($failure->isConflict());
        self::assertSame('content_in_use', $failure->errorCode);
        self::assertSame('This item is showing on Display 2.', $failure->getMessage());
    }

    public function test_server_errors_become_a_friendly_retry_message_and_never_log_the_token(): void
    {
        Log::spy();
        Http::fake(['media.example.test/*' => Http::response(['error' => 'stack trace here'], 500)]);

        $failure = $this->failure(fn () => app(MediaControlMediaClient::class)->update($this->actor(), 'c-1', ['title' => 'New']));

        self::assertSame(MediaControlRequestFailed::UNAVAILABLE, $failure->kind);
        self::assertStringNotContainsString('stack trace', $failure->getMessage());
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === 'media_control.ingest.rejected'
            && $context['status'] === 500
            && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), self::TOKEN));
    }

    public function test_timeouts_become_a_friendly_retry_message(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $failure = $this->failure(fn () => app(MediaControlMediaClient::class)->grantStatus($this->actor(), 'g-1'));

        self::assertSame(MediaControlRequestFailed::UNAVAILABLE, $failure->kind);
        self::assertStringContainsString('try again', $failure->getMessage());
    }

    public function test_ingest_not_configured_on_media_control_is_reported_as_setup_problem(): void
    {
        Http::fake(['media.example.test/*' => Http::response(['error' => 'not configured', 'code' => 'ingest_not_configured'], 503)]);

        $failure = $this->failure(fn () => app(MediaControlMediaClient::class)->health($this->actor()));

        self::assertSame(MediaControlRequestFailed::NOT_CONFIGURED, $failure->kind);
        self::assertStringContainsString('HUB_INGEST_TOKEN', $failure->getMessage());
    }

    public function test_origin_is_derived_from_base_url(): void
    {
        config()->set('services.media_control.base_url', 'https://Media.Example.test:8443/base/');

        self::assertSame('https://media.example.test:8443', app(MediaControlMediaClient::class)->origin());
    }

    private function actor(): User
    {
        return (new User)->forceFill(['id' => 42, 'name' => 'Pat Example', 'email' => 'pat@example.test']);
    }

    private function failure(callable $call): MediaControlRequestFailed
    {
        try {
            $call();
        } catch (MediaControlRequestFailed $failure) {
            return $failure;
        }
        self::fail('Expected MediaControlRequestFailed.');
    }
}
