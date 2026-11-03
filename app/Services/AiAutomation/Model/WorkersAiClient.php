<?php

declare(strict_types=1);

namespace App\Services\AiAutomation\Model;

use App\Models\AiAutomation\AiModelCall;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Optional Cloudflare Workers AI composition. The model only rephrases approved guidance into a short
 * answer: it has no tools, decides nothing and writes nothing. Every failure path returns null so the
 * caller falls back to the fixed acknowledgment.
 *
 * Request: POST https://api.cloudflare.com/client/v4/accounts/{account}/ai/run/{model}, bearer token,
 * chat `messages` input. Prompts and responses are never logged; only an error code and HTTP status are.
 */
final class WorkersAiClient
{
    private const ENDPOINT = 'https://api.cloudflare.com/client/v4/accounts/%s/ai/run/%s';

    private const TEMPERATURE = 0.2;

    private const REASONING_LEVELS = ['none', 'low', 'medium', 'high', 'max'];

    public function __construct(private readonly AiBudgetGuard $guard) {}

    /** Model enabled in config AND a well-formed account id and a token are present. */
    public function available(): bool
    {
        if ((bool) config('ai_automation.hard_disabled', false) || ! (bool) config('ai_automation.model.enabled', false)) {
            return false;
        }

        return $this->accountId() !== null && $this->token() !== null && $this->modelId() !== null;
    }

    public function compose(string $systemPrompt, string $userContent): ?ModelReply
    {
        if (! $this->available()) {
            return null;
        }

        $accountId = (string) $this->accountId();
        $token = (string) $this->token();
        $model = (string) $this->modelId();
        $inputEstimate = AiBudgetGuard::estimateTokens($systemPrompt.$userContent) + 32;

        $call = $this->guard->reserve($model, $inputEstimate);
        if ($call === null) {
            return null;
        }

        $startedAt = hrtime(true);

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->asJson()
                ->timeout($this->timeoutSeconds())
                ->connectTimeout(min(10, $this->timeoutSeconds()))
                ->post(sprintf(self::ENDPOINT, $accountId, $model), $this->payload($systemPrompt, $userContent));
        } catch (ConnectionException $e) {
            return $this->failed($call, 'timeout', $startedAt, null, true, $e);
        } catch (Throwable $e) {
            return $this->failed($call, 'request_error', $startedAt, null, true, $e);
        }

