<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\CloudflareAIService;
use App\Services\LocalAIService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class LocalAIServiceTest extends TestCase
{
    private string $credentialFile;

    protected function setUp(): void
    {
        parent::setUp();

        $credentialFile = tempnam(sys_get_temp_dir(), 'mbfd-hub-gateway-test-');
        $this->assertNotFalse($credentialFile);
        $this->credentialFile = $credentialFile;
        file_put_contents($this->credentialFile, 'unit-test-credential');

        config()->set('cloudflare.ai.gateway.url', 'http://gateway.test:11440');
        config()->set('cloudflare.ai.gateway.capability', 'mbfd-general');
        config()->set('cloudflare.ai.gateway.credential_file', $this->credentialFile);
        config()->set('cloudflare.ai.enabled', true);

        Cache::flush();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        @unlink($this->credentialFile);

        parent::tearDown();
    }

    public function test_container_binding_cannot_bypass_the_canonical_gateway(): void
    {
        config()->set('cloudflare.ai.driver', 'cloudflare');

        $this->assertInstanceOf(LocalAIService::class, app(CloudflareAIService::class));
    }

    public function test_deployable_configuration_has_no_direct_provider_model_catalog(): void
    {
        $this->assertNull(config('services.cloudflare.ai'));
        $this->assertArrayNotHasKey('models', config('cloudflare.ai'));
    }

    public function test_health_check_is_available_when_exact_model_is_present(): void
    {
        Http::fake([
            'http://gateway.test:11440/api/tags' => Http::response([
                'models' => [
                    ['name' => 'mbfd-general'],
                    ['name' => 'nomic-embed-text:latest'],
                ],
            ]),
        ]);

        $service = new LocalAIService;

        $this->assertSame([
            'configured' => true,
            'reachable' => true,
            'model_exists' => true,
            'available' => true,
            'error' => null,
        ], $service->checkHealth());
        $this->assertTrue($service->isEnabled());
        Http::assertSentCount(1);
    }

    public function test_health_check_normalizes_the_latest_model_alias(): void
    {
        config()->set('cloudflare.ai.gateway.capability', 'mbfd-general');
        Http::fake([
            'http://gateway.test:11440/api/tags' => Http::response([
                'models' => [['name' => 'mbfd-general:latest']],
            ]),
        ]);

        $result = (new LocalAIService)->checkHealth();

        $this->assertTrue($result['model_exists']);
        $this->assertTrue($result['available']);
    }

    public function test_health_check_is_unavailable_when_configured_model_is_missing(): void
    {
        Http::fake([
            'http://gateway.test:11440/api/tags' => Http::response([
                'models' => [['name' => 'another-model:latest']],
            ]),
        ]);

        $result = (new LocalAIService)->checkHealth();

        $this->assertSame([
            'configured' => true,
            'reachable' => true,
            'model_exists' => false,
            'available' => false,
            'error' => "Capability 'mbfd-general' not advertised by AI gateway",
        ], $result);
    }

    public function test_health_check_is_unavailable_after_http_failure(): void
    {
        Http::fake([
            'http://gateway.test:11440/api/tags' => Http::response([], 503),
        ]);

        $result = (new LocalAIService)->checkHealth();

        $this->assertSame([
            'configured' => true,
            'reachable' => false,
            'model_exists' => false,
            'available' => false,
            'error' => 'AI gateway returned HTTP 503',
        ], $result);
    }

    public function test_health_check_is_unavailable_after_connection_timeout(): void
    {
        Http::fake([
            'http://gateway.test:11440/api/tags' => Http::failedConnection('cURL error 28: Operation timed out'),
        ]);

        $result = (new LocalAIService)->checkHealth();

        $this->assertTrue($result['configured']);
        $this->assertFalse($result['reachable']);
        $this->assertFalse($result['model_exists']);
        $this->assertFalse($result['available']);
        $this->assertStringStartsWith('AI gateway unreachable:', (string) $result['error']);
        $this->assertStringContainsString('timed out', (string) $result['error']);
    }

    public function test_health_check_rejects_malformed_json(): void
    {
        Http::fake([
            'http://gateway.test:11440/api/tags' => Http::response(
                '{"models":',
                200,
                ['Content-Type' => 'application/json'],
            ),
        ]);

        $result = (new LocalAIService)->checkHealth();

        $this->assertSame([
            'configured' => true,
            'reachable' => true,
            'model_exists' => false,
            'available' => false,
            'error' => 'AI gateway returned malformed capability inventory',
        ], $result);
    }

    public function test_health_check_uses_cached_result_within_ttl(): void
    {
        Http::fakeSequence()
            ->push(['models' => [['name' => 'mbfd-general']]])
            ->push(['models' => []]);

        $service = new LocalAIService;
        $first = $service->checkHealth();
        $cached = $service->checkHealth();

        $this->assertSame($first, $cached);
        $this->assertTrue($cached['available']);
        Http::assertSentCount(1);
    }

    public function test_health_check_refreshes_after_cache_expiry(): void
    {
        Http::fakeSequence()
            ->push(['models' => [['name' => 'mbfd-general']]])
            ->push(['models' => []]);

        $service = new LocalAIService;
        $this->assertTrue($service->checkHealth()['available']);

        $this->travel(61)->seconds();

        $this->assertFalse($service->checkHealth()['available']);
        Http::assertSentCount(2);
    }

    public function test_health_check_recovers_after_cached_outage_expires(): void
    {
        Http::fakeSequence()
            ->pushStatus(503)
            ->push(['models' => [['name' => 'mbfd-general']]]);

        $service = new LocalAIService;
        $this->assertFalse($service->checkHealth()['available']);
        $this->assertFalse($service->checkHealth()['available']);
        Http::assertSentCount(1);

        $this->travel(61)->seconds();

        $this->assertTrue($service->checkHealth()['available']);
        Http::assertSentCount(2);
    }

    public function test_health_check_only_lists_models_and_does_not_load_one(): void
    {
        Http::fake([
            'http://gateway.test:11440/api/tags' => Http::response([
                'models' => [['name' => 'mbfd-general']],
            ]),
        ]);

        (new LocalAIService)->checkHealth();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'http://gateway.test:11440/api/tags'
            && ($request->header('Authorization')[0] ?? '') === 'Bearer unit-test-credential'
            && ($request->header('X-MBFD-Capability')[0] ?? '') === 'mbfd-general'
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $request->header('X-Request-ID')[0] ?? '') === 1
            && $request->body() === '');
    }

    public function test_structured_requests_use_gateway_native_schema_endpoint(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['labor' => ['type' => 'array']],
            'required' => ['labor'],
        ];

        Http::fake([
            'http://gateway.test:11440/api/chat' => Http::response([
                'message' => ['content' => '{"labor":[]}'],
            ]),
        ]);

        $result = (new LocalAIService)->runModel('ignored', [
            ['role' => 'user', 'content' => 'test'],
        ], [
            'temperature' => 0,
            'max_tokens' => 500,
            'response_schema' => $schema,
        ]);

        $this->assertSame('{"labor":[]}', data_get($result, 'result.response'));
        Http::assertSent(function (Request $request) use ($schema): bool {
            return $request->url() === 'http://gateway.test:11440/api/chat'
                && $request['model'] === 'mbfd-general'
                && $request['format'] === $schema
                && $request['stream'] === false
                && $request['think'] === false
                && data_get($request, 'options.num_predict') === 500;
        });
    }

    public function test_failed_responses_log_metadata_without_response_body(): void
    {
        Http::fakeSequence()
            ->push('sensitive provider response', 503)
            ->push('sensitive provider response', 503);
        Log::spy();

        try {
            (new LocalAIService)->runModel('ignored', [
                ['role' => 'user', 'content' => 'test'],
            ]);
            $this->fail('Expected the provider failure to be raised.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('AI gateway request failed: 503', $exception->getMessage());
        }

        Http::assertSentCount(2);
        Log::shouldHaveReceived('error')->once()->withArgs(
            fn (string $message, array $context): bool => $message === 'AI gateway request failed'
                && $context['status'] === 503
                && $context['attempts'] === 2
                && ! array_key_exists('body', $context)
                && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'sensitive'),
        );
    }

    public function test_structured_request_uses_the_frozen_gateway_contract(): void
    {
        $credentialFile = tempnam(sys_get_temp_dir(), 'mbfd-hub-gateway-test-');
        $this->assertNotFalse($credentialFile);
        file_put_contents($credentialFile, 'unit-test-credential');

        try {
            config()->set('cloudflare.ai.gateway.url', 'http://gateway.test:11440');
            config()->set('cloudflare.ai.gateway.capability', 'mbfd-general');
            config()->set('cloudflare.ai.gateway.credential_file', $credentialFile);

            Http::fake([
                'http://gateway.test:11440/api/chat' => Http::response([
                    'message' => ['content' => '{"labor":[]}'],
                ]),
            ]);

            $result = (new LocalAIService)->runModel('ignored', [
                ['role' => 'user', 'content' => 'test'],
            ], [
                'response_schema' => [
                    'type' => 'object',
                    'properties' => ['labor' => ['type' => 'array']],
                ],
            ]);

            $this->assertSame('{"labor":[]}', data_get($result, 'result.response'));
            Http::assertSent(function (Request $request): bool {
                $requestId = $request->header('X-Request-ID')[0] ?? '';

                return $request->url() === 'http://gateway.test:11440/api/chat'
                    && ($request->header('Authorization')[0] ?? '') === 'Bearer unit-test-credential'
                    && ($request->header('X-MBFD-Capability')[0] ?? '') === 'mbfd-general'
                    && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $requestId) === 1
                    && $request['model'] === 'mbfd-general';
            });
        } finally {
            @unlink($credentialFile);
        }
    }

    public function test_missing_gateway_credential_fails_closed_without_a_request(): void
    {
        config()->set('cloudflare.ai.gateway.url', 'http://gateway.test:11440');
        config()->set('cloudflare.ai.gateway.capability', 'mbfd-general');
        config()->set('cloudflare.ai.gateway.credential_file', sys_get_temp_dir().'/missing-mbfd-hub-gateway-credential');
        Http::fake();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AI gateway credential is unavailable');

        try {
            (new LocalAIService)->runModel('ignored', [
                ['role' => 'user', 'content' => 'test'],
            ]);
        } finally {
            Http::assertNothingSent();
        }
    }
}
