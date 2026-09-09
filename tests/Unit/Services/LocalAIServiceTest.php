<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\LocalAIService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class LocalAIServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cloudflare.ai.gateway.url', 'http://gateway.test:11440');
        config()->set('cloudflare.ai.gateway.capability', 'mbfd-general');
        config()->set('cloudflare.ai.gateway.credential', 'gateway-test-secret');
        config()->set('cloudflare.ai.gateway.timeout', 60);

        Cache::flush();
        Http::preventStrayRequests();
    }

    public function test_health_check_proves_authenticated_gateway_readiness(): void
    {
        Http::fake([
            'http://gateway.test:11440/health/ready' => Http::response(['status' => 'ready']),
        ]);

        $result = (new LocalAIService)->checkHealth();

        $this->assertSame([
            'configured' => true,
            'reachable' => true,
            'authenticated' => true,
            'capability' => 'mbfd-general',
            'available' => true,
            'error' => null,
        ], $result);
        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'GET'
                && $request->url() === 'http://gateway.test:11440/health/ready'
                && $request->hasHeader('Authorization', 'Bearer gateway-test-secret')
                && $request->hasHeader('X-MBFD-Capability', 'mbfd-general')
                && ($request->header('X-Request-ID')[0] ?? '') !== '';
        });
    }

    public function test_missing_gateway_configuration_fails_closed_without_a_request(): void
    {
        foreach (['url', 'capability', 'credential'] as $missing) {
            config()->set("cloudflare.ai.gateway.{$missing}", '');
            Http::fake();

            $service = new LocalAIService;
            $this->assertFalse($service->checkHealth()['available']);

            try {
                $service->runModel('ignored', [['role' => 'user', 'content' => 'test']]);
                $this->fail("Expected missing {$missing} configuration to fail closed.");
            } catch (\RuntimeException $exception) {
                $this->assertSame('MBFD AI gateway is not configured', $exception->getMessage());
            }

            config()->set("cloudflare.ai.gateway.{$missing}", match ($missing) {
                'url' => 'http://gateway.test:11440',
                'capability' => 'mbfd-general',
                'credential' => 'gateway-test-secret',
            });
        }

        Http::assertNothingSent();
    }

    public function test_health_check_distinguishes_rejected_credentials(): void
    {
        Http::fake([
            'http://gateway.test:11440/health/ready' => Http::response(['error' => 'unauthorized'], 401),
        ]);

        $result = (new LocalAIService)->checkHealth();

        $this->assertTrue($result['reachable']);
        $this->assertFalse($result['authenticated']);
        $this->assertFalse($result['available']);
        $this->assertSame('MBFD AI gateway rejected the consumer credential', $result['error']);
    }

    public function test_health_check_reports_server_failure_without_exposing_body(): void
    {
        Http::fake([
            'http://gateway.test:11440/health/ready' => Http::response('private upstream detail', 503),
        ]);

        $result = (new LocalAIService)->checkHealth();

        $this->assertTrue($result['reachable']);
        $this->assertTrue($result['authenticated']);
        $this->assertFalse($result['available']);
        $this->assertSame('MBFD AI gateway returned HTTP 503', $result['error']);
        $this->assertStringNotContainsString('private upstream detail', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function test_health_check_reports_connection_failure_without_transport_details(): void
    {
        Http::fake([
            'http://gateway.test:11440/health/ready' => Http::failedConnection('private network path'),
        ]);

        $result = (new LocalAIService)->checkHealth();

        $this->assertTrue($result['configured']);
        $this->assertFalse($result['reachable']);
        $this->assertFalse($result['authenticated']);
        $this->assertFalse($result['available']);
        $this->assertSame('MBFD AI gateway is unreachable', $result['error']);
        $this->assertStringNotContainsString('private network path', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function test_health_check_rejects_malformed_readiness(): void
    {
        Http::fake([
            'http://gateway.test:11440/health/ready' => Http::response('{"status":', 200),
        ]);

        $result = (new LocalAIService)->checkHealth();

        $this->assertFalse($result['available']);
        $this->assertSame('MBFD AI gateway returned malformed readiness', $result['error']);
    }

    public function test_health_check_is_cached_for_sixty_seconds(): void
    {
        Http::fakeSequence()
            ->push(['status' => 'ready'])
            ->push(['status' => 'unavailable'], 503);

        $service = new LocalAIService;
        $first = $service->checkHealth();
        $cached = $service->checkHealth();

        $this->assertSame($first, $cached);
        Http::assertSentCount(1);

        $this->travel(61)->seconds();
        $this->assertFalse($service->checkHealth()['available']);
        Http::assertSentCount(2);
    }

    public function test_generation_uses_authenticated_logical_gateway_contract(): void
    {
        Http::fake([
            'http://gateway.test:11440/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => '{"status":"ok"}']]],
            ]),
        ]);

        $result = (new LocalAIService)->runModel('ignored-physical-model', [
            ['role' => 'user', 'content' => 'test'],
        ]);

        $this->assertSame('{"status":"ok"}', data_get($result, 'result.response'));
        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'http://gateway.test:11440/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer gateway-test-secret')
                && $request->hasHeader('X-MBFD-Capability', 'mbfd-general')
                && $request['model'] === 'mbfd-general'
                && ($request->header('X-Request-ID')[0] ?? '') !== '';
        });
    }

    public function test_structured_generation_uses_same_gateway_contract(): void
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
                && $request->hasHeader('Authorization', 'Bearer gateway-test-secret')
                && $request->hasHeader('X-MBFD-Capability', 'mbfd-general')
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
            $this->assertSame('Local AI request failed: 503', $exception->getMessage());
        }

        Http::assertSentCount(2);
        Log::shouldHaveReceived('error')->once()->withArgs(
            fn (string $message, array $context): bool => $message === 'Local AI request failed'
                && $context['status'] === 503
                && $context['attempts'] === 2
                && ! array_key_exists('body', $context)
                && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'sensitive'),
        );
    }
}
