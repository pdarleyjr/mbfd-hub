<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\CloudflareUsageBudget;
use App\Models\OutboundEmail;
use App\Models\OutboundEmailDeliveryEvent;
use App\Models\OutboundEmailReconciliationCheckpoint;
use App\Services\Communications\CloudflareDeliveryReconciler;
use App\Services\Communications\CloudflareEmailDispatcher;
use App\Services\Communications\OutboundDeliveryLedger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

final class OutboundDeliveryLedgerTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = CarbonImmutable::parse('2026-09-26T12:00:00Z');
        $this->travelTo($this->now);
        config([
            'communications.cloudflare.account_id' => str_repeat('a', 32),
            'communications.cloudflare.api_token' => 'isolated-sending-token',
            'communications.delivery.zone_id' => str_repeat('b', 32),
            'communications.delivery.analytics_token' => 'isolated-read-token',
        ]);
        Http::preventStrayRequests();
    }

    public function test_initial_response_persists_recipient_classes_and_mixed_outcomes_without_exposing_bcc(): void
    {
        $email = $this->email(['cc_recipients' => [' CC@Example.test '], 'bcc_recipients' => ['hidden@example.test'], 'recipient_count' => 3]);
        $email = app(OutboundDeliveryLedger::class)->initialResponse($email, [
            'delivered' => ['member@example.test'], 'queued' => ['cc@example.test'], 'suppressed_recipients' => ['hidden@example.test'],
        ], $this->now);
        self::assertSame('partially_delivered', $email->status);
        self::assertEquals(['delivered' => 1, 'queued' => 1, 'rejected' => 1], $email->deliveryCounts());
        self::assertSame('cc', $email->recipients()->where('address', 'cc@example.test')->sole()->recipient_class);
        self::assertSame('member@example.test', $email->recipient_summary);
        self::assertStringNotContainsString('hidden@example.test', $email->toJson());
        self::assertNull($email->delivered_at);
    }

    public function test_queued_deferred_delivered_is_monotonic_and_duplicate_events_are_idempotent(): void
    {
        $email = $this->email();
        $ledger = app(OutboundDeliveryLedger::class);
        $ledger->initialResponse($email, ['queued' => ['member@example.test']], $this->now);
        $ledger->record($email, 'member@example.test', 'deferred', 'deferred', $this->now->addMinute(), 'greylist', 'Try later');
        self::assertSame('deferred', $email->fresh()->status);
        $ledger->record($email, 'member@example.test', 'delivered', 'delivered', $this->now->addMinutes(2));
        self::assertFalse($ledger->record($email, 'member@example.test', 'delivered', 'delivered', $this->now->addMinutes(2)));
        // Older events and later nonterminal telemetry cannot undo server acceptance.
        $ledger->record($email, 'member@example.test', 'queued', 'queued', $this->now->addSeconds(30));
        $ledger->record($email, 'member@example.test', 'deferred', 'deferred', $this->now->addMinutes(3));
        self::assertSame('delivered', $email->fresh()->status);
        self::assertSame(5, OutboundEmailDeliveryEvent::query()->count());
        self::assertNull($email->recipients()->sole()->failure_detail);
    }

    public function test_delayed_and_replayed_initial_response_cannot_reset_confirmed_delivery_or_complaint(): void
    {
        $email = $this->email(['cc_recipients' => ['complaint@example.test'], 'recipient_count' => 2]);
        $ledger = app(OutboundDeliveryLedger::class);
        $ledger->record($email, 'member@example.test', 'delivered', 'newEmailSending', $this->now->subSecond());
        $ledger->record($email, 'complaint@example.test', 'complained', 'complained', $this->now->addMinute(), 'complaint', 'Recipient complaint');
        $response = ['queued' => ['member@example.test'], 'delivered' => ['complaint@example.test']];
        $ledger->initialResponse($email, $response, $this->now->addMinutes(2));
        $ledger->initialResponse($email, $response, $this->now->addMinutes(3));
        self::assertSame('complained', $email->fresh()->status);
        $delivered = $email->recipients()->where('address', 'member@example.test')->sole();
        $complained = $email->recipients()->where('address', 'complaint@example.test')->sole();
        self::assertSame('delivered', $delivered->status);
        self::assertTrue($delivered->is_terminal);
        self::assertTrue($delivered->last_event_at->equalTo($this->now->subSecond()));
        self::assertSame('complained', $complained->status);
        self::assertTrue($complained->is_terminal);
        self::assertSame('Recipient complaint', $complained->failure_detail);
        self::assertSame(2, $email->deliveryEvents()->count());
    }

    public function test_bounce_and_post_delivery_complaint_are_preserved(): void
    {
        $email = $this->email();
        $ledger = app(OutboundDeliveryLedger::class);
        $ledger->initialResponse($email, ['queued' => ['member@example.test']], $this->now);
        $ledger->record($email, 'member@example.test', 'bounced', 'bounced', $this->now->addMinute(), '550', 'Mailbox unavailable https://host.test/private-token');
        self::assertSame('bounced', $email->fresh()->status);
        self::assertSame('Mailbox unavailable [redacted]', $email->recipients()->sole()->failure_detail);
        $ledger->record($email, 'member@example.test', 'delivered', 'delivered', $this->now->addMinutes(2));
        $ledger->record($email, 'member@example.test', 'complained', 'complained', $this->now->addMinutes(3));
        self::assertSame('complained', $email->fresh()->status);
    }

    public function test_mixed_terminal_failures_are_failed_not_pending_or_accepted(): void
    {
        $email = $this->email(['cc_recipients' => ['suppressed@example.test'], 'recipient_count' => 2]);
        app(OutboundDeliveryLedger::class)->initialResponse($email, [
            'permanent_bounces' => ['member@example.test'], 'suppressed_recipients' => ['suppressed@example.test'],
        ], $this->now);
        self::assertSame('failed', $email->fresh()->status);
        self::assertSame('danger', $email->fresh()->deliveryStatusColor());
        self::assertTrue($email->recipients()->get()->every(fn ($recipient): bool => $recipient->is_terminal));
    }

    public function test_equal_timestamp_outcomes_have_deterministic_priority(): void
    {
        $ledger = app(OutboundDeliveryLedger::class);
        foreach ([['queued', 'delivered'], ['delivered', 'queued']] as $order) {
            $email = $this->email(['provider_message_id' => implode('-', $order)]);
            foreach ($order as $status) {
                $ledger->record($email, 'member@example.test', $status, $status, $this->now);
            }
            self::assertSame('delivered', $email->fresh()->status);
        }
    }

    public function test_provider_terminal_event_before_api_receipt_time_overrides_pending_receipt(): void
    {
        $email = $this->email();
        $ledger = app(OutboundDeliveryLedger::class);
        $ledger->initialResponse($email, ['queued' => ['member@example.test']], $this->now);
        $ledger->record($email, 'member@example.test', 'delivered', 'newEmailSending', $this->now->subSecond());
        self::assertSame('delivered', $email->fresh()->status);
        self::assertTrue($email->recipients()->sole()->delivered_at->equalTo($this->now->subSecond()));
    }

    public function test_old_partial_delivery_preserves_confirmed_recipient_and_ages_only_unconfirmed_recipient(): void
    {
        $email = $this->email(['cc_recipients' => ['pending@example.test'], 'created_at' => $this->now->subDays(32)]);
        $ledger = app(OutboundDeliveryLedger::class);
        $ledger->initialResponse($email, ['delivered' => ['member@example.test'], 'queued' => ['pending@example.test']], $this->now->subDays(32));
        $ledger->markHistorical();
        self::assertSame('partially_delivered', $email->fresh()->status);
        self::assertSame('historical_unknown', $email->recipients()->where('address', 'pending@example.test')->sole()->status);
        self::assertSame('delivered', $email->recipients()->where('address', 'member@example.test')->sole()->status);
    }

    public function test_backfilled_local_pre_acceptance_failure_is_not_presented_as_pending(): void
    {
        $email = $this->email(['status' => 'failed_pre_acceptance', 'accepted_at' => null, 'failed_at' => $this->now]);
        app(OutboundDeliveryLedger::class)->initialize($email);
        self::assertSame('failed', $email->recipients()->sole()->status);
        self::assertTrue($email->recipients()->sole()->is_terminal);
    }

    public function test_provider_event_cannot_add_an_unaddressed_recipient(): void
    {
        $email = $this->email();
        self::assertFalse(app(OutboundDeliveryLedger::class)->record($email, 'stranger@example.test', 'delivered', 'delivered', $this->now));
        self::assertSame(0, OutboundEmailDeliveryEvent::query()->count());
        self::assertSame(1, $email->recipients()->count());
    }

    public function test_old_unconfirmed_messages_are_historical_without_overwriting_confirmed_delivery(): void
    {
        $old = $this->email(['created_at' => $this->now->subDays(32)]);
        $confirmed = $this->email(['created_at' => $this->now->subDays(32), 'status' => 'delivered', 'delivered_at' => $this->now->subDays(32)]);
        self::assertSame('Delivery status unavailable / historical', $old->deliveryStatusLabel());
        self::assertSame(1, app(OutboundDeliveryLedger::class)->markHistorical());
        self::assertSame('historical_unknown', $old->fresh()->status);
        self::assertSame('delivered', $confirmed->fresh()->status);
    }

    public function test_graphql_overlap_is_replayed_idempotently_and_checkpoint_advances_only_after_success(): void
    {
        $email = $this->email(['created_at' => $this->now->subHour()]);
        $checkpoint = OutboundEmailReconciliationCheckpoint::query()->create(['zone_id' => str_repeat('b', 32), 'completed_through' => $this->now->subMinutes(5)]);
        Http::fake(function ($request) {
            $end = CarbonImmutable::parse($request['variables']['end']);

            return Http::response($this->graphql($end->gte($this->now->subMinute()) ? [$this->event()] : []));
        });
        $service = app(CloudflareDeliveryReconciler::class);
        self::assertSame(1, $service->reconcile()['events_recorded']);
        self::assertSame(0, $service->reconcile()['events_recorded']);
        self::assertSame('delivered', $email->fresh()->status);
        self::assertTrue($checkpoint->fresh()->completed_through->equalTo($this->now));
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.cloudflare.com/client/v4/graphql'
            && $request['variables']['zoneTag'] === str_repeat('b', 32)
            && $request['variables']['start'] === $this->now->subMinutes(1445)->toIso8601ZuluString()
            && ! str_contains($request->body(), 'subject'));
    }

    public function test_graphql_errors_preserve_checkpoint_and_do_not_leak_provider_errors(): void
    {
        $checkpoint = OutboundEmailReconciliationCheckpoint::query()->create(['zone_id' => str_repeat('b', 32), 'completed_through' => $this->now->subHour()]);
        Http::fake(['api.cloudflare.com/client/v4/graphql' => Http::response(['errors' => [['message' => 'secret provider error']]])]);
        try {
            app(CloudflareDeliveryReconciler::class)->reconcile();
            self::fail('GraphQL errors must not be accepted as an empty event list.');
        } catch (RuntimeException $exception) {
            self::assertStringNotContainsString('secret', $exception->getMessage());
            self::assertTrue($checkpoint->fresh()->completed_through->equalTo($this->now->subHour()));
        }
    }

    public function test_backfill_is_bounded_to_31_days_and_matches_domain_and_id_not_subject(): void
    {
        $email = $this->email(['created_at' => $this->now->subHour()]);
        Http::fake(function ($request) {
            $end = CarbonImmutable::parse($request['variables']['end']);

            return Http::response($this->graphql($end->equalTo($this->now) ? [
                $this->event(['messageId' => 'different-id']), $this->event(['sendingDomain' => 'unrelated.test']), $this->event(),
            ] : []));
        });
        $result = app(CloudflareDeliveryReconciler::class)->reconcile(true);
        self::assertSame(31, $result['requests']);
        self::assertSame(1, $result['events_recorded']);
        self::assertSame('delivered', $email->fresh()->status);
        Http::assertSent(fn ($request): bool => $request['variables']['start'] === $this->now->subDays(31)->toIso8601ZuluString());
    }

    public function test_saturated_graphql_window_is_split_instead_of_silently_dropping_events(): void
    {
        $email = $this->email(['created_at' => $this->now->subHour()]);
        config(['communications.delivery.overlap_minutes' => 10]);
        OutboundEmailReconciliationCheckpoint::query()->create(['zone_id' => str_repeat('b', 32), 'completed_through' => $this->now]);
        $attempt = 0;
        Http::fake(function ($request) use (&$attempt) {
            $attempt++;
            if ($attempt === 1) {
                return Http::response($this->graphql(array_fill(0, 1000, $this->event())));
            }

            return Http::response($this->graphql($attempt === 3 ? [$this->event()] : []));
        });
        $result = app(CloudflareDeliveryReconciler::class)->reconcile();
        self::assertSame(3, $result['requests']);
        self::assertSame('delivered', $email->fresh()->status);
    }

    public function test_dispatcher_uses_real_recipient_response_and_preserves_private_attachments_and_headers(): void
    {
        Storage::fake('local');
        $this->budget();
        Http::fake(['api.cloudflare.com/client/v4/accounts/*' => Http::response(['success' => true, 'result' => [
            'message_id' => '<provider@example.test>', 'delivered' => ['member@example.test'], 'permanent_bounces' => ['bad@example.test'],
        ]])]);
        $email = app(CloudflareEmailDispatcher::class)->send(
            [' MEMBER@example.test ', 'bad@example.test'], 'Test', 'Body', null, 'admin_reply',
            attachments: [['filename' => 'test.txt', 'type' => 'text/plain', 'content' => base64_encode('Attachment')]],
            headers: ['In-Reply-To' => '<parent@example.test>', 'References' => '<parent@example.test>'],
        );
        self::assertSame('partially_delivered', $email->status);
        self::assertSame('<provider@example.test>', $email->message_id);
        self::assertSame('member@example.test +1', $email->recipient_summary);
        Storage::disk('local')->assertExists($email->attachment_metadata[0]['path']);
        Http::assertSent(fn ($request): bool => $request['headers']['In-Reply-To'] === '<parent@example.test>');
        self::assertNotNull($email->budget_reserved_at);
        self::assertNull($email->budget_released_at);
    }

    public function test_explicit_structured_suppression_rejection_records_failure_without_releasing_budget_or_retrying(): void
    {
        $this->budget();
        Http::fake(['api.cloudflare.com/client/v4/accounts/*' => Http::response([
            'success' => false, 'errors' => [['code' => 'E_RECIPIENT_SUPPRESSED', 'message' => 'Private provider context']],
        ], 400)]);
        try {
            app(CloudflareEmailDispatcher::class)->send(['member@example.test'], 'Test', 'Body', null, 'admin_compose');
            self::fail('The rejected send must be surfaced to its caller.');
        } catch (\Illuminate\Http\Client\RequestException) {
            $email = OutboundEmail::query()->sole();
            self::assertSame('rejected', $email->status);
            self::assertSame('rejected', $email->recipients()->sole()->status);
            self::assertNull($email->accepted_at);
            self::assertNull($email->budget_released_at);
            self::assertNotNull($email->budget_reserved_at);
            self::assertStringNotContainsString('Private provider context', $email->toJson());
        }
        Http::assertSentCount(1);
    }

    private function budget(): void
    {
        CloudflareUsageBudget::query()->create([
            'provider_account_id' => str_repeat('a', 32),
            'cycle_start' => $this->now->startOfMonth(), 'cycle_end' => $this->now->addMonth()->startOfMonth(),
            'provider_chargeable_used' => 0, 'provider_daily_quota' => 10000, 'provider_daily_used' => 0,
            'hub_safe_ceiling' => 2850, 'worker_requests_used' => 0, 'worker_cpu_ms_used' => 0,
            'worker_request_threshold' => 9000000, 'worker_cpu_ms_threshold' => 27000000,
            'reconciled_at' => $this->now, 'provider_daily_reconciled_at' => $this->now,
        ]);
    }

    public function test_authentication_and_documented_validation_denials_are_definitive_and_sanitized(): void
    {
        $this->budget();
        $sequence = Http::sequence();
        foreach ([401, 403, 400] as $statusCode) {
            $sequence->push([
                'success' => false,
                'errors' => [['code' => 10001, 'message' => $statusCode === 400 ? 'email.sending.error.invalid_request_schema' : 'Private provider context']],
            ], $statusCode);
        }
        Http::fake(['api.cloudflare.com/client/v4/accounts/*' => $sequence]);
        foreach ([401, 403, 400] as $statusCode) {
            try {
                app(CloudflareEmailDispatcher::class)->send(['member@example.test'], 'Test', 'Body', null, 'admin_compose');
                self::fail('The denied send must be surfaced to its caller.');
            } catch (\Illuminate\Http\Client\RequestException) {
                $email = OutboundEmail::query()->latest('id')->firstOrFail();
                self::assertSame($statusCode === 400 ? 'rejected' : 'failed', $email->status);
                $recipient = $email->recipients()->sole();
                self::assertSame($statusCode === 400 ? 'INVALID_REQUEST_SCHEMA' : 'HTTP_'.$statusCode, $recipient->failure_code);
                self::assertTrue($recipient->is_terminal);
                self::assertStringNotContainsString('Private provider context', $recipient->failure_detail);
                self::assertNull($email->accepted_at);
                self::assertNull($email->budget_released_at);
                self::assertNotNull($email->budget_reserved_at);
            }
        }
    }

    private function email(array $overrides = []): OutboundEmail
    {
        return OutboundEmail::query()->forceCreate(array_merge([
            'provider' => 'cloudflare', 'provider_message_id' => 'provider-id', 'source_type' => 'admin_compose',
            'from_address' => 'info@mbfdhub.com', 'to_recipients' => ['member@example.test'],
            'subject' => 'Test', 'recipient_count' => 1, 'chargeable_budget_units' => 1,
            'status' => 'queued', 'accepted_at' => $this->now,
        ], $overrides));
    }

    private function graphql(array $events): array
    {
        return ['data' => ['viewer' => ['zones' => [['emailSendingAdaptive' => $events]]]]];
    }

    private function event(array $overrides = []): array
    {
        return array_merge(['messageId' => 'provider-id', 'envelopeTo' => 'member@example.test', 'status' => 'delivered',
            'eventType' => 'delivery', 'datetime' => $this->now->subMinute()->toIso8601ZuluString(), 'sendingDomain' => 'mbfdhub.com'], $overrides);
    }
}
