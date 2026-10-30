<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\HubPushSubscription;
use App\Models\User;
use App\Services\Notifications\PushDeviceStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Notifications\Concerns\BuildsNotificationPersonas;
use Tests\TestCase;

final class PushDeliveryHealthStatusTest extends TestCase
{
    use BuildsNotificationPersonas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-30 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param array<string, mixed> $columns */
    private function device(User $user, array $columns = []): HubPushSubscription
    {
        /** @var HubPushSubscription $device */
        $device = $user->pushSubscriptions()->create([
            'endpoint' => 'https://web.push.apple.com/fixture-'.uniqid(),
            'public_key' => 'BAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
            'auth_token' => 'AAAAAAAAAAAAAAAAAAAAAA',
        ]);

        return $device->forceFill($columns)->save() ? $device->refresh() : $device;
    }

    public function test_the_wording_is_exact(): void
    {
        $this->assertSame(
            ['Accepted by push service', 'Rejected by push service', 'Device setup needed', 'Waiting to retry', 'Never attempted'],
            PushDeviceStatus::all(),
        );
        $this->assertSame(
            'Accepted means Apple/Google/Microsoft/Mozilla took the alert. Whether the phone displayed it is not observable by the server.',
            PushDeviceStatus::EXPLANATION,
        );
    }

    public function test_a_device_whose_last_attempt_succeeded_is_accepted(): void
    {
        $user = $this->member();

        $this->assertSame('Accepted by push service', PushDeviceStatus::for($this->device($user, ['last_success_at' => now()->subHour()])));
        // An older failure that a later success cleared (the recorder nulls the reason) does not count against it.
        $this->assertSame('Accepted by push service', PushDeviceStatus::for($this->device($user, [
            'last_failure_at' => now()->subDay(), 'last_success_at' => now()->subHour(), 'last_failure_reason' => null,
        ])));
    }

    public function test_a_refusal_other_than_404_or_410_is_rejected_and_is_not_a_back_off(): void
    {
        $user = $this->member();

        foreach ([400, 401, 403, 413] as $code) {
            $device = $this->device($user, [
                'last_success_at' => now()->subDay(), 'last_failure_at' => now()->subMinute(),
                'last_failure_reason' => "rejected ({$code})", 'failure_count' => 1,
            ]);
            $this->assertSame('Rejected by push service', PushDeviceStatus::for($device), "HTTP {$code}");
        }
    }

    public function test_a_device_the_push_service_no_longer_knows_needs_setup(): void
    {
        $user = $this->member();

        foreach ([404, 410] as $code) {
            $device = $this->device($user, ['last_failure_at' => now(), 'last_failure_reason' => "rejected ({$code})"]);
            $this->assertSame('Device setup needed', PushDeviceStatus::for($device), "HTTP {$code}");
        }
    }

    public function test_a_device_in_back_off_or_failing_transiently_is_waiting_to_retry(): void
    {
        $user = $this->member();

        $backedOff = $this->device($user, [
            'last_failure_at' => now(), 'last_failure_reason' => 'rate_limited (429)', 'failure_count' => 2,
            'backoff_until' => now()->addMinutes(3), 'last_success_at' => now()->subDay(),
        ]);
        $this->assertSame('Waiting to retry', PushDeviceStatus::for($backedOff));

        // A back-off that has ended with no success since: the next alert goes to the device again.
        $ended = $this->device($user, ['last_failure_at' => now()->subHour(), 'last_failure_reason' => 'push_service_error (503)', 'backoff_until' => now()->subMinute()]);
        $this->assertSame('Waiting to retry', PushDeviceStatus::for($ended));
        $this->assertSame('Waiting to retry', PushDeviceStatus::for($this->device($user, ['last_failure_at' => now(), 'last_failure_reason' => 'no_response'])));
    }

    public function test_a_back_off_that_is_still_running_wins_over_an_older_rejection(): void
    {
        $device = $this->device($this->member(), ['last_failure_reason' => 'rejected (403)', 'backoff_until' => now()->addMinute()]);

        $this->assertSame('Waiting to retry', PushDeviceStatus::for($device));
    }

    public function test_a_device_nothing_was_ever_sent_to_was_never_attempted(): void
    {
        $this->assertSame('Never attempted', PushDeviceStatus::for($this->device($this->member())));
    }

    public function test_reasons_are_written_in_plain_words_without_codes_in_the_way(): void
    {
        $this->assertNull(PushDeviceStatus::plainReason(null));
        $this->assertSame('The push service refused the alert (code 403).', PushDeviceStatus::plainReason('rejected (403)'));
        $this->assertSame('The push service asked the Hub to slow down (code 429).', PushDeviceStatus::plainReason('rate_limited (429)'));
        $this->assertSame('The push service had a problem (code 503).', PushDeviceStatus::plainReason('push_service_error (503)'));
        $this->assertSame('The push service did not answer.', PushDeviceStatus::plainReason('no_response'));
        $this->assertSame('The push service no longer knows this device (code 410).', PushDeviceStatus::plainReason('rejected (410)'));
        foreach (PushDeviceStatus::all() as $status) {
            $this->assertStringNotContainsStringIgnoringCase('delivered', $status);
        }
    }
}
