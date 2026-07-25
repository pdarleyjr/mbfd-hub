<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
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
        return $this->checkHealth()['available'] === true;
    }

    /**
     * Bounded, cached health/capability check for the local Ollama instance.
     *
     * Pings /api/tags (lightweight — does not load the model into VRAM) with a
     * short connect timeout. The result is cached for 60 seconds so it does not
     * fire on every page request. Distinguishes:
     *   - configured  (base URL + model name are set)
     *   - reachable   (Ollama daemon responds)
     *   - model_exists (the configured model is in /api/tags)
     *   - available   (all three true) — what isEnabled() returns
     *
     * @return array{configured: bool, reachable: bool, model_exists: bool, available: bool, error: ?string}
     */
    public function checkHealth(): array
    {
        $cacheKey = 'local_ai_health:' . md5($this->baseUrl . ':' . $this->localModel);
        return Cache::remember($cacheKey, now()->addSeconds(60), function () {
            $configured = ! empty($this->baseUrl) && ! empty($this->localModel);
            if (! $configured) {
                return ['configured' => false, 'reachable' => false, 'model_exists' => false, 'available' => false, 'error' => 'Ollama URL or model not configured'];
            }

            try {
                $response = Http::connectTimeout(3)->timeout(5)->get("{$this->baseUrl}/api/tags");
                if (! $response->successful()) {
                    Log::warning('Local AI health check: Ollama returned non-200', ['status' => $response->status()]);
                    return ['configured' => true, 'reachable' => false, 'model_exists' => false, 'available' => false, 'error' => "Ollama returned HTTP {$response->status()}"];
                }

                $models = collect(data_get($response->json(), 'models', []))
                    ->pluck('name')
                    ->filter()
                    ->map(fn ($n) => (string) $n)
                    ->all();

                $modelExists = in_array($this->localModel, $models, true);
                if (! $modelExists) {
                    Log::warning('Local AI health check: configured model not found', [
                        'model' => $this->localModel,
                        'available_models' => array_slice($models, 0, 10),
                    ]);
                    return ['configured' => true, 'reachable' => true, 'model_exists' => false, 'available' => false, 'error' => "Model '{$this->localModel}' not found in Ollama"];
                }

                return ['configured' => true, 'reachable' => true, 'model_exists' => true, 'available' => true, 'error' => null];
            } catch (\Exception $e) {
                Log::warning('Local AI health check: Ollama unreachable', ['error' => $e->getMessage()]);
                return ['configured' => true, 'reachable' => false, 'model_exists' => false, 'available' => false, 'error' => 'Ollama unreachable: ' . $e->getMessage()];
            }
        });
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

        // Local LLM cold-load (weights into VRAM) can take ~45s before the
        // first token; the Cloudflare 30s request timeout is too short for
        // that. Use the local-specific timeout (default 120s). Warm calls
        // return in a few seconds.
        $response = Http::timeout((int) config('cloudflare.ai.local.timeout', 120))
            ->connectTimeout(10)
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
