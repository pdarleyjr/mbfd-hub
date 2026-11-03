<?php

declare(strict_types=1);

namespace Tests\Feature\UnitStatus;

use App\Models\UnitStatus\UnitStatusCall;
use App\Models\UnitStatus\UnitStatusCallUnit;
use App\Models\UnitStatus\UnitStatusRun;
use App\Models\UnitStatus\UnitStatusSyncState;
use App\Services\UnitStatus\SyncOutcome;
use App\Services\UnitStatus\UnitStatusSync;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Shared fixtures: a configured, enabled feed, a fixed clock, and builders for Command CallV1 rows and pages. */
abstract class UnitStatusTestCase extends TestCase
{
    use RefreshDatabase;

    /** Monday 10:30 ET: inside the duty day that began at 08:00 on 2026-11-02 (standard time). */
    protected const NOW = '2026-11-02 10:30:00';

    protected const TODAY = '2026-11-02';

    protected const TOKEN = 'unit-status-test-token-0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config([
            'unit_status.enabled' => true,
            'unit_status.source.url' => 'http://cmd-api:8200',
            'unit_status.source.token' => self::TOKEN,
            'unit_status.tracking_starts_at' => '2026-11-02 08:00',
            // The board cache has its own test; everywhere else a changed row must show on the next build.
            'unit_status.board_cache_seconds' => 0,
        ]);
        $this->travelTo(CarbonImmutable::parse(self::NOW, 'America/New_York'));
    }

    protected static function callId(int $number): string
    {
        return 'src_'.str_pad(dechex($number), 32, '0', STR_PAD_LEFT);
    }

    protected static function et(string $moment): CarbonImmutable
    {
        return CarbonImmutable::parse($moment, 'America/New_York')->utc();
    }

    /**
     * A CallV1 row as Command sends it.
     *
     * @param  list<array{unitId: string, status: string}>  $units
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function sourceCall(int $number, array $units, array $overrides = []): array
    {
        $received = self::et('2026-11-02 09:15:00')->toIso8601String();

        return array_merge([
            'id' => self::callId($number),
            'agency' => 'X1012',
            'externalId' => '26011'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
            'provisional' => false,
            'duplicateOf' => null,
            'sourceUrl' => 'https://web.pulsepoint.org/DeepLink.aspx?agencyid=X1012&incidentid='.$number,
            'callType' => 'Medical Emergency',
            'address' => '1230 OCEAN AVE, MIAMI BEACH, FL',
            'lat' => 25.78,
            'lng' => -80.13,
            'locationQuality' => 'approximate',
            'receivedAt' => $received,
            'receivedDisplay' => '9:15 AM',
            'section' => 'active',
            'sourceStatus' => 'Active',
            'closedAt' => null,
            'disappearedAt' => null,
            'firstSeenAt' => $received,
            'lastSeenAt' => self::et('2026-11-02 10:25:00')->toIso8601String(),
            'updatedAt' => self::et('2026-11-02 10:25:00')->toIso8601String(),
            'units' => $units,
            'unitsText' => '',
            'quality' => 'complete',
        ], $overrides);
    }

    /**
     * A feed page body.
     *
     * @param  list<array<string, mixed>>  $calls
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function feedPage(array $calls, array $overrides = []): array
    {
        $last = $calls === [] ? null : $calls[array_key_last($calls)];

        return array_merge([
            'schemaVersion' => 1,
            'agency' => 'X1012',
            'serverNow' => now()->toIso8601String(),
            'mode' => 'delta',
            'health' => $this->liveHealth(),
            'calls' => $calls,
            'nextCursor' => ['updatedSince' => $last['updatedAt'] ?? now()->toIso8601String(), 'afterId' => $last['id'] ?? null],
            'hasMore' => false,
        ], $overrides);
    }

    /** @return array<string, mixed> */
    protected function liveHealth(string $state = 'live'): array
    {
        return ['state' => $state, 'collectorStatus' => 'live', 'lastSourceReadAt' => now()->subSeconds(20)->toIso8601String(), 'activeCount' => 1, 'recentCount' => 5, 'outboxDepth' => 0, 'failureClass' => '', 'delayedAfterSeconds' => 120, 'unavailableAfterSeconds' => 300, 'parserVersion' => 'test'];
    }

    /**
     * Queue the bodies of consecutive feed requests (all HTTP 200). The last one repeats if more requests arrive.
     *
     * @param  array<string, mixed>  ...$bodies
     */
    protected function fakeFeed(array ...$bodies): void
    {
        $sequence = Http::sequence()->whenEmpty(Http::response($bodies[array_key_last($bodies)], 200));
        foreach ($bodies as $body) {
            $sequence->push($body, 200);
        }
        Http::fake(['cmd-api:8200/*' => $sequence]);
    }

    /** @var array<string, mixed> */
    protected array $deltaBody = [];

    protected int $deltaStatus = 200;

    /** @var list<string> */
    protected array $lookupMissing = [];

    /** @var list<array<string, mixed>> */
    protected array $lookupCalls = [];

    /**
     * Answer delta requests with $this->deltaBody and lookup requests (?ids=) from $this->lookupMissing/$this->lookupCalls.
     * Tests change those properties between runs; the stub reads them when a request arrives.
     */
    protected function fakeFeedRouter(): void
    {
        Http::fake(['cmd-api:8200/*' => function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            if (isset($query['ids'])) {
                return Http::response($this->feedPage($this->lookupCalls, ['mode' => 'lookup', 'missing' => $this->lookupMissing]), 200);
            }

            return Http::response($this->deltaBody, $this->deltaStatus);
        }]);
    }

    /** @return list<array<string, mixed>> the query strings of every request sent so far, in order */
    protected function sentQueries(): array
    {
        return Http::recorded()->map(static function (array $pair): array {
            parse_str((string) parse_url($pair[0]->url(), PHP_URL_QUERY), $query);

            return $query;
        })->values()->all();
    }

    protected function fakeFeedStatus(int $status): void
    {
        Http::fake(['cmd-api:8200/*' => Http::response(['error' => 'nope'], $status)]);
    }

    protected function sync(?CarbonImmutable $at = null): SyncOutcome
    {
        return app()->make(UnitStatusSync::class)->run($at);
    }

    /** The Command feed state a board needs to treat the PulsePoint copy as fresh. */
    protected function markFeedFresh(string $state = 'live', ?\DateTimeInterface $at = null): UnitStatusSyncState
    {
        return UnitStatusSyncState::query()->updateOrCreate(['id' => 1], [
            'tracking_starts_at' => self::et('2026-11-02 08:00'),
            'first_synced_at' => self::et('2026-11-02 08:00'),
            'last_success_at' => $at ?? now(),
            'source_state' => $state,
            'consecutive_failures' => 0,
        ]);
    }

    /**
     * Insert a stored call and the units the Hub holds on it, bypassing the sync.
     *
     * @param  array<string, array{status?: string, confirmed?: bool}>  $units  unit code => state
     * @param  array<string, mixed>  $attributes
     */
    protected function storeCall(int $number, array $units, array $attributes = []): UnitStatusCall
    {
        $call = new UnitStatusCall(array_merge([
            'external_id' => '26011'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
            'call_type' => 'Medical Emergency',
            'address' => '1230 OCEAN AVE, MIAMI BEACH, FL',
            'location_quality' => 'none',
            'received_at' => self::et('2026-11-02 09:15:00'),
            'section' => 'active',
            'source_status' => 'Active',
            'first_seen_at' => self::et('2026-11-02 09:16:00'),
            'last_seen_at' => self::et('2026-11-02 10:25:00'),
            'source_updated_at' => self::et('2026-11-02 10:25:00'),
            'source_url' => 'https://web.pulsepoint.org/DeepLink.aspx?incidentid='.$number,
            'synced_at' => now(),
        ], $attributes));
        $call->source_call_id = self::callId($number);
        $call->save();
        foreach ($units as $code => $unit) {
            UnitStatusCallUnit::query()->create([
                'source_call_id' => $call->source_call_id,
                'unit_code' => $code,
                'designation' => $code,
                'status' => $unit['status'] ?? 'Dispatched',
                'confirmed' => $unit['confirmed'] ?? true,
                'first_seen_on_call_at' => now(),
                'status_observed_at' => now(),
            ]);
        }

        return $call;
    }

    /** @return array<string, int> unit code => number of non-voided, confirmed runs */
    protected function ledgerCounts(): array
    {
        return UnitStatusRun::query()->whereNull('voided_at')->where('confirmed', true)
            ->selectRaw('unit_code, count(*) as n')->groupBy('unit_code')->orderBy('unit_code')->pluck('n', 'unit_code')
            ->map(static fn (mixed $n): int => (int) $n)->all();
    }

    protected function feedRequestCount(): int
    {
        return Http::recorded(static fn (Request $request): bool => true)->count();
    }
}
