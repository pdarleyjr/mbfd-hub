<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Private MBFD AI Gateway service — a drop-in replacement for
 * {@see CloudflareAIService} that uses only the logical capability contract.
 *
 * It normalizes gateway responses into the Cloudflare Workers-AI
 * ['result' => ['response' => ...]] shape, so every inherited method
 * (generateAdminBulletSummary, prioritizeProjects, analyzeProject,
 * generateWeeklySummary, parseAIResponse, extractTextFromResponse) keeps
 * working unchanged. Bound over CloudflareAIService in AppServiceProvider
 * when cloudflare.ai.driver === 'local'.
 */
class LocalAIService extends CloudflareAIService
{
    protected string $baseUrl;

    protected string $capability;

    protected string $credentialFile;

    public function __construct()
    {
        parent::__construct();
        $this->baseUrl = rtrim((string) config('cloudflare.ai.gateway.url', 'http://172.20.0.1:11440'), '/');
        $this->capability = (string) config('cloudflare.ai.gateway.capability', 'mbfd-general');
        $this->credentialFile = (string) config('cloudflare.ai.gateway.credential_file', '/run/secrets/mbfd-hub-ai-gateway-token');
    }

    public function isEnabled(): bool
    {
        return $this->checkHealth()['available'];
    }

    /**
     * Perform a bounded, cached capability check without loading a model.
     *
     * @return array{
     *     configured: bool,
     *     reachable: bool,
     *     model_exists: bool,
     *     available: bool,
     *     error: string|null
     * }
     */
    public function checkHealth(): array
    {
        $cacheKey = 'local_ai_health:'.md5($this->baseUrl.':'.$this->capability.':'.$this->credentialFile);

        return Cache::remember($cacheKey, now()->addSeconds(60), function (): array {
            if ($this->baseUrl === '' || $this->capability === '' || $this->credentialFile === '') {
                return [
                    'configured' => false,
                    'reachable' => false,
                    'model_exists' => false,
                    'available' => false,
                    'error' => 'AI gateway configuration is incomplete',
                ];
            }

            try {
                $headers = $this->gatewayHeaders();
            } catch (\RuntimeException) {
                return [
                    'configured' => false,
                    'reachable' => false,
                    'model_exists' => false,
                    'available' => false,
                    'error' => 'AI gateway credential is unavailable',
                ];
            }

            try {
                $response = Http::withHeaders($headers)
                    ->connectTimeout(3)
                    ->timeout(5)
                    ->get("{$this->baseUrl}/api/tags");
            } catch (ConnectionException $exception) {
                Log::warning('AI gateway health check failed', [
                    'error' => $exception->getMessage(),
                ]);

                return [
                    'configured' => true,
                    'reachable' => false,
                    'model_exists' => false,
                    'available' => false,
                    'error' => 'AI gateway unreachable: '.$exception->getMessage(),
                ];
            }

            if (! $response->successful()) {
                Log::warning('AI gateway health check returned non-200', [
                    'status' => $response->status(),
                ]);

                return [
                    'configured' => true,
                    'reachable' => false,
                    'model_exists' => false,
                    'available' => false,
                    'error' => "AI gateway returned HTTP {$response->status()}",
                ];
            }

            try {
                $inventory = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $inventory = null;
            }

            if (! is_array($inventory) || ! isset($inventory['models']) || ! is_array($inventory['models'])) {
                Log::warning('AI gateway health check returned malformed capability inventory');

                return [
                    'configured' => true,
                    'reachable' => true,
                    'model_exists' => false,
                    'available' => false,
                    'error' => 'AI gateway returned malformed capability inventory',
                ];
            }

            $models = collect($inventory['models'])
                ->pluck('name')
                ->filter(fn (mixed $name): bool => is_string($name) && $name !== '')
                ->map(fn (string $name): string => $this->normalizeModelName($name))
                ->values()
                ->all();
            $modelExists = in_array($this->normalizeModelName($this->capability), $models, true);

            if (! $modelExists) {
                Log::warning('AI gateway health check did not advertise capability', [
                    'capability' => $this->capability,
                    'available_models' => array_slice($models, 0, 10),
                ]);

                return [
                    'configured' => true,
                    'reachable' => true,
                    'model_exists' => false,
                    'available' => false,
                    'error' => "Capability '{$this->capability}' not advertised by AI gateway",
                ];
            }

            return [
                'configured' => true,
                'reachable' => true,
                'model_exists' => true,
                'available' => true,
                'error' => null,
            ];
        });
    }

    private function normalizeModelName(string $model): string
    {
        $model = trim($model);

        return str_ends_with($model, ':latest')
            ? substr($model, 0, -strlen(':latest'))
            : $model;
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
     * Run the gateway capability and return a Cloudflare-shaped response array.
     *
     * @param  string  $model  Ignored; the gateway always receives the configured logical capability.
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     * @return array{result: array{response: string}}
     */
    public function runModel(string $model, array $messages, array $options = []): array
    {
        // Interactive browser requests may use a shorter ceiling than the
        // global cold-load timeout. Never forward this transport option.
        $requestTimeout = (int) ($options['request_timeout'] ?? config('cloudflare.ai.gateway.timeout', 120));
        $responseSchema = $options['response_schema'] ?? null;
        unset($options['request_timeout']);
        unset($options['response_schema']);

        if (is_array($responseSchema)) {
            $temperature = (float) ($options['temperature'] ?? 0.1);
            $maxTokens = (int) ($options['max_tokens'] ?? 2048);
            $response = $this->postWithBoundedRetry("{$this->baseUrl}/api/chat", [
                'model' => $this->capability,
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
            'model' => $this->capability,
            'messages' => $messages,
            'reasoning_effort' => 'none',
        ]);

        // The private gateway may cold-load its routed model, so retain the
        // existing bounded 120-second default instead of the cloud timeout.
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
        $headers = $this->gatewayHeaders();

        while (true) {
            $attempt++;

            try {
                $response = Http::withHeaders($headers)
                    ->timeout($timeout)
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
                Log::error('AI gateway request failed', [
                    'status' => $response->status(),
                    'response_bytes' => strlen($response->body()),
                    'attempts' => $attempt,
                ]);
                throw new \RuntimeException("AI gateway request failed: {$response->status()}");
            }

            return $response;
        }
    }

    /**
     * @return array<string, string>
     */
    private function gatewayHeaders(): array
    {
        if ($this->credentialFile === '' || ! is_file($this->credentialFile) || ! is_readable($this->credentialFile)) {
            throw new \RuntimeException('AI gateway credential is unavailable');
        }

        $credential = @file_get_contents($this->credentialFile);
        if ($credential === false || trim($credential) === '') {
            throw new \RuntimeException('AI gateway credential is unavailable');
        }

        return [
            'Authorization' => 'Bearer '.trim($credential),
            'X-MBFD-Capability' => $this->capability,
            'X-Request-ID' => (string) Str::uuid(),
        ];
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

        $result = $this->runModel($this->capability, $messages);

        return [
            'message' => $result['result']['response'] ?? '',
            'actions' => [],
        ];
    }
}
