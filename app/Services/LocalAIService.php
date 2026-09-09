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
 * Compatibility-named AI service that routes generation through the
 * authenticated MBFD AI Gateway. Applications request only a logical
 * capability; the gateway exclusively owns backend and physical-model choice.
 *
 * It normalizes Ollama responses into the Cloudflare Workers-AI
 * ['result' => ['response' => ...]] shape, so every inherited method
 * (generateAdminBulletSummary, prioritizeProjects, analyzeProject,
 * generateWeeklySummary, parseAIResponse, extractTextFromResponse) keeps
 * working unchanged. Bound over CloudflareAIService in AppServiceProvider for
 * the gateway driver and its temporary legacy local alias.
 */
class LocalAIService extends CloudflareAIService
{
    protected string $baseUrl;

    protected string $capability;

    protected string $credential;

    protected int $gatewayTimeout;

    public function __construct()
    {
        parent::__construct();
        $this->baseUrl = rtrim((string) config('cloudflare.ai.gateway.url', ''), '/');
        $this->capability = trim((string) config('cloudflare.ai.gateway.capability', ''));
        $this->credential = trim((string) config('cloudflare.ai.gateway.credential', ''));
        $this->gatewayTimeout = (int) config('cloudflare.ai.gateway.timeout', 360);
    }

    public function isEnabled(): bool
    {
        return $this->checkHealth()['available'];
    }

    /**
     * Perform a bounded, authenticated gateway readiness check without loading
     * a model. A successful request proves the configured consumer credential
     * is accepted; generation separately proves its capability admission.
     *
     * @return array{
     *     configured: bool,
     *     reachable: bool,
     *     authenticated: bool,
     *     capability: string|null,
     *     available: bool,
     *     error: string|null
     * }
     */
    public function checkHealth(): array
    {
        $cacheKey = 'mbfd_ai_gateway_health:'.md5($this->baseUrl.':'.$this->capability.':'.($this->credential !== '' ? 'configured' : 'missing'));

        return Cache::remember($cacheKey, now()->addSeconds(60), function (): array {
            if (! $this->isConfigured()) {
                return [
                    'configured' => false,
                    'reachable' => false,
                    'authenticated' => false,
                    'capability' => $this->capability !== '' ? $this->capability : null,
                    'available' => false,
                    'error' => 'MBFD AI gateway is not configured',
                ];
            }

            try {
                $response = $this->gatewayRequest($this->requestId())
                    ->connectTimeout(3)
                    ->timeout(5)
                    ->get("{$this->baseUrl}/health/ready");
            } catch (ConnectionException $exception) {
                Log::warning('MBFD AI gateway health check failed', [
                    'exception_type' => $exception::class,
                ]);

                return [
                    'configured' => true,
                    'reachable' => false,
                    'authenticated' => false,
                    'capability' => $this->capability,
                    'available' => false,
                    'error' => 'MBFD AI gateway is unreachable',
                ];
            }

            if (! $response->successful()) {
                $authenticated = ! in_array($response->status(), [401, 403], true);
                Log::warning('MBFD AI gateway health check returned non-200', [
                    'status' => $response->status(),
                    'request_id' => $response->header('X-Request-ID'),
                ]);

                return [
                    'configured' => true,
                    'reachable' => true,
                    'authenticated' => $authenticated,
                    'capability' => $this->capability,
                    'available' => false,
                    'error' => $authenticated
                        ? "MBFD AI gateway returned HTTP {$response->status()}"
                        : 'MBFD AI gateway rejected the consumer credential',
                ];
            }

            try {
                $readiness = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $readiness = null;
            }

            if (! is_array($readiness) || ($readiness['status'] ?? null) !== 'ready') {
                Log::warning('MBFD AI gateway returned malformed readiness');

                return [
                    'configured' => true,
                    'reachable' => true,
                    'authenticated' => true,
                    'capability' => $this->capability,
                    'available' => false,
                    'error' => 'MBFD AI gateway returned malformed readiness',
                ];
            }

            return [
                'configured' => true,
                'reachable' => true,
                'authenticated' => true,
                'capability' => $this->capability,
                'available' => true,
                'error' => null,
            ];
        });
    }

    private function isConfigured(): bool
    {
        if ($this->baseUrl === '' || $this->capability === '' || $this->credential === '') {
            return false;
        }

        $scheme = parse_url($this->baseUrl, PHP_URL_SCHEME);

        return in_array($scheme, ['http', 'https'], true);
    }

    public function checkRateLimit(): bool
    {
        // Gateway admission policy owns per-consumer rate limiting.
        return true;
    }

    public function getRateLimitUsage(): array
    {
        return ['used' => 0, 'limit' => 0, 'remaining' => 0, 'unlimited' => true];
    }

    /**
     * Run the logical gateway capability and return a Cloudflare-shaped array.
     *
     * @param  string  $model  Ignored; physical model selection is gateway-owned.
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     * @return array{result: array{response: string}}
     */
    public function runModel(string $model, array $messages, array $options = []): array
    {
        if (! $this->isConfigured()) {
            throw new \RuntimeException('MBFD AI gateway is not configured');
        }

        // Interactive browser requests may use a shorter ceiling than the
        // global cold-load timeout. Never forward this transport option.
        $requestTimeout = (int) ($options['request_timeout'] ?? $this->gatewayTimeout);
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
        $requestId = $this->requestId();

        while (true) {
            $attempt++;

            try {
                $response = $this->gatewayRequest($requestId)
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
                Log::error('Local AI request failed', [
                    'status' => $response->status(),
                    'response_bytes' => strlen($response->body()),
                    'attempts' => $attempt,
                    'request_id' => $response->header('X-Request-ID') ?: $requestId,
                ]);
                throw new \RuntimeException("Local AI request failed: {$response->status()}");
            }

            return $response;
        }
    }

    private function gatewayRequest(string $requestId): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withToken($this->credential)
            ->acceptJson()
            ->withHeaders([
                'X-MBFD-Capability' => $this->capability,
                'X-Request-ID' => $requestId,
            ]);
    }

    private function requestId(): string
    {
        return (string) Str::uuid();
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
