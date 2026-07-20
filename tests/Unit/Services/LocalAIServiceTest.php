<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\LocalAIService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class LocalAIServiceTest extends TestCase
{
    public function test_structured_requests_use_ollama_native_schema_endpoint(): void
    {
        config()->set('cloudflare.ai.local.url', 'http://ollama.test:11434');
        config()->set('cloudflare.ai.local.model', 'qwen3.6:35b');
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
        config()->set('cloudflare.ai.local.url', 'http://ollama.test:11434');
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
