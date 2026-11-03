<?php

declare(strict_types=1);

namespace App\Services\UnitStatus;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Hub -> Command private read feed (docs: mbfd-command UNIT_STATUS_SOURCE_FEED_CONTRACT.md). Server side only.
 * Plain http is allowed only for configured private hosts; redirects are never followed, so the bearer token only
 * ever goes to the configured origin. Request and response bodies are never logged.
 */
final class CommandSourceFeedClient
{
    private const SUPPORTED_SCHEMA = 1;

    private const PATH = '/api/internal/unit-status/source-calls';

    private const HEALTH_FIELDS = ['state', 'collectorStatus', 'lastHeartbeatAt', 'lastSourceReadAt', 'lastParseAt', 'lastForwardedAt', 'activeCount', 'recentCount', 'outboxDepth', 'failureClass', 'lastFailureAt', 'delayedAfterSeconds', 'unavailableAfterSeconds', 'parserVersion'];

    public function isConfigured(): bool
    {
        return $this->configurationProblem() === null;
    }

    /** Why the feed cannot be called, or null when it can. Never contains the token. */
    public function configurationProblem(): ?string
    {
        $token = config('unit_status.source.token');
        if (! is_string($token) || trim($token) === '') {
            return 'token missing';
        }
        $url = trim((string) config('unit_status.source.url'));
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '' || ! in_array($scheme, ['http', 'https'], true)) {
            return 'url invalid';
        }
        $private = array_map('strtolower', array_filter((array) config('unit_status.source.private_hosts', []), 'is_string'));

        return $scheme === 'https' || in_array($host, $private, true) ? null : 'plain http is allowed for private hosts only';
    }

    /** Calls updated after the cursor, oldest first. */
    public function delta(CarbonImmutable $updatedSince, ?string $afterId = null, ?int $limit = null): SourceFeedPage
    {
        $query = [
            'updatedSince' => $updatedSince->utc()->format('Y-m-d\TH:i:s.uP'),
            'limit' => max(1, min(500, $limit ?? (int) config('unit_status.page_limit', 200))),
        ];
        if ($afterId !== null && $afterId !== '') {
            $query['afterId'] = $afterId;
        }

        return $this->decode($this->get($query), true);
    }

    /**
     * Named calls; ids Command no longer has come back in `missing`.
     *
     * @param  list<string>  $ids  1 to 100
     */
    public function lookup(array $ids): SourceFeedPage
    {
        $ids = array_slice(array_values(array_unique($ids)), 0, 100);
        if ($ids === []) {
            throw SourceFeedUnavailable::invalid();
        }

        return $this->decode($this->get(['ids' => implode(',', $ids)]), false);
    }

    /** @param  array<string, scalar>  $query */
    private function get(array $query): Response
    {
        $problem = $this->configurationProblem();
        if ($problem !== null) {
            throw SourceFeedUnavailable::notConfigured($problem);
        }

        try {
            return $this->request()->get(rtrim(trim((string) config('unit_status.source.url')), '/').self::PATH, $query);
        } catch (ConnectionException $exception) {
            $message = strtolower($exception->getMessage());

            throw SourceFeedUnavailable::unreachable(str_contains($message, 'curl error 28') || str_contains($message, 'timed out'));
        }
    }

    private function request(): PendingRequest
    {
        return Http::timeout((int) config('unit_status.source.timeout', 8))
            ->connectTimeout((int) config('unit_status.source.connect_timeout', 3))
            ->withoutRedirecting()
            ->withToken((string) config('unit_status.source.token'))
            ->acceptJson();
    }

    private function decode(Response $response, bool $expectsCursor): SourceFeedPage
    {
        if (! $response->successful()) {
            throw SourceFeedUnavailable::status($response->status());
        }
        $body = $response->json();
        if (! is_array($body)) {
            throw SourceFeedUnavailable::invalid();
        }
        if (($body['schemaVersion'] ?? null) !== self::SUPPORTED_SCHEMA) {
            throw SourceFeedUnavailable::schema();
        }
        if (! is_array($body['calls'] ?? null) || ! is_array($body['health'] ?? null)) {
            throw SourceFeedUnavailable::invalid();
        }

        [$calls, $skipped] = $this->calls($body['calls']);
        $cursor = is_array($body['nextCursor'] ?? null) ? $body['nextCursor'] : [];
        $cursorAt = $this->instant($cursor['updatedSince'] ?? null);
        $hasMore = ($body['hasMore'] ?? false) === true;
        if ($expectsCursor && $hasMore && $cursorAt === null) {
            throw SourceFeedUnavailable::invalid();
        }

        return new SourceFeedPage(
            $calls,
            $skipped,
            array_intersect_key($body['health'], array_flip(self::HEALTH_FIELDS)),
            $cursorAt,
            is_string($cursor['afterId'] ?? null) ? $cursor['afterId'] : null,
            $hasMore,
            array_values(array_filter((array) ($body['missing'] ?? []), 'is_string')),
        );
    }

    /**
     * @param  array<mixed>  $rows
     * @return array{0: list<SourceCall>, 1: int}
     */
    private function calls(array $rows): array
    {
        $calls = [];
        $skipped = 0;
        foreach ($rows as $row) {
            $call = is_array($row) ? SourceCall::fromArray($row) : null;
            if ($call === null) {
                $skipped++;

                continue;
            }
            $calls[] = $call;
        }

        return [$calls, $skipped];
    }

    private function instant(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value)) {
            return null;
        }
        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}
