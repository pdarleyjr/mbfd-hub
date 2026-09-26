<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Filament\Pages\ComposeEmail;
use App\Filament\Resources\OutboundEmailResource\Pages\ListOutboundEmails;
use App\Filament\Resources\OutboundEmailResource\Pages\ViewOutboundEmail;
use App\Models\OutboundEmail;
use App\Models\User;
use App\Services\Communications\EmailConversation;
use App\Services\Communications\OutboundDeliveryLedger;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class CommunicationsMessageUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = User::factory()->create(['account_status' => 'active']);
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($admin);
        $this->withoutVite();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_sent_renders_scalar_to_and_search_never_matches_bcc(): void
    {
        $email = $this->email();
        Livewire::test(ListOutboundEmails::class)->assertSee('public@example.test')->assertDontSee('private@example.test')
            ->searchTable('public@example.test')->assertCanSeeTableRecords([$email])
            ->searchTable('private@example.test')->assertCanNotSeeTableRecords([$email]);
    }

    public function test_historical_filter_includes_reconciled_and_aged_unresolved_messages_without_confirmed_terminal_evidence(): void
    {
        $persisted = $this->email();
        $persisted->forceFill(['created_at' => now()->subDays(32)])->save();
        app(OutboundDeliveryLedger::class)->markHistorical();
        self::assertSame('historical_unknown', $persisted->fresh()->status);
        $unresolved = $this->email();
        $unresolved->forceFill(['created_at' => now()->subDays(32)])->save();
        $current = $this->email();
        $confirmed = $this->email();
        $confirmed->forceFill(['status' => 'accepted_with_delivery_issues', 'created_at' => now()->subDays(32)])->save();
        $confirmed->recipients()->create([
            'address' => 'public@example.test', 'recipient_class' => 'to', 'status' => 'bounced', 'is_terminal' => true,
        ]);
        $delivered = $this->email();
        $delivered->forceFill(['status' => 'delivered', 'created_at' => now()->subDays(32)])->save();
        $otherSource = $this->email();
        $otherSource->forceFill(['status' => 'historical_unknown', 'source_type' => 'member_onboarding_invitation', 'created_at' => now()->subDays(32)])->save();

        Livewire::test(ListOutboundEmails::class)
            ->filterTable('status', 'historical_unknown')
            ->assertCanSeeTableRecords([$persisted, $unresolved, $otherSource])
            ->assertCanNotSeeTableRecords([$current, $confirmed, $delivered])
            ->filterTable('source_type', 'admin_compose')
            ->assertCanSeeTableRecords([$persisted, $unresolved])
            ->assertCanNotSeeTableRecords([$otherSource])
            ->filterTable('status', 'accepted_with_delivery_issues')
            ->assertCanSeeTableRecords([$confirmed])
            ->assertCanNotSeeTableRecords([$persisted, $unresolved, $current, $delivered]);
    }

    public function test_message_view_has_readable_content_without_bcc_and_sensitive_actions_are_hidden(): void
    {
        $email = $this->email();
        Livewire::test(ViewOutboundEmail::class, ['record' => $email->id])->assertSee('Original body')->assertSee('public@example.test')
            ->assertDontSee('private@example.test')->assertActionVisible('reply')->assertActionVisible('forward');
        $email->update(['source_type' => 'member_onboarding_invitation', 'text_body' => '[Sensitive account-security message omitted]']);
        Livewire::test(ViewOutboundEmail::class, ['record' => $email->id])->assertActionHidden('reply')->assertActionHidden('forward');
    }

    public function test_source_labels_and_delivery_timeline_are_readable_and_preserve_bcc_privacy(): void
    {
        $email = $this->email();
        $email->update(['source_type' => 'App\\Notifications\\NewSubmissionNotification']);
        foreach (['to' => 'public@example.test', 'bcc' => 'private@example.test'] as $class => $address) {
            $recipient = $email->recipients()->create(['address' => $address, 'recipient_class' => $class, 'status' => 'deferred']);
            $email->deliveryEvents()->create([
                'outbound_email_recipient_id' => $recipient->id, 'identity' => hash('sha256', $class),
                'event_type' => 'newEmailSending', 'status' => 'deferred', 'occurred_at' => now(),
                'failure_detail' => $class === 'bcc' ? 'Private failure details' : 'Recipient server temporarily unavailable',
            ]);
        }
        Livewire::test(ListOutboundEmails::class)->assertSee('New submission');
        Livewire::test(ViewOutboundEmail::class, ['record' => $email->id])->assertSee('New submission')->assertSee('Deferred')
            ->assertSee('Recipient server temporarily unavailable')->assertDontSee('newEmailSending')
            ->assertDontSee('Private failure details')->assertDontSee('private@example.test')
            ->assertActionVisible('reply')->assertActionVisible('reply_all')->assertActionVisible('forward');
        self::assertSame('Onboarding invitation', EmailConversation::sourceLabel('member_onboarding_invitation'));
        self::assertSame('Training Assignment', EmailConversation::sourceLabel('App\\Notifications\\TrainingAssignmentNotification'));
    }

    public function test_sensitive_source_is_rejected_server_side_even_for_send_authorized_admin(): void
    {
        $email = $this->email();
        $email->update(['source_type' => 'member_onboarding_invitation']);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(EmailConversation::class)->source('outbound', $email->id, auth()->user());
    }

    public function test_compose_invalid_recipient_stops_before_provider_request(): void
    {
        Http::fake();
        Livewire::test(ComposeEmail::class)->fillForm(['to' => ['invalid-address'], 'subject' => 'Operations', 'text' => 'Body'])
            ->call('send')->assertHasFormErrors(['to.0']);
        Http::assertNothingSent();
    }

    public function test_reply_send_preserves_provider_headers_parent_and_private_recipient_classes(): void
    {
        config(['communications.cloudflare.account_id' => str_repeat('a', 32), 'communications.cloudflare.api_token' => 'test-only-token']);
        \App\Models\CloudflareUsageBudget::create([
            'provider_account_id' => str_repeat('a', 32), 'cycle_start' => now()->subDay(), 'cycle_end' => now()->addDays(29),
            'provider_chargeable_used' => 0, 'provider_daily_quota' => 100, 'provider_daily_used' => 0,
            'hub_safe_ceiling' => 2850, 'worker_request_threshold' => 9000000, 'worker_cpu_ms_threshold' => 27000000,
            'worker_requests_used' => 0, 'worker_cpu_ms_used' => 0, 'reconciled_at' => now(), 'provider_daily_reconciled_at' => now(),
        ]);
        $source = $this->email();
        $source->update(['message_id' => '<parent@example.test>', 'references' => ['<first@example.test>']]);
        Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true, 'result' => ['message_id' => '<reply@example.test>', 'queued' => ['public@example.test', 'copy@example.test']]])]);
        Livewire::withQueryParams(['source' => 'outbound', 'message' => $source->id, 'mode' => 'reply'])
            ->test(ComposeEmail::class)->fillForm(['to' => ['PUBLIC@example.test'], 'cc' => ['copy@example.test'], 'text' => 'Reply body'])
            ->call('send')->assertHasNoFormErrors()->assertRedirect();
        Http::assertSent(fn ($request) => $request['headers']['In-Reply-To'] === '<parent@example.test>'
            && $request['headers']['References'] === '<first@example.test> <parent@example.test>'
            && $request['to'] === ['public@example.test'] && $request['cc'] === ['copy@example.test'] && ! isset($request['bcc']));
        $reply = OutboundEmail::where('id', '!=', $source->id)->sole();
        self::assertSame($source->id, $reply->parent_outbound_email_id);
        self::assertSame('admin_reply', $reply->source_type);
        self::assertSame('queued', $reply->status);
        self::assertSame('<parent@example.test>', $reply->in_reply_to);
    }

    private function email(): OutboundEmail
    {
        return OutboundEmail::create(['provider' => 'cloudflare', 'source_type' => 'admin_compose', 'from_address' => 'info@mbfdhub.com', 'to_recipients' => ['public@example.test'], 'bcc_recipients' => ['private@example.test'], 'subject' => 'Operational note', 'text_body' => 'Original body', 'recipient_count' => 2, 'chargeable_budget_units' => 2, 'status' => 'queued']);
    }
}