        try {
            return $this->settle($call, $response, $startedAt, $inputEstimate);
        } catch (Throwable $e) {
            return $this->failed($call, 'unexpected', $startedAt, $response->status(), true, $e);
        }
    }

    /** @return array<string, mixed> */
    private function payload(string $systemPrompt, string $userContent): array
    {
        $payload = [
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userContent],
            ],
            'max_tokens' => $this->guard->maxOutputTokens(),
            'temperature' => self::TEMPERATURE,
            'stream' => false,
        ];

        $effort = config('ai_automation.model.reasoning_effort');
        if (is_string($effort) && in_array($effort, self::REASONING_LEVELS, true)) {
            $payload['reasoning_effort'] = $effort;
        }

        return $payload;
    }

    private function settle(AiModelCall $call, Response $response, int $startedAt, int $inputEstimate): ?ModelReply
    {
        $status = $response->status();

        if ($response->failed()) {
            // 429 and other 4xx were refused before processing; 5xx may have consumed capacity.
            $code = $status >= 500 ? 'http_5xx' : ($status === 429 ? 'http_429' : 'http_4xx');

            return $this->failed($call, $code, $startedAt, $status, $status >= 500);
        }

        $json = $response->json();
        if (! is_array($json) || ($json['success'] ?? true) === false) {
            return $this->failed($call, 'malformed', $startedAt, $status, true);
        }

        $result = $json['result'] ?? $json;
        if (! is_array($result)) {
            return $this->failed($call, 'malformed', $startedAt, $status, true);
        }

        if ($this->truncated($result)) {
            return $this->failed($call, 'truncated', $startedAt, $status, true);
        }

        $text = $this->finalText($result);
        if ($text === '') {
            return $this->failed($call, 'empty', $startedAt, $status, true);
        }

        [$in, $out] = $this->usage($result, $inputEstimate, $text);
        $latencyMs = $this->elapsedMs($startedAt);
        $this->guard->complete($call, $in, $out, $latencyMs);

        return new ModelReply($text, (int) $call->getKey(), $in, $out);
    }

    private function failed(AiModelCall $call, string $code, int $startedAt, ?int $status, bool $mayHaveBeenBilled, ?Throwable $e = null): null
    {
        try {
            $this->guard->fail($call, $code, $this->elapsedMs($startedAt), $mayHaveBeenBilled);
        } catch (Throwable $inner) {
            Log::error('ai_automation.model_ledger_write_failed', ['exception' => $inner::class]);
        }

        // Error class and status only. Exception messages can embed the request URL, so they are never logged.
        Log::warning('ai_automation.model_call_failed', array_filter([
            'error' => $code,
            'status' => $status,
            'exception' => $e !== null ? $e::class : null,
        ], static fn ($value): bool => $value !== null));

        return null;
    }

    /** Final answer text only; reasoning/thinking content is never read and any leaked markup is stripped. */
    private function finalText(array $result): string
    {
        $raw = null;

        $choice = $result['choices'][0] ?? null;
        if (is_array($choice)) {
            $raw = $this->contentToString($choice['message']['content'] ?? null);
        }

        if (($raw === null || $raw === '') && is_array($result['output'] ?? null)) {
            $parts = [];
            foreach ($result['output'] as $item) {
                // Responses-style output: skip "reasoning" items, keep only the final "message".
                if (is_array($item) && ($item['type'] ?? null) === 'message') {
                    $parts[] = $this->contentToString($item['content'] ?? null);
                }
            }
            $raw = implode("\n", array_filter($parts, static fn (?string $part): bool => $part !== null && $part !== ''));
        }

        if (($raw === null || $raw === '') && is_string($result['response'] ?? null)) {
            $raw = $result['response'];
        }

        return $this->stripReasoning((string) $raw);
    }

    private function contentToString(mixed $content): ?string
    {
        if (is_string($content)) {
            return $content;
        }
        if (! is_array($content)) {
            return null;
        }

        $parts = [];
        foreach ($content as $part) {
            if (is_array($part) && is_string($part['text'] ?? null)
                && in_array($part['type'] ?? 'text', ['text', 'output_text'], true)) {
                $parts[] = $part['text'];
            }
        }

        return implode('', $parts);
    }

    private function stripReasoning(string $text): string
    {
        // Harmony-format leakage: keep only what follows the final channel marker.
        $segments = preg_split('/<\|channel\|>\s*final\s*<\|message\|>/i', $text);
        if (is_array($segments) && count($segments) > 1) {
            $text = (string) end($segments);
        }
        $text = (string) preg_replace('/<\|[a-z_]+\|>/i', '', $text);

        $text = (string) preg_replace('~<(think|thinking|reasoning)>.*?</\1>~is', '', $text);
        // An opened but never closed block means the answer was cut off inside the reasoning.
        if (preg_match('~<(think|thinking|reasoning)>~i', $text) === 1) {
            return '';
        }

        return trim($text);
    }

    private function truncated(array $result): bool
    {
        $choice = $result['choices'][0] ?? null;
        if (is_array($choice) && ($choice['finish_reason'] ?? null) === 'length') {
            return true;
        }

        return ($result['status'] ?? null) === 'incomplete';
    }

    /** @return array{0: int, 1: int} Provider usage when present, else a chars/4 estimate. */
    private function usage(array $result, int $inputEstimate, string $text): array
    {
        $usage = is_array($result['usage'] ?? null) ? $result['usage'] : [];
        $in = $usage['prompt_tokens'] ?? $usage['input_tokens'] ?? null;
        $out = $usage['completion_tokens'] ?? $usage['output_tokens'] ?? null;

        return [
            is_numeric($in) ? max(0, (int) $in) : $inputEstimate,
            is_numeric($out) ? max(0, (int) $out) : AiBudgetGuard::estimateTokens($text),
        ];
    }

    private function elapsedMs(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }

    private function timeoutSeconds(): int
    {
        return max(1, (int) config('ai_automation.model.timeout_seconds', 30));
    }

    private function accountId(): ?string
    {
        $id = config('ai_automation.model.account_id');

        return is_string($id) && preg_match('/\A[a-f0-9]{32}\z/i', $id) === 1 ? $id : null;
    }

    private function token(): ?string
    {
        $token = config('ai_automation.model.api_token');

        return is_string($token) && trim($token) !== '' ? trim($token) : null;
    }

    private function modelId(): ?string
    {
        $model = config('ai_automation.model.model');

        return is_string($model) && preg_match('~\A@cf/[A-Za-z0-9._-]+/[A-Za-z0-9._-]+\z~', $model) === 1 ? $model : null;
    }
}
