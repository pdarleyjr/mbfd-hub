<?php

declare(strict_types=1);

namespace Tests\Feature\Deliverability;

use App\Exceptions\EmailBudgetExhausted;
use App\Models\CloudflareUsageBudget;
use App\Models\OutboundEmail;
use App\Models\OutboundEmailDeliveryEvent;
use App\Services\Communications\CloudflareDeliveryReconciler;
use App\Services\Communications\CloudflareEmailDispatcher;
use App\Services\Communications\OutboundDeliveryLedger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Deliverability\Concerns\SendsThroughCloudflareFake;
use Tests\TestCase;

/**
 * What the Hub records for each way the provider can answer, and what it makes of delivery analytics rows.
 * Every case sends exactly one request: there is no unattended resend of an ambiguous message.
 */
final class ProviderOutcomesTest extends TestCase
{
    use RefreshDatabase;
    use SendsThroughCloudflareFake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareSending();
        config(['communications.delivery.zone_id' => str_repeat('b', 32), 'communications.delivery.analytics_token' => 'isolated-read-credential']);
    }

    /** @return array<string, array{string, string, string, bool, ?string}> */
    public static function providerAnswers(): array
    {
        // answer, ledger status, recipient status, budget released, recipient failure code
        return [
            '401 unauthorised' => ['401', 'failed', 'failed', false, 'HTTP_401'],
            '403 forbidden' => ['403', 'failed', 'failed', false, 'HTTP_403'],
            '429 throttled' => ['429', 'failed_pre_acceptance', 'failed', true, null],
            'timeout' => ['timeout', 'acceptance_unknown', 'unknown', false, null],
            '503 server error' => ['503', 'acceptance_unknown', 'unknown', false, null],
            '200 without a message id' => ['no-id', 'acceptance_unknown', 'unknown', false, null],
            '200 with success false' => ['not-success', 'acceptance_unknown', 'unknown', false, null],
        ];
    }

    #[DataProvider('providerAnswers')]
    public function test_each_provider_answer_is_recorded_once_and_never_resent(string $answer, string $status, string $recipientStatus, bool $released, ?string $code): void
    {
        $attempts = 0;
        $this->fakeProvider(function (Request $request) use ($answer, &$attempts) {
            $attempts++;

            return match ($answer) {
                'timeout' => throw new ConnectionException('isolated timeout'),
                'no-id' => Http::response(['success' => true, 'result' => ['delivered' => []]]),
                'not-success' => Http::response(['success' => false, 'result' => ['message_id' => '<x@example.test>']]),
                '429' => Http::response(['success' => false, 'errors' => [['code' => 10004]]], 429, ['Retry-After' => '120']),
                default => Http::response(['success' => false, 'errors' => [['code' => 1, 'message' => 'Private provider context']]], (int) $answer),
            };
        });

        try {
            $this->send();
            self::fail('The answer is not an acceptance and must reach the caller as an exception.');
        } catch (EmailBudgetExhausted $exception) {
            self::assertSame('429', $answer);
            self::assertSame(120, $exception->retryAfterSeconds);
        } catch (HttpClientException) {
            self::assertNotSame('429', $answer);
        }

        $email = OutboundEmail::query()->sole();
        self::assertSame($status, $email->status);
        self::assertSame($released, $email->budget_released_at !== null);
        self::assertNull($email->accepted_at);
        self::assertSame($recipientStatus, $email->recipients()->sole()->status);
        self::assertSame($code, $email->recipients()->sole()->failure_code);
        self::assertStringNotContainsString('Private provider context', $email->toJson());
        self::assertSame(1, $attempts);
    }

    public function test_after_a_429_the_provider_backoff_blocks_the_next_send_without_a_request(): void
    {
        $this->fakeProvider(fn () => Http::response(['success' => false], 429, ['Retry-After' => '300']));
        try {
            $this->send();
        } catch (EmailBudgetExhausted) {
            // expected
        }

        try {
            $this->send();
            self::fail('The provider asked for a pause.');
        } catch (EmailBudgetExhausted $exception) {
            self::assertGreaterThanOrEqual(60, $exception->retryAfterSeconds);
        }
        Http::assertSentCount(1);
        self::assertNotNull(CloudflareUsageBudget::query()->sole()->provider_backoff_until);
    }

    public function test_a_suppressed_recipient_in_an_accepted_response_is_a_terminal_rejection_and_costs_nothing_extra(): void
    {
        $this->fakeProvider(fn (Request $request) => Http::response(['success' => true, 'result' => [
            'message_id' => '<suppressed@example.test>', 'suppressed_recipients' => (array) $request['to'],
        ]]));

        $email = $this->send();

        self::assertSame('rejected', $email->status);
        $recipient = $email->recipients()->sole();
        self::assertSame('rejected', $recipient->status);
        self::assertTrue($recipient->is_terminal);
        self::assertSame('send_response.suppressed_recipients', $recipient->last_event_type);
    }

    public function test_ambiguous_acceptance_keeps_its_reservation_and_a_later_analytics_event_settles_it(): void
    {
        $this->fakeProvider(function () {
            throw new ConnectionException('response lost');
        });
        try {
            $this->send();
        } catch (HttpClientException) {
            // The provider may have accepted it.
        }
        $email = OutboundEmail::query()->sole();
        $email->forceFill(['provider_message_id' => 'late-id'])->save();
        self::assertSame('acceptance_unknown', $email->status);

        app(OutboundDeliveryLedger::class)->record($email, 'member@miamibeachfl.gov', 'delivered', 'newEmail', $this->now->addMinute());

        self::assertSame('delivered', $email->fresh()->status);
        self::assertNull($email->fresh()->budget_released_at, 'a possibly-sent message keeps counting against the budget');
    }

    public function test_duplicate_and_out_of_order_analytics_rows_settle_on_the_latest_truth_exactly_once(): void
    {
        $email = $this->acceptedEmail();
        $events = [
            $this->event(['status' => 'delivered', 'datetime' => $this->now->addMinutes(3)->toIso8601ZuluString()]),
            $this->event(['status' => 'deferred', 'datetime' => $this->now->addMinute()->toIso8601ZuluString()]),
            $this->event(['status' => 'delivered', 'datetime' => $this->now->addMinutes(3)->toIso8601ZuluString()]),
            $this->event(['status' => 'deferred', 'datetime' => $this->now->addMinutes(2)->toIso8601ZuluString()]),
        ];
        $this->travelTo($this->now->addMinutes(10));
        $this->fakeAnalytics($events);

        $recorded = app(CloudflareDeliveryReconciler::class)->reconcile(true)['events_recorded'];

        self::assertSame(3, $recorded, 'the repeated row is recognised, not counted twice');
        self::assertSame(3, OutboundEmailDeliveryEvent::query()->count());
        self::assertSame('delivered', $email->fresh()->status);
        self::assertSame('delivered', $email->recipients()->sole()->status);
    }

    public function test_a_late_deferral_cannot_undo_a_confirmed_delivery_on_a_rerun(): void
    {
        $email = $this->acceptedEmail();
        $this->travelTo($this->now->addMinutes(10));
        $this->fakeAnalytics([
            $this->event(['status' => 'delivered', 'datetime' => $this->now->addMinute()->toIso8601ZuluString()]),
            $this->event(['status' => 'deferred', 'datetime' => $this->now->addMinutes(2)->toIso8601ZuluString()]),
        ]);

        app(CloudflareDeliveryReconciler::class)->reconcile(true);
        app(CloudflareDeliveryReconciler::class)->reconcile(true);

        self::assertSame('delivered', $email->fresh()->status);
        self::assertSame(2, OutboundEmailDeliveryEvent::query()->count());
    }

    /**
     * Findings about emailSendingAdaptive: Cloudflare documents its `status` as a delivery status such as
     * delivered or deliveryFailed and its `eventType` as the origin of the mail (newEmail, reply, forward,
     * incoming). It documents no complaint or suppression value in this dataset, so those outcomes can only
     * arrive from the send response (suppressed_recipients) or from a literal value if Cloudflare ever adds one.
     *
     * @return array<string, array{string, string, string, bool}>
     */
    public static function analyticsRows(): array
    {
        // status, eventType, expected ledger status, expected terminal
        return [
            'documented delivered' => ['delivered', 'newEmail', 'delivered', true],
            'documented delivery failure' => ['deliveryFailed', 'newEmail', 'bounced', true],
            'origin is never mistaken for an outcome' => ['delivered', 'reply', 'delivered', true],
            'literal complaint value' => ['complained', 'newEmail', 'complained', true],
            'lifecycle event name' => ['sent', 'cf.email.sending.message.complained', 'complained', true],
            'deferred' => ['deferred', 'forward', 'deferred', false],
            'undocumented suppression value is not guessed' => ['suppressed', 'newEmail', 'unknown', false],
            'unknown value never becomes terminal' => ['somethingNew', 'newEmail', 'unknown', false],
        ];
    }

    #[DataProvider('analyticsRows')]
    public function test_analytics_status_mapping(string $status, string $eventType, string $expected, bool $terminal): void
    {
        $email = $this->acceptedEmail();
        $this->travelTo($this->now->addMinutes(10));
        $this->fakeAnalytics([$this->event(['status' => $status, 'eventType' => $eventType, 'datetime' => $this->now->addMinute()->toIso8601ZuluString()])]);

        app(CloudflareDeliveryReconciler::class)->reconcile(true);

        $recipient = $email->recipients()->sole();
        self::assertSame($expected, $recipient->status);
        self::assertSame($terminal, (bool) $recipient->is_terminal);
    }

    public function test_a_failed_attempt_cloudflare_is_still_retrying_is_not_a_bounce(): void
    {
        $email = $this->acceptedEmail();
        $this->travelTo($this->now->addMinutes(10));
        $this->fakeAnalytics([$this->event(['status' => 'deliveryFailed', 'isLastEvent' => 0, 'datetime' => $this->now->addMinute()->toIso8601ZuluString()])]);

        app(CloudflareDeliveryReconciler::class)->reconcile(true);

        $recipient = $email->recipients()->sole();
        self::assertSame('deferred', $recipient->status);
        self::assertFalse((bool) $recipient->is_terminal);

        $this->fakeAnalytics([
            $this->event(['status' => 'deliveryFailed', 'isLastEvent' => 0, 'datetime' => $this->now->addMinute()->toIso8601ZuluString()]),
            $this->event(['status' => 'deliveryFailed', 'isLastEvent' => 1, 'datetime' => $this->now->addMinutes(2)->toIso8601ZuluString()]),
        ]);
        app(CloudflareDeliveryReconciler::class)->reconcile(true);

        self::assertSame('bounced', $email->recipients()->sole()->status);
    }

    public function test_an_unmapped_row_cannot_downgrade_a_confirmed_delivery(): void
    {
        $email = $this->acceptedEmail();
        app(OutboundDeliveryLedger::class)->record($email, 'member@miamibeachfl.gov', 'delivered', 'newEmail', $this->now->addMinute());
        $this->travelTo($this->now->addMinutes(10));
        $this->fakeAnalytics([$this->event(['status' => 'somethingNew', 'datetime' => $this->now->addMinutes(2)->toIso8601ZuluString()])]);

        app(CloudflareDeliveryReconciler::class)->reconcile(true);

        self::assertSame('delivered', $email->fresh()->status);
    }

    private function send(): OutboundEmail
    {
        return app(CloudflareEmailDispatcher::class)->send(['member@miamibeachfl.gov'], 'Outcome test', 'Body', null, 'test');
    }

    private function acceptedEmail(): OutboundEmail
    {
        $email = OutboundEmail::query()->forceCreate([
            'provider' => 'cloudflare', 'provider_message_id' => 'provider-id', 'source_type' => 'admin_compose',
            'from_address' => 'info@mbfdhub.com', 'to_recipients' => ['member@miamibeachfl.gov'], 'subject' => 'Test',
            'recipient_count' => 1, 'chargeable_budget_units' => 1, 'status' => 'accepted', 'accepted_at' => $this->now,
            'created_at' => $this->now->subMinute(),
        ]);
        app(OutboundDeliveryLedger::class)->initialize($email);

        return $email;
    }

    /** @param list<array<string, mixed>> $events */
    private function fakeAnalytics(array $events): void
    {
        $this->fakeProvider(function (Request $request) use ($events) {
            $start = CarbonImmutable::parse($request['variables']['start']);
            $end = CarbonImmutable::parse($request['variables']['end']);
            $inside = array_values(array_filter($events, function (array $event) use ($start, $end): bool {
                $at = CarbonImmutable::parse($event['datetime']);

                return $at->gte($start) && $at->lte($end);
            }));

            // The analytics API returns rows by time; keep the order the test supplied within the window.
            return Http::response(['data' => ['viewer' => ['zones' => [['emailSendingAdaptive' => $inside]]]]]);
        });
    }

    /** @param array<string, mixed> $overrides */
    private function event(array $overrides = []): array
    {
        return array_merge([
            'messageId' => 'provider-id', 'envelopeTo' => 'member@miamibeachfl.gov', 'status' => 'delivered',
            'eventType' => 'newEmail', 'datetime' => $this->now->addMinute()->toIso8601ZuluString(), 'sendingDomain' => 'mbfdhub.com',
        ], $overrides);
    }
}
