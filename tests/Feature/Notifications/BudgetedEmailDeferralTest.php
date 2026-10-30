<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Jobs\DeliverDeferredEmail;
use App\Models\OutboundEmail;
use App\Models\User;
use App\Notifications\Channels\BudgetedMailChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Notifications\Concerns\BuildsNotificationPersonas;
use Tests\TestCase;

/**
 * More alerts per minute than the email budget allows used to throw out of the queued notification job,
 * which the worker retried three times at once and then dropped. A refusal with a retry window now
 * defers the email (bounded); a refusal without one (the cycle ceiling) is logged and nothing retries.
 */
final class BudgetedEmailDeferralTest extends TestCase
{
    use BuildsNotificationPersonas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Securely configured, but no reconciled usage budget exists: the cost guard refuses every
        // reservation with a 300 second retry window, before anything reaches the provider.
        config()->set('communications.cloudflare.account_id', str_repeat('a', 32));
        config()->set('communications.cloudflare.api_token', 'test-token');
        Http::fake(fn () => throw new \RuntimeException('The provider must never be contacted in this test.'));
    }

    private function recipient(): User
    {
        return $this->rosterMember('Firefighter', 'member@miamibeachfl.gov');
    }

    private function alert(): Notification
    {
        $notification = new class extends Notification
        {
            public function via($notifiable): array
            {
                return [BudgetedMailChannel::class];
            }

            /** @return array{subject: string, text: string} */
            public function toBudgetedEmail(object $notifiable): array
            {
                return ['subject' => 'Rescue 2 defect', 'text' => 'Missing airway bag item'];
            }
        };
        $notification->id = (string) Str::uuid();

        return $notification;
    }

    public function test_a_refusal_with_a_retry_window_defers_the_email_instead_of_dropping_it(): void
    {
        Queue::fake();

        app(BudgetedMailChannel::class)->send($this->recipient(), $this->alert());

        Queue::assertPushed(DeliverDeferredEmail::class, fn (DeliverDeferredEmail $job): bool => $job->attempt === 2
            && $job->queue === 'notifications'
            && $job->delay !== null);
        // Nothing was submitted: the dispatcher recorded the refusal before the provider.
        $this->assertNull(OutboundEmail::query()->whereNotNull('submitted_at')->first());
    }

    public function test_the_deferred_job_tries_again_and_stops_after_the_bounded_number_of_attempts(): void
    {
        Queue::fake();
        $member = $this->recipient();

        (new DeliverDeferredEmail($this->alert(), $member, BudgetedMailChannel::MAX_DEFERRALS - 1))->handle(app(BudgetedMailChannel::class));
        Queue::assertPushed(DeliverDeferredEmail::class, fn (DeliverDeferredEmail $job): bool => $job->attempt === BudgetedMailChannel::MAX_DEFERRALS);

        Queue::fake();
        (new DeliverDeferredEmail($this->alert(), $member, BudgetedMailChannel::MAX_DEFERRALS))->handle(app(BudgetedMailChannel::class));
        Queue::assertNothingPushed();
    }

    public function test_a_member_without_a_city_email_is_skipped_without_any_retry(): void
    {
        Queue::fake();

        app(BudgetedMailChannel::class)->send($this->member(), $this->alert());

        Queue::assertNothingPushed();
    }
}
