<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
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
     * @param  string  $model  Ignored (callers pass @cf/... ids); always uses the local model.
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     * @return array{result: array{response: string}}
     */
    public function runModel(string $model, array $messages, array $options = []): array
    {
        // Interactive browser requests may use a shorter ceiling than the
        // global cold-load timeout. Never forward this transport option.
        $requestTimeout = (int) ($options['request_timeout'] ?? config('cloudflare.ai.local.timeout', 120));
        $responseSchema = $options['response_schema'] ?? null;
        unset($options['request_timeout']);
        unset($options['response_schema']);

        if (is_array($responseSchema)) {
            $temperature = (float) ($options['temperature'] ?? 0.1);
            $maxTokens = (int) ($options['max_tokens'] ?? 2048);
            $response = $this->postWithBoundedRetry("{$this->baseUrl}/api/chat", [
                'model' => $this->localModel,
                'messages' => $messages,
                'stream' => false,
                'think' => false,
                'format' => $responseSchema,
                'options' => [
                    'temperature' => $temperature,
                    'num_predict' => $maxTokens,
                ],
            ], $requestTimeout);

            $content = (string) data_get($response->json(), 'message.content', '');

            return ['result' => ['response' => $content]];
        }

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
        $response = $this->postWithBoundedRetry("{$this->baseUrl}/v1/chat/completions", $payload, $requestTimeout);

        $content = (string) data_get($response->json(), 'choices.0.message.content', '');

        return ['result' => ['response' => $content]];
    }

    /**
     * Retry one time only for connection failures, rate limiting, and server
     * errors. Successful-but-invalid model output is validated by the caller
     * and is never retried here.
     *
     * @param  array<string, mixed>  $payload
     */
    private function postWithBoundedRetry(string $url, array $payload, int $timeout): Response
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $response = Http::timeout($timeout)
                    ->connectTimeout(10)
                    ->post($url, $payload);
            } catch (ConnectionException $exception) {
                if ($attempt >= 2) {
                    throw $exception;
                }

                usleep(250_000);

                continue;
            }

            $retryable = $response->status() === 429 || $response->serverError();
            if ($retryable && $attempt < 2) {
                usleep(250_000);

                continue;
            }

            if (! $response->successful()) {
                Log::error('Local AI request failed', [
                    'status' => $response->status(),
                    'response_bytes' => strlen($response->body()),
                    'attempts' => $attempt,
                ]);
                throw new \RuntimeException("Local AI request failed: {$response->status()}");
            }

            return $response;
        }
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
                'content' => $message.(empty($metrics) ? '' : "\n\nINVENTORY CONTEXT:\n".json_encode($metrics)),
            ],
        ];

        $result = $this->runModel($this->localModel, $messages);

        return [
            'message' => $result['result']['response'] ?? '',
            'actions' => [],
        ];
    }
}
