<?php

declare(strict_types=1);

namespace Tests\Feature\Mail\Concerns;

use App\Models\CloudflareUsageBudget;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/** A reconciled Cloudflare budget and a faked Email Sending endpoint, so the real dispatcher and cost guard run. */
trait FakesMailSending
{
    private int $providerCalls = 0;

    /** @var array{0: int, 1: string}|null */
    private ?array $providerRefusal = null;

    protected function prepareSending(): void
    {
        Storage::fake('local');
        config()->set('communications.cloudflare.account_id', str_repeat('a', 32));
        config()->set('communications.cloudflare.api_token', 'mail-center-test-credential');
        config()->set('communications.cloudflare.from_address', 'info@mbfdhub.com');
        config()->set('communications.inbound.address', 'info@mbfdhub.com');
        $this->openBudget();
        Http::preventStrayRequests();
        $this->providerAccepts();
    }

    protected function openBudget(): void
    {
        CloudflareUsageBudget::query()->delete();
        $now = CarbonImmutable::now();
        CloudflareUsageBudget::query()->create([
            'provider_account_id' => str_repeat('a', 32),
            'cycle_start' => $now->startOfMonth(), 'cycle_end' => $now->addMonth()->startOfMonth(),
            'provider_chargeable_used' => 0, 'provider_daily_quota' => 1000, 'provider_daily_used' => 0,
            'hub_safe_ceiling' => 1000, 'worker_request_threshold' => 9_000_000, 'worker_cpu_ms_threshold' => 27_000_000,
            'reconciled_at' => $now, 'provider_daily_reconciled_at' => $now, 'worker_requests_used' => 0, 'worker_cpu_ms_used' => 0,
        ]);
    }

    /** The budget was last reconciled long ago, so the cost guard refuses and nothing reaches the provider. */
    protected function staleBudget(): void
    {
        CloudflareUsageBudget::query()->update(['reconciled_at' => now()->subHour(), 'provider_daily_reconciled_at' => now()->subHour()]);
    }

    private bool $providerBroken = false;

    /** One stub for the whole test (a second Http::fake would never be consulted); the mode flips behaviour. */
    protected function providerAccepts(): void
    {
        $this->providerCalls = 0;
        $this->providerBroken = false;
        Http::fake(function (Request $request) {
            $this->providerCalls++;
            if ($this->providerBroken) {
                return Http::response('upstream broke', 500);
            }
            if ($this->providerRefusal !== null) {
                return Http::response(['success' => false, 'errors' => [['code' => $this->providerRefusal[1], 'message' => 'Private provider context']]], $this->providerRefusal[0]);
            }

            return Http::response(['success' => true, 'result' => [
                'message_id' => '<cf.'.$this->providerCalls.'.'.bin2hex(random_bytes(3)).'@mbfdhub.com>',
                'delivered' => $request['to'], 'queued' => [], 'permanent_bounces' => [],
            ]]);
        });
    }

    protected function providerFailsAmbiguously(): void
    {
        $this->providerCalls = 0;
        $this->providerBroken = true;
    }

    /** The provider answers with a definite refusal: the message was not taken. */
    protected function providerRefuses(int $status, string $code): void
    {
        $this->providerCalls = 0;
        $this->providerRefusal = [$status, $code];
    }

    protected function providerRecovers(): void
    {
        $this->providerCalls = 0;
        $this->providerBroken = false;
        $this->providerRefusal = null;
    }

    /** @return array<string, mixed> the JSON the provider was last sent */
    protected function lastProviderPayload(): array
    {
        $recorded = Http::recorded();
        self::assertNotEmpty($recorded, 'the provider was never called');

        return $recorded->last()[0]->data();
    }
}
