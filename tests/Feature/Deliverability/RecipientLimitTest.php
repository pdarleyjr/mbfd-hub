<?php

declare(strict_types=1);

namespace Tests\Feature\Deliverability;

use App\Livewire\Mail\MessageComposer;
use App\Models\OutboundEmail;
use App\Models\User;
use App\Services\Communications\CloudflareCostGuard;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\Feature\Deliverability\Concerns\SendsThroughCloudflareFake;
use Tests\TestCase;

/**
 * A message of 6 to 10 recipients could never pass the 5-per-minute guard. The composer now says so up front.
 * No guard is relaxed: these tests also prove the guard itself still refuses what it refused before.
 *
 * The composer is the Email Center's (it replaced the Compose Email form), so a refusal shows as a sentence in the composer
 * ("Not sent. ...") rather than a form error under the To field.
 */
final class RecipientLimitTest extends TestCase
{
    use RefreshDatabase;
    use SendsThroughCloudflareFake;

    /** @return array<string, array{int, int, int}> */
    public static function caps(): array
    {
        return [
            'defaults' => [10, 5, 5],
            'message cap is smaller' => [3, 5, 3],
            'minute cap is smaller' => [10, 2, 2],
            'minute cap cannot exceed five' => [10, 100, 5],
            'message cap cannot lift the minute cap' => [50, 5, 5],
            'both zero' => [0, 0, 0],
        ];
    }

    #[DataProvider('caps')]
    public function test_the_effective_limit_is_the_smaller_of_the_two_caps(int $perMessage, int $perMinute, int $expected): void
    {
        config([
            'communications.cloudflare.max_recipients_per_message' => $perMessage,
            'communications.cloudflare.max_recipient_units_per_minute' => $perMinute,
        ]);

        self::assertSame($expected, app(CloudflareCostGuard::class)->effectiveRecipientLimit());
    }

    public function test_the_composer_stops_a_message_that_could_never_be_sent_and_says_why(): void
    {
        $this->prepareSending();
        $this->actAsSuperAdmin();

        $component = Livewire::test(MessageComposer::class)
            ->set('to', $this->addresses(6))->set('subject', 'Operations')->set('data.body', '<p>Body</p>')->set('reviewedRecipients', true)
            ->call('send')->assertSet('result.status', 'refused');

        $message = (string) $component->get('result.message');
        self::assertStringContainsString('at most 5', $message);
        self::assertStringContainsString('Department Updates', $message);
        // The composer also says it before anyone presses Send, and with the count.
        $component->assertSee('at most 5')->assertSee('Department Updates');
        Http::assertNothingSent();
        $this->assertDatabaseCount('outbound_emails', 0);
    }

    public function test_recipients_are_counted_across_to_cc_and_bcc_after_removing_duplicates(): void
    {
        $this->prepareSending();
        $this->actAsSuperAdmin();

        // Six different people, however many times some of them are repeated across the three fields.
        Livewire::test(MessageComposer::class)
            ->set('to', ['person-1@example.test', 'person-2@example.test'])
            ->set('cc', ['PERSON-1@example.test', 'person-3@example.test', 'person-4@example.test'])
            ->set('bcc', ['person-5@example.test', 'person-6@example.test', 'Person-2@Example.test'])
            ->set('subject', 'Operations')->set('data.body', '<p>Body</p>')->set('reviewedRecipients', true)
            ->call('send')->assertSet('result.status', 'refused')->assertSee('at most 5');

        Http::assertNothingSent();
    }

    public function test_repeated_addresses_do_not_count_twice(): void
    {
        $this->prepareSending();
        $this->actAsSuperAdmin();

        Livewire::test(MessageComposer::class)
            ->set('to', ['person-1@example.test', 'person-2@example.test', 'person-3@example.test'])
            ->set('cc', ['PERSON-1@example.test', 'person-4@example.test'])
            ->set('bcc', ['person-5@example.test', 'Person-2@Example.test'])
            ->set('subject', 'Operations')->set('data.body', '<p>Body</p>')->set('reviewedRecipients', true)
            ->call('send')->assertSet('result.status', 'accepted');

        self::assertSame(5, OutboundEmail::query()->sole()->recipient_count);
    }

    public function test_the_composer_follows_a_lower_configured_cap(): void
    {
        $this->prepareSending();
        $this->actAsSuperAdmin();
        config(['communications.cloudflare.max_recipients_per_message' => 3]);

        Livewire::test(MessageComposer::class)
            ->set('to', $this->addresses(4))->set('subject', 'Operations')->set('data.body', '<p>Body</p>')
            ->call('send')->assertSet('result.status', 'refused')->assertSee('at most 3');

        Http::assertNothingSent();
    }

    public function test_exactly_the_limit_still_sends_after_the_existing_confirmation(): void
    {
        $this->prepareSending();
        $this->actAsSuperAdmin();

        $composer = Livewire::test(MessageComposer::class)
            ->set('to', $this->addresses(5))->set('subject', 'Operations')->set('data.body', '<p>Body</p>');

        // Five people need the member's own "I have reviewed every recipient" tick first, as the old form also asked.
        $composer->call('send')->assertSet('result.status', 'refused')->assertSee('confirm you have reviewed every recipient');
        Http::assertNothingSent();

        $composer->set('reviewedRecipients', true)->call('send')->assertSet('result.status', 'accepted');

        self::assertSame(5, OutboundEmail::query()->sole()->recipient_count);
        Http::assertSentCount(1);
    }

    public function test_the_guard_itself_still_refuses_a_sixth_recipient_in_the_same_minute(): void
    {
        $this->prepareSending();

        try {
            app(\App\Services\Communications\CloudflareEmailDispatcher::class)->send($this->addresses(6), 'Operations', 'Body', null, 'admin_compose');
            self::fail('Six recipients must still be refused by the per-minute guard.');
        } catch (\App\Exceptions\EmailBudgetExhausted $exception) {
            self::assertSame(61, $exception->retryAfterSeconds);
        }
        Http::assertNothingSent();
    }

    public function test_a_blocked_send_configuration_is_left_to_the_pause_message_not_the_recipient_message(): void
    {
        $this->prepareSending();
        $this->actAsSuperAdmin();
        config(['communications.cloudflare.max_recipient_units_per_minute' => 0]);

        Livewire::test(MessageComposer::class)
            ->set('to', ['one@example.test'])->set('subject', 'Operations')->set('data.body', '<p>Body</p>')
            ->call('send')->assertSet('result.status', 'deferred')->assertSee('Nothing was sent')->assertDontSee('at most 0');

        Http::assertNothingSent();
    }

    /** @return list<string> */
    private function addresses(int $count): array
    {
        return array_map(fn (int $number): string => "person-{$number}@example.test", range(1, $count));
    }

    private function actAsSuperAdmin(): void
    {
        $admin = User::factory()->create(['account_status' => 'active']);
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($admin);
        $this->withoutVite();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }
}
