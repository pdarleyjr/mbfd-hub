<?php

declare(strict_types=1);

namespace Tests\Feature\UnitStatus;

use App\Services\UnitStatus\CommandSourceFeedClient;
use App\Services\UnitStatus\SourceFeedUnavailable;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

final class CommandSourceFeedClientTest extends UnitStatusTestCase
{
    private function client(): CommandSourceFeedClient
    {
        return app(CommandSourceFeedClient::class);
    }

    private function delta(): \App\Services\UnitStatus\SourceFeedPage
    {
        return $this->client()->delta(CarbonImmutable::parse('2026-11-02T12:00:00+00:00'));
    }

    public function test_plain_http_is_allowed_only_for_private_hosts(): void
    {
        self::assertTrue($this->client()->isConfigured());

        foreach (['http://evil.example:8200', 'http://cmd-api.evil.example', 'http://localhost:8200', 'ftp://cmd-api', 'cmd-api:8200', ''] as $url) {
            config(['unit_status.source.url' => $url]);
            self::assertFalse($this->client()->isConfigured(), $url);
        }
        config(['unit_status.source.url' => 'https://cmd.example.org']);
        self::assertTrue($this->client()->isConfigured());
        config(['unit_status.source.url' => 'http://cmd-api:8200', 'unit_status.source.token' => '  ']);
        self::assertFalse($this->client()->isConfigured());
    }

    public function test_a_misconfigured_client_sends_nothing(): void
    {
        Http::fake();
        config(['unit_status.source.url' => 'http://evil.example']);

        try {
            $this->delta();
            self::fail('Expected the client to refuse.');
        } catch (SourceFeedUnavailable $exception) {
            self::assertSame('not_configured', $exception->reason);
            self::assertStringNotContainsString(self::TOKEN, $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_the_request_carries_the_bearer_token_and_an_offset_cursor(): void
    {
        $this->fakeFeed($this->feedPage([]));

        $this->client()->delta(CarbonImmutable::parse('2026-11-02T12:00:00-05:00'), 'src_abc', 50);

        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->method() === 'GET'
                && str_starts_with($request->url(), 'http://cmd-api:8200/api/internal/unit-status/source-calls?')
                && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN)
                && $query['updatedSince'] === '2026-11-02T17:00:00.000000+00:00'
                && $query['afterId'] === 'src_abc'
                && $query['limit'] === '50';
        });
    }

    public function test_a_redirect_is_never_followed(): void
    {
        Http::fake(['*' => Http::response('', 302, ['Location' => 'http://evil.example/steal'])]);

        try {
            $this->delta();
            self::fail('Expected a redirect to be refused.');
        } catch (SourceFeedUnavailable $exception) {
            self::assertSame('redirect', $exception->reason);
        }

        Http::assertSentCount(1);
        Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), 'evil.example'));
    }

    public function test_an_unknown_schema_version_means_unavailable(): void
    {
        $this->fakeFeed($this->feedPage([], ['schemaVersion' => 2]));

        $this->expectException(SourceFeedUnavailable::class);
        $this->expectExceptionMessage('schemaVersion');
        $this->delta();
    }

    public function test_status_codes_map_to_stable_reasons(): void
    {
        foreach ([401 => 'unauthorized', 429 => 'rate_limited', 503 => 'feed_not_configured', 500 => 'server_error', 404 => 'http_404'] as $status => $reason) {
            Http::fake(['*' => Http::response(['error' => 'x', 'secret' => self::TOKEN], $status)]);
            try {
                $this->delta();
                self::fail('Expected failure for '.$status);
            } catch (SourceFeedUnavailable $exception) {
                self::assertSame($reason, $exception->reason);
                self::assertStringNotContainsString(self::TOKEN, $exception->getMessage());
            }
            Http::swap(new \Illuminate\Http\Client\Factory);
        }
    }

    public function test_connection_failures_are_classified_without_the_message(): void
    {
        Http::fake(['*' => static fn () => throw new ConnectionException('cURL error 28: Operation timed out after 8000 ms')]);
        try {
            $this->delta();
            self::fail('Expected a timeout.');
        } catch (SourceFeedUnavailable $exception) {
            self::assertSame('timeout', $exception->reason);
            self::assertStringNotContainsString('8000', $exception->getMessage());
        }

        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['*' => static fn () => throw new ConnectionException('cURL error 6: Could not resolve host: cmd-api')]);
        try {
            $this->delta();
            self::fail('Expected unreachable.');
        } catch (SourceFeedUnavailable $exception) {
            self::assertSame('unreachable', $exception->reason);
        }
    }

    public function test_a_body_that_is_not_a_feed_page_is_unavailable(): void
    {
        foreach ([['schemaVersion' => 1, 'calls' => 'x', 'health' => []], ['schemaVersion' => 1, 'health' => []], ['unrelated' => true]] as $body) {
            Http::swap(new \Illuminate\Http\Client\Factory);
            Http::fake(['*' => Http::response($body, 200)]);
            try {
                $this->delta();
                self::fail('Expected an invalid response.');
            } catch (SourceFeedUnavailable $exception) {
                self::assertContains($exception->reason, ['invalid_response', 'bad_schema']);
            }
        }
    }

    public function test_rows_are_validated_one_by_one_and_unknown_health_fields_are_dropped(): void
    {
        $good = $this->sourceCall(1, [['unitId' => 'E2', 'status' => 'Dispatched']]);
        $this->fakeFeed($this->feedPage([$good, ['id' => 'src_x'], $good], ['health' => $this->liveHealth() + ['details_json' => 'secret', 'token' => 'x']]));

        $page = $this->delta();

        self::assertCount(2, $page->calls);
        self::assertSame(1, $page->skipped);
        self::assertSame('live', $page->healthState());
        self::assertArrayNotHasKey('details_json', $page->health);
        self::assertArrayNotHasKey('token', $page->health);
        self::assertFalse($page->hasMore);
    }

    public function test_lookup_returns_missing_ids_and_refuses_an_empty_list(): void
    {
        $this->fakeFeed($this->feedPage([], ['mode' => 'lookup', 'missing' => [self::callId(9), 42]]));

        $page = $this->client()->lookup([self::callId(9), self::callId(9), self::callId(10)]);

        self::assertSame([self::callId(9)], $page->missing);
        Http::assertSent(static function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $query['ids'] === self::callId(9).','.self::callId(10);
        });

        $this->expectException(SourceFeedUnavailable::class);
        $this->client()->lookup([]);
    }

    public function test_a_page_that_says_more_without_a_cursor_is_invalid(): void
    {
        $this->fakeFeed($this->feedPage([], ['hasMore' => true, 'nextCursor' => null]));

        $this->expectException(SourceFeedUnavailable::class);
        $this->delta();
    }
}
