<?php

declare(strict_types=1);

namespace Tests\Feature\Deliverability\Concerns;

use App\Models\CloudflareUsageBudget;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/** Opens a fresh, reconciled Email Sending budget and answers the provider with a recorded fake. No real mail is sent. */
trait SendsThroughCloudflareFake
{
    protected CarbonImmutable $now;

    protected function prepareSending(): void
    {
        $this->now = CarbonImmutable::parse('2026-10-30T12:00:00Z');
        $this->travelTo($this->now);
        config([
            'communications.cloudflare.account_id' => str_repeat('a', 32),
            'communications.cloudflare.api_token' => 'deliverability-test-credential',
            'communications.cloudflare.from_address' => 'info@mbfdhub.com',
            'communications.cloudflare.from_name' => 'Miami Beach Fire Department',
        ]);
        CloudflareUsageBudget::query()->create([
            'provider_account_id' => str_repeat('a', 32),
            'cycle_start' => $this->now->startOfMonth(), 'cycle_end' => $this->now->addMonth()->startOfMonth(),
            'provider_chargeable_used' => 0, 'provider_daily_quota' => 10000, 'provider_daily_used' => 0,
            'hub_safe_ceiling' => 2850, 'worker_requests_used' => 0, 'worker_cpu_ms_used' => 0,
            'worker_request_threshold' => 9000000, 'worker_cpu_ms_threshold' => 27000000,
            'reconciled_at' => $this->now, 'provider_daily_reconciled_at' => $this->now,
        ]);
        $this->fakeAcceptance();
    }

    protected function fakeAcceptance(): void
    {
        $this->fakeProvider(fn (Request $request) => Http::response([
            'success' => true,
            'result' => ['message_id' => '<accepted@example.test>', 'delivered' => [], 'queued' => (array) $request['to']],
        ]));
    }

    /** Replaces every earlier fake: the first matching stub wins, so a test cannot just add another. */
    protected function fakeProvider(callable $callback): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($callback);
    }

    /** @return array<string, mixed> The JSON body of the single provider request. */
    protected function onlyRequest(): array
    {
        $recorded = Http::recorded();
        self::assertCount(1, $recorded);

        return $recorded->first()[0]->data();
    }
}
