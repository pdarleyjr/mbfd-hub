<?php

declare(strict_types=1);

namespace Tests\Feature\Deliverability;

use App\Models\CloudflareUsageBudget;
use App\Models\OutboundEmail;
use App\Models\OutboundEmailReconciliationCheckpoint;
use App\Models\User;
use App\Services\Mail\MailHealthReport;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Deliverability\Concerns\SendsThroughCloudflareFake;
use Tests\TestCase;

final class MailHealthReportTest extends TestCase
{
    use RefreshDatabase;
    use SendsThroughCloudflareFake;

    /** @var list<string> */
    private array $lookups = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareSending();
        Cache::flush();
        config(['communications.delivery.enabled' => true, 'communications.delivery.zone_id' => str_repeat('b', 32)]);
        $this->resolveWith(fn (string $name): array => match ($name) {
            'mbfdhub.com' => ['v=spf1 include:_spf.mx.cloudflare.net ~all'],
            '_dmarc.mbfdhub.com' => ['v=DMARC1; p=reject;'],
            'cf-bounce._domainkey.mbfdhub.com' => ['v=DKIM1; k=rsa; p=examplekeyvalue'],
            default => [],
        });
    }

    public function test_only_people_who_may_view_communications_can_read_it(): void
    {
        $this->expectException(AuthorizationException::class);

        try {
            $this->report()->summary(User::factory()->create(['account_status' => 'active']));
        } finally {
            self::assertSame([], $this->lookups, 'nothing is read for someone who may not see it');
        }
    }

    public function test_an_authorised_viewer_gets_a_summary_without_any_provider_call(): void
    {
        $summary = $this->report()->summary($this->viewer());

        foreach (['sending', 'reconciliation', 'outcomes_30d', 'authentication', 'notes', 'outcome_labels'] as $key) {
            self::assertArrayHasKey($key, $summary);
        }
        Http::assertNothingSent();
    }

    public function test_sending_state_reports_an_open_budget_and_its_ceilings(): void
    {
        $sending = $this->report()->summary($this->viewer())['sending'];

        self::assertSame('open', $sending['state']);
        self::assertSame(2850, $sending['ceiling']);
        self::assertSame(0, $sending['used']);
        self::assertSame(2850, $sending['remaining']);
        self::assertSame(5, $sending['recipient_limit']);
        self::assertNull($sending['backoff_until']);
    }

    public function test_sending_state_counts_local_reservations_that_provider_usage_has_not_caught_up_with(): void
    {
        OutboundEmail::query()->forceCreate([
            'provider' => 'cloudflare', 'source_type' => 'test', 'from_address' => 'info@mbfdhub.com', 'to_recipients' => ['a@example.test'],
            'subject' => 'S', 'recipient_count' => 1, 'chargeable_budget_units' => 3, 'status' => 'accepted',
            'budget_reserved_at' => $this->now, 'accepted_at' => $this->now,
        ]);

        $sending = $this->report()->summary($this->viewer())['sending'];

        self::assertSame(3, $sending['used']);
        self::assertSame(2847, $sending['remaining']);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function closedStates(): array
    {
        return [
            'stale reconciliation' => [['reconciled_at' => '2026-10-30T10:00:00Z'], 'not_reconciled'],
            'never reconciled' => [['reconciled_at' => null, 'provider_daily_reconciled_at' => null], 'not_reconciled'],
            'provider backoff' => [['provider_backoff_until' => '2026-10-30T12:30:00Z'], 'paused'],
            'ceiling reached' => [['provider_chargeable_used' => 2850], 'ceiling_reached'],
            'daily quota reached' => [['provider_daily_used' => 10000], 'daily_quota_reached'],
        ];
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('closedStates')]
    public function test_every_way_sending_can_close_is_named(array $changes, string $state): void
    {
        CloudflareUsageBudget::query()->update($changes);

        $sending = $this->report()->summary($this->viewer())['sending'];

        self::assertSame($state, $sending['state']);
        self::assertNotSame('', $sending['summary']);
    }

    public function test_no_budget_at_all_is_not_reconciled_rather_than_an_error(): void
    {
        CloudflareUsageBudget::query()->delete();

        self::assertSame('not_reconciled', $this->report()->summary($this->viewer())['sending']['state']);
    }

    public function test_reconciliation_shows_the_last_completed_attempt_and_error_from_the_checkpoint(): void
    {
        OutboundEmailReconciliationCheckpoint::query()->create([
            'zone_id' => str_repeat('b', 32), 'completed_through' => $this->now->subMinutes(7),
            'attempted_at' => $this->now->subMinutes(2), 'last_error' => 'Delivery analytics reconciliation failed; checkpoint preserved.',
        ]);

        $reconciliation = $this->report()->summary($this->viewer())['reconciliation'];

        self::assertTrue($reconciliation['enabled']);
        self::assertSame($this->now->subMinutes(7)->utc()->toIso8601String(), $reconciliation['last_completed_at']);
        self::assertSame($this->now->subMinutes(2)->utc()->toIso8601String(), $reconciliation['last_attempted_at']);
        self::assertSame('Delivery analytics reconciliation failed; checkpoint preserved.', $reconciliation['last_error']);
    }

    public function test_reconciliation_that_never_ran_or_is_switched_off_is_said_plainly(): void
    {
        config(['communications.delivery.enabled' => false]);

        $summary = $this->report()->summary($this->viewer());

        self::assertFalse($summary['reconciliation']['enabled']);
        self::assertNull($summary['reconciliation']['last_completed_at']);
        self::assertNull($summary['reconciliation']['last_error']);
        self::assertStringContainsString('reconciliation is off', strtolower(implode(' ', $summary['notes'])));
    }

    public function test_thirty_day_counts_use_the_recipient_ledger_and_keep_suppression_apart_from_rejection(): void
    {
        $email = $this->email();
        $rows = [
            ['delivered', null, null], ['delivered', null, null], ['deferred', null, null], ['bounced', null, null],
            ['failed', null, null], ['rejected', 'INVALID_REQUEST_SCHEMA', 'send_response.rejected'],
            ['rejected', 'E_RECIPIENT_SUPPRESSED', 'send_response.rejected'],
            ['rejected', null, 'send_response.suppressed_recipients'],
            ['complained', null, null], ['accepted', null, null], ['queued', null, null], ['unknown', null, null],
        ];
        foreach ($rows as $index => [$status, $code, $type]) {
            $email->recipients()->create([
                'address' => "r{$index}@example.test", 'recipient_class' => 'to', 'status' => $status,
                'failure_code' => $code, 'last_event_type' => $type,
            ]);
        }
        $old = $this->email();
        $old->recipients()->create(['address' => 'old@example.test', 'recipient_class' => 'to', 'status' => 'bounced', 'created_at' => $this->now->subDays(31)]);

        $counts = $this->report()->summary($this->viewer())['outcomes_30d'];

        self::assertSame([
            'accepted_by_receiving_server' => 2, 'deferred' => 1, 'bounced' => 1, 'failed' => 1,
            'rejected' => 1, 'suppressed' => 2, 'complained' => 1, 'awaiting_confirmation' => 3,
        ], $counts);
    }

    public function test_an_empty_ledger_gives_zeroes_not_errors(): void
    {
        $summary = $this->report()->summary($this->viewer());
        $counts = $summary['outcomes_30d'];

        // Complaints are not a zero: the analytics dataset has no complaint data, so the report says so.
        self::assertNull($counts['complained']);
        self::assertSame('Not reported by Cloudflare analytics', $summary['complaints_unavailable_label']);
        self::assertSame(array_fill_keys(array_keys(array_diff_key($counts, ['complained' => 1])), 0), array_diff_key($counts, ['complained' => 1]));
        self::assertArrayHasKey('suppressed', $counts);
    }

    public function test_the_email_center_read_levels_decide_who_may_view_the_report(): void
    {
        $emailViewer = $this->viewerWith('admin.email.view');
        self::assertArrayHasKey('sending', $this->report()->summary($emailViewer));

        foreach ([$this->viewerWith('admin.email.send'), $this->viewerWith('admin.email.manage'), $this->viewerWith(null)] as $refused) {
            try {
                $this->report()->summary($refused);
                self::fail('send or manage alone, or no permission, must not open the health report');
            } catch (AuthorizationException) {
                self::assertTrue(true);
            }
        }
        $noPanel = User::factory()->create(['account_status' => 'active']);
        $noPanel->givePermissionTo(Permission::findOrCreate('admin.communications.view', 'web'));
        $this->expectException(AuthorizationException::class);
        $this->report()->summary($noPanel->fresh());
    }

    public function test_wording_never_claims_inbox_placement(): void
    {
        $summary = $this->report()->summary($this->viewer());

        $text = strtolower(json_encode($summary, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('inbox', $text);
        self::assertStringNotContainsString('delivered to', $text);
        self::assertSame('Accepted by receiving server', $summary['outcome_labels']['accepted_by_receiving_server']);
        self::assertStringContainsString('folder', strtolower(implode(' ', $summary['notes'])), 'the report says what it cannot show');
    }

    public function test_dns_records_are_reported_as_present_for_the_sending_domain(): void
    {
        $auth = $this->report()->summary($this->viewer())['authentication'];

        self::assertSame('mbfdhub.com', $auth['domain']);
        self::assertSame(['spf' => 'present', 'dmarc' => 'present', 'dkim' => 'present'], array_intersect_key($auth, array_flip(['spf', 'dmarc', 'dkim'])));
        self::assertNotNull($auth['checked_at']);
    }

    public function test_missing_records_are_reported_as_missing(): void
    {
        $this->resolveWith(fn (string $name): array => $name === 'mbfdhub.com' ? ['google-site-verification=abc'] : []);

        $auth = $this->report()->summary($this->viewer())['authentication'];

        self::assertSame(['spf' => 'missing', 'dmarc' => 'missing', 'dkim' => 'missing'], array_intersect_key($auth, array_flip(['spf', 'dmarc', 'dkim'])));
    }

    public function test_a_failed_lookup_is_not_checked_rather_than_an_error_or_a_false_missing(): void
    {
        $this->resolveWith(fn (string $name): ?array => null);

        $auth = $this->report()->summary($this->viewer())['authentication'];

        self::assertSame(['spf' => 'not checked', 'dmarc' => 'not checked', 'dkim' => 'not checked'], array_intersect_key($auth, array_flip(['spf', 'dmarc', 'dkim'])));
    }

    public function test_a_lookup_that_throws_fails_soft(): void
    {
        $this->resolveWith(function (string $name): array {
            throw new \RuntimeException('resolver exploded');
        });

        $auth = $this->report()->summary($this->viewer())['authentication'];

        self::assertSame('not checked', $auth['spf']);
    }

    public function test_dns_answers_are_cached_for_a_day(): void
    {
        $viewer = $this->viewer();
        $this->report()->summary($viewer);
        $first = count($this->lookups);
        $this->report()->summary($viewer);
        self::assertSame($first, count($this->lookups), 'a second view inside a day makes no lookups');
        self::assertGreaterThan(0, $first);

        $this->travel(25)->hours();
        $this->report()->summary($viewer);
        self::assertSame($first * 2, count($this->lookups), 'the answer is refreshed after 24 hours');
    }

    public function test_a_failed_lookup_is_not_remembered_for_a_day(): void
    {
        $viewer = $this->viewer();
        $this->resolveWith(fn (string $name): ?array => null);
        $this->report()->summary($viewer);

        $this->resolveWith(fn (string $name): array => $name === 'mbfdhub.com' ? ['v=spf1 -all'] : []);
        $this->travel(11)->minutes();

        self::assertSame('present', $this->report()->summary($viewer)['authentication']['spf']);
    }

    private function resolveWith(Closure $resolver): void
    {
        $this->app->bind(MailHealthReport::class, fn (): MailHealthReport => new MailHealthReport(function (string $name) use ($resolver): ?array {
            $this->lookups[] = $name;

            return $resolver($name);
        }));
    }

    private function report(): MailHealthReport
    {
        return app(MailHealthReport::class);
    }

    /** Panel entitlement plus the legacy read permission, as every Communications reader had before Email permissions existed. */
    private function viewer(): User
    {
        return $this->viewerWith('admin.communications.view');
    }

    private function viewerWith(?string $permission): User
    {
        $user = User::factory()->create(['account_status' => 'active', 'must_change_password' => false]);
        $user->givePermissionTo(Permission::findOrCreate('admin.access', 'web'));
        if ($permission !== null) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        return $user->fresh();
    }

    private function email(): OutboundEmail
    {
        return OutboundEmail::query()->forceCreate([
            'provider' => 'cloudflare', 'source_type' => 'admin_compose', 'from_address' => 'info@mbfdhub.com',
            'to_recipients' => ['a@example.test'], 'subject' => 'S', 'recipient_count' => 1, 'chargeable_budget_units' => 1, 'status' => 'queued',
        ]);
    }
}
