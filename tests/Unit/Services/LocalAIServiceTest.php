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

        config()->set('cloudflare.ai.local.url', 'http://ollama.test:11434');
        config()->set('cloudflare.ai.local.model', 'qwen3.6:35b');

        Cache::flush();
        Http::preventStrayRequests();
    }

    public function test_health_check_is_available_when_exact_model_is_present(): void
    {
        Http::fake([
            'http://ollama.test:11434/api/tags' => Http::response([
                'models' => [
                    ['name' => 'qwen3.6:35b'],
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
        config()->set('cloudflare.ai.local.model', 'qwen3.6');
        Http::fake([
            'http://ollama.test:11434/api/tags' => Http::response([
                'models' => [['name' => 'qwen3.6:latest']],
            ]),
        ]);

        $result = (new LocalAIService)->checkHealth();

        $this->assertTrue($result['model_exists']);
        $this->assertTrue($result['available']);
    }

    public function test_health_check_is_unavailable_when_configured_model_is_missing(): void
    {
        Http::fake([
            'http://ollama.test:11434/api/tags' => Http::response([
                'models' => [['name' => 'another-model:latest']],
            ]),
        ]);

        $result = (new LocalAIService)->checkHealth();

        $this->assertSame([
            'configured' => true,
            'reachable' => true,
            'model_exists' => false,
            'available' => false,
            'error' => "Model 'qwen3.6:35b' not found in Ollama",
        ], $result);
    }

    public function test_health_check_is_unavailable_after_http_failure(): void
    {
        Http::fake([
            'http://ollama.test:11434/api/tags' => Http::response([], 503),
        ]);

        $result = (new LocalAIService)->checkHealth();

        $this->assertSame([
            'configured' => true,
            'reachable' => false,
            'model_exists' => false,
            'available' => false,
            'error' => 'Ollama returned HTTP 503',
        ], $result);
    }

    public function test_health_check_is_unavailable_after_connection_timeout(): void
    {
        Http::fake([
            'http://ollama.test:11434/api/tags' => Http::failedConnection('cURL error 28: Operation timed out'),
        ]);

        $result = (new LocalAIService)->checkHealth();

        $this->assertTrue($result['configured']);
        $this->assertFalse($result['reachable']);
        $this->assertFalse($result['model_exists']);
        $this->assertFalse($result['available']);
        $this->assertStringStartsWith('Ollama unreachable:', (string) $result['error']);
        $this->assertStringContainsString('timed out', (string) $result['error']);
    }

    public function test_health_check_rejects_malformed_json(): void
    {
        Http::fake([
            'http://ollama.test:11434/api/tags' => Http::response(
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
            'error' => 'Ollama returned malformed model inventory',
        ], $result);
    }

    public function test_health_check_uses_cached_result_within_ttl(): void
    {
        Http::fakeSequence()
            ->push(['models' => [['name' => 'qwen3.6:35b']]])
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
            ->push(['models' => [['name' => 'qwen3.6:35b']]])
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
            ->push(['models' => [['name' => 'qwen3.6:35b']]]);

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
            'http://ollama.test:11434/api/tags' => Http::response([
                'models' => [['name' => 'qwen3.6:35b']],
            ]),
        ]);

        (new LocalAIService)->checkHealth();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'http://ollama.test:11434/api/tags'
            && $request->body() === '');
    }

    public function test_structured_requests_use_ollama_native_schema_endpoint(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['labor' => ['type' => 'array']],
            'required' => ['labor'],
        ];

        Http::fake([
            'http://ollama.test:11434/api/chat' => Http::response([
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
            return $request->url() === 'http://ollama.test:11434/api/chat'
                && $request['model'] === 'qwen3.6:35b'
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
