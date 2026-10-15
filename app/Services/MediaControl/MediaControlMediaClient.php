<?php

declare(strict_types=1);

namespace App\Services\MediaControl;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Hub -> Media Control ingest API (/api/hub-ingest/v1). Every call carries the
 * dedicated ingest bearer token and the acting Hub user in X-MBFD-Hub-Actor.
 * Media bytes never pass through the Hub: the browser uploads them directly to
 * the upload_url returned by createGrant().
 */
final class MediaControlMediaClient
{
    private const API_PATH = '/api/hub-ingest/v1';

    public function isConfigured(): bool
    {
        return $this->baseUrl() !== null && $this->token() !== null;
    }

    /** Origin (scheme://host[:port]) of the configured Media Control instance. */
    public function origin(): ?string
    {
        $base = $this->baseUrl();
        if ($base === null) {
            return null;
        }
        $scheme = parse_url($base, PHP_URL_SCHEME);
        $host = parse_url($base, PHP_URL_HOST);
        $port = parse_url($base, PHP_URL_PORT);

        return is_string($scheme) && is_string($host) && $host !== ''
            ? strtolower($scheme).'://'.strtolower($host).(is_int($port) ? ':'.$port : '')
            : null;
    }

    /** @return array<string, mixed> */
    public function health(User $actor): array
    {
        return $this->send($actor, 'get', '/health');
    }

    /**
     * @param  array{filename: string, size: int, mime: string, title?: string|null, display_span?: int|null, category?: string|null, tags?: list<string>}  $file
     * @return array<string, mixed>
     */
    public function createGrant(User $actor, array $file): array
    {
        return $this->send($actor, 'post', '/grants', $file);
    }

    /** @return array<string, mixed> */
    public function grantStatus(User $actor, string $grantId): array
    {
        return $this->send($actor, 'get', '/grants/'.rawurlencode($grantId));
    }

    /**
     * @param  array{kind?: string|null, q?: string|null, limit?: int, cursor?: string|null}  $filters
     * @return array<string, mixed>
     */
    public function list(User $actor, array $filters = []): array
    {
        $query = array_filter($filters, static fn (mixed $value): bool => $value !== null && $value !== '');

        return $this->send($actor, 'get', '/content', $query);
    }

    /**
     * @param  array{title?: string, display_span?: int|null, category?: string|null, tags?: list<string>}  $changes
     * @return array<string, mixed>
     */
    public function update(User $actor, string $contentId, array $changes): array
    {
        return $this->send($actor, 'patch', '/content/'.rawurlencode($contentId), $changes);
    }

    /** @return array<string, mixed> */
    public function archive(User $actor, string $contentId): array
    {
        return $this->send($actor, 'post', '/content/'.rawurlencode($contentId).'/archive');
    }

    /** @return array<string, mixed> */
    public function restore(User $actor, string $contentId): array
    {
        return $this->send($actor, 'post', '/content/'.rawurlencode($contentId).'/restore');
    }

    public static function actorHeader(User $actor): string
    {
        $json = json_encode([
            'hub_user_id' => (int) $actor->getKey(),
            'name' => (string) $actor->name,
            'email' => (string) $actor->email,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function send(User $actor, string $method, string $path, array $payload = []): array
    {
        $base = $this->baseUrl();
        $token = $this->token();
        if ($base === null || $token === null) {
            throw MediaControlRequestFailed::notConfigured();
        }

        $context = ['method' => strtoupper($method), 'path' => $path, 'hub_user_id' => $actor->getKey()];
        try {
            $response = $this->request($token, $actor)->{$method}($base.self::API_PATH.$path, $payload);
        } catch (ConnectionException $exception) {
            Log::warning('media_control.ingest.unreachable', $context + ['error' => $exception->getMessage()]);

            throw MediaControlRequestFailed::unavailable();
        }

        return $this->decode($response, $context);
    }

    private function request(string $token, User $actor): PendingRequest
    {
        return Http::timeout(max(1, (int) config('services.media_control.ingest_timeout', 10)))
            ->connectTimeout(5)
            ->withToken($token)
            ->withHeaders(['X-MBFD-Hub-Actor' => self::actorHeader($actor)])
            ->acceptJson()
            ->asJson();
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function decode(Response $response, array $context): array
    {
        $body = $response->json();
        $body = is_array($body) ? $body : [];

        if ($response->successful()) {
            return $body;
        }

        $status = $response->status();
        $code = is_string($body['code'] ?? null) ? $body['code'] : null;
        Log::warning('media_control.ingest.rejected', $context + ['status' => $status, 'code' => $code]);

        if ($status >= 500 || $status === 429) {
            if ($status === 503 && $code === 'ingest_not_configured') {
                throw new MediaControlRequestFailed(
                    'Media Control has not been set up to accept Hub uploads yet (HUB_INGEST_TOKEN is missing there).',
                    MediaControlRequestFailed::NOT_CONFIGURED,
                    $status,
                    $code,
                );
            }

            throw MediaControlRequestFailed::unavailable($status);
        }

        if ($status === 401) {
            throw new MediaControlRequestFailed(
                'Media Control did not accept the Hub ingest credential. Check that MEDIA_CONTROL_INGEST_TOKEN matches HUB_INGEST_TOKEN.',
                MediaControlRequestFailed::REJECTED,
                $status,
                $code,
            );
        }

        $message = is_string($body['error'] ?? null) && trim($body['error']) !== ''
            ? mb_substr(trim($body['error']), 0, 300)
            : 'Media Control refused the request (status '.$status.').';

        throw new MediaControlRequestFailed($message, MediaControlRequestFailed::REJECTED, $status, $code);
    }

    private function baseUrl(): ?string
    {
        $base = trim((string) config('services.media_control.base_url', ''));
        if ($base === '' || ! in_array(parse_url($base, PHP_URL_SCHEME), ['https', 'http'], true)) {
            return null;
        }

        return rtrim($base, '/');
    }

    private function token(): ?string
    {
        $token = trim((string) config('services.media_control.ingest_token', ''));

        return $token === '' ? null : $token;
    }
}
