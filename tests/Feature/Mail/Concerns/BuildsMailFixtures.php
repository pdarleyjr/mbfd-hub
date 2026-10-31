<?php

declare(strict_types=1);

namespace Tests\Feature\Mail\Concerns;

use App\Enums\AccountStatus;
use App\Models\DailyRoster\RosterImport;
use App\Models\InboundEmail;
use App\Models\Mail\MailThread;
use App\Models\Mail\MailThreadMessage;
use App\Models\OutboundEmail;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/** Builds users, mail rows and threads for the Email Center tests. */
trait BuildsMailFixtures
{
    private int $mailSeq = 0;

    /** @param list<string> $permissions */
    protected function mailUser(array $permissions, bool $panel = true, array $attributes = []): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user = User::factory()->create(array_merge([
            'account_status' => AccountStatus::Active,
            'must_change_password' => false,
        ], $attributes));
        foreach ($panel ? ['admin.access', ...$permissions] : $permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    /** Panel entitlement plus the three Email permissions: read, send and manage. */
    protected function mailAdmin(): User
    {
        return $this->mailUser(['admin.email.view', 'admin.email.send', 'admin.email.manage']);
    }

    /** Panel entitlement plus only admin.email.view: read-only. */
    protected function mailViewer(): User
    {
        return $this->mailUser(['admin.email.view']);
    }

    /** Panel entitlement plus the older communications pair, as every mail reader had before Email permissions existed. */
    protected function legacyMailAdmin(): User
    {
        return $this->mailUser(['admin.communications.view', 'admin.communications.send']);
    }

    /** @param array<string, mixed> $attributes */
    protected function inbound(array $attributes = []): InboundEmail
    {
        $n = ++$this->mailSeq;
        $received = $attributes['received_at'] ?? now()->subMinutes(10)->addSeconds($n);

        return InboundEmail::query()->create(array_merge([
            'provider_message_id' => "<in.{$n}.".Str::lower(Str::random(6)).'@sender.test>',
            'from_address' => "sender{$n}@example.test",
            'from_display_name' => null,
            'to_address' => 'info@mbfdhub.com',
            'subject' => "Subject {$n}",
            'received_at' => $received,
            'text_body' => "Body {$n}",
            'safe_headers' => ['to' => ['info@mbfdhub.com'], 'cc' => [], 'reply-to' => null],
            'references' => [],
            'processing_status' => 'received',
        ], $attributes));
    }

    /** @param array<string, mixed> $attributes */
    protected function outbound(array $attributes = []): OutboundEmail
    {
        $n = ++$this->mailSeq;
        $id = "<out.{$n}.".Str::lower(Str::random(6)).'@mbfdhub.com>';

        return OutboundEmail::query()->create(array_merge([
            'provider' => 'cloudflare',
            'source_type' => 'admin_compose',
            'from_address' => 'info@mbfdhub.com',
            'to_recipients' => ["person{$n}@example.test"],
            'cc_recipients' => [],
            'bcc_recipients' => [],
            'subject' => "Outbound {$n}",
            'text_body' => "Outbound body {$n}",
            'attachment_metadata' => [],
            'recipient_count' => 1,
            'chargeable_budget_units' => 1,
            'status' => 'accepted',
            'queued_at' => now()->subMinutes(5)->addSeconds($n),
            'accepted_at' => now()->subMinutes(5)->addSeconds($n),
            'message_id' => $id,
            'provider_message_id' => $id,
        ], $attributes));
    }

    protected function rosterLedgerFor(InboundEmail $email, string $state = RosterImport::STATE_NEEDS_REVIEW): RosterImport
    {
        return RosterImport::query()->create([
            'correlation_id' => (string) Str::uuid(),
            'state' => $state,
            'state_changed_at' => now(),
            'message_id_sha256' => hash('sha256', (string) $email->provider_message_id),
            'inbound_email_id' => $email->getKey(),
            'sender' => (string) $email->from_address,
            'subject' => $email->subject,
            'received_at' => now(),
        ]);
    }

    protected function threadOf(InboundEmail|OutboundEmail $message): ?MailThread
    {
        $membership = MailThreadMessage::query()
            ->where($message instanceof InboundEmail ? 'inbound_email_id' : 'outbound_email_id', $message->getKey())->first();

        return $membership === null ? null : MailThread::query()->find($membership->thread_id);
    }

    /** Runs $callback with Eloquent events off, so the after-commit indexing hooks cannot fire. */
    protected function withoutIndexing(callable $callback): mixed
    {
        return Model::withoutEvents($callback);
    }
}
