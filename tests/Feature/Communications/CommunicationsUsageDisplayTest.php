<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Filament\Pages\CommunicationsUsage;
use App\Models\CloudflareUsageBudget;
use App\Models\OutboundEmail;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CommunicationsUsageDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_usage_display_resets_with_the_provider_cycle_but_preserves_uncertain_sends(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-04T12:00:00Z'));
        config(['communications.cloudflare.account_id' => str_repeat('a', 32)]);
        $budget = CloudflareUsageBudget::create([
            'provider_account_id' => str_repeat('a', 32),
            'cycle_start' => '2026-10-04', 'cycle_end' => '2026-11-04',
            'hub_safe_ceiling' => 2850,
        ]);
        foreach ([
            ['budget_reserved_at' => '2026-10-03', 'accepted_at' => '2026-10-03'],
            ['budget_reserved_at' => '2026-10-03', 'accepted_at' => null],
            ['budget_reserved_at' => '2026-10-04', 'accepted_at' => '2026-10-04'],
        ] as $timing) {
            OutboundEmail::create($timing + [
                'provider' => 'cloudflare', 'source_type' => 'test', 'from_address' => 'info@mbfdhub.com',
                'to_recipients' => ['fixture@example.test'], 'subject' => 'Local fixture',
                'recipient_count' => 1, 'chargeable_budget_units' => 1, 'status' => 'reserved',
            ]);
        }
        $page = new CommunicationsUsage;
        self::assertSame($budget->id, $page->getBudget()?->id);
        self::assertSame(2, $page->getReservedUnits());
    }

    public function test_usage_display_does_not_present_expired_or_other_account_budget_as_current(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-04T12:00:00Z'));
        config(['communications.cloudflare.account_id' => str_repeat('a', 32)]);
        CloudflareUsageBudget::create([
            'provider_account_id' => str_repeat('a', 32),
            'cycle_start' => '2026-09-04', 'cycle_end' => '2026-10-04', 'hub_safe_ceiling' => 2850,
        ]);
        CloudflareUsageBudget::create([
            'provider_account_id' => str_repeat('b', 32),
            'cycle_start' => '2026-10-04', 'cycle_end' => '2026-11-04', 'hub_safe_ceiling' => 2850,
        ]);
        self::assertNull((new CommunicationsUsage)->getBudget());
    }
}
