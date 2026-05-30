<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Local Ollama-backed AI service — a drop-in replacement for
 * {@see CloudflareAIService} that routes all generation to the on-prem
 * qwen3.6:35b model via Ollama's OpenAI-compatible endpoint.
 *
 * It normalizes Ollama responses into the Cloudflare Workers-AI
 * ['result' => ['response' => ...]] shape, so every inherited method
 * (generateAdminBulletSummary, prioritizeProjects, analyzeProject,
 * generateWeeklySummary, parseAIResponse, extractTextFromResponse) keeps
 * working unchanged. Bound over CloudflareAIService in AppServiceProvider
 * when cloudflare.ai.driver === 'local'.
 */
class LocalAIService extends CloudflareAIService
{
    protected string $baseUrl;
    protected string $localModel;

    public function __construct()
    {
        parent::__construct();
        $this->baseUrl = rtrim((string) config('cloudflare.ai.local.url', 'http://host.docker.internal:11434'), '/');
        $this->localModel = (string) config('cloudflare.ai.local.model', 'qwen3.6:35b');
    }

    public function isEnabled(): bool
    {
        // Local Ollama needs no account id / API token.
        return true;
    }

    public function checkRateLimit(): bool
    {
        // No neuron budget when running locally.
        return true;
    }

    public function getRateLimitUsage(): array
    {
        return ['used' => 0, 'limit' => 0, 'remaining' => 0, 'unlimited' => true];
    }

    /**
     * Run the local model and return a Cloudflare-shaped response array.
     *
     * @param  string  $model    Ignored (callers pass @cf/... ids); always uses the local model.
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     * @return array{result: array{response: string}}
     */
    public function runModel(string $model, array $messages, array $options = []): array
    {
        $payload = array_merge([
            'temperature' => 0.3,
            'max_tokens' => 2048,
        ], $options, [
            // qwen3.6 is a thinking model; reasoning_effort:none keeps replies
            // fast and guarantees non-empty `content`. Force the local model id
            // regardless of any @cf/... id the caller passed.
            'model' => $this->localModel,
            'messages' => $messages,
            'reasoning_effort' => 'none',
        ]);

        $response = Http::timeout((int) config('cloudflare.ai.timeouts.request', 120))
            ->connectTimeout((int) config('cloudflare.ai.timeouts.connect', 10))
            ->post("{$this->baseUrl}/v1/chat/completions", $payload);

        if (! $response->successful()) {
            Log::error('Local AI request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \Exception("Local AI request failed: {$response->status()}");
        }

        $content = (string) data_get($response->json(), 'choices.0.message.content', '');

        return ['result' => ['response' => $content]];
    }

    /**
     * Inventory chat (admin command center). Matches CloudflareAIService::chat():
     * returns ['message' => string, 'actions' => array].
     *
     * @param  array<string, mixed>  $metrics
     * @return array{message: string, actions: array<int, mixed>}
     */
    public function chat(string $message, array $metrics = []): array
    {
        $messages = [
            [
                'role' => 'system',
                'content' => 'You are the MBFD admin command-center assistant. Answer concisely and professionally. Use the provided inventory context when relevant.',
            ],
            [
                'role' => 'user',
                'content' => $message . (empty($metrics) ? '' : "\n\nINVENTORY CONTEXT:\n" . json_encode($metrics)),
            ],
        ];

        $result = $this->runModel($this->localModel, $messages);

        return [
            'message' => $result['result']['response'] ?? '',
            'actions' => [],
        ];
    }
}
