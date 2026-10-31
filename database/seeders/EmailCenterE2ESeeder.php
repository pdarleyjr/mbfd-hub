<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\InboundEmail;
use App\Models\Mail\MailThread;
use App\Models\OutboundEmail;
use App\Models\User;
use App\Services\Communications\OutboundDeliveryLedger;
use App\Services\Mail\MailboxActions;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Browser fixtures for the Email Center (tests/e2e/email-center.spec.ts). Disposable database only. Three people:
 *
 *   99871  Email Test Admin    super administrator, signs in to write, act and send
 *   99872  Email Test Viewer   panel access and admin.email.view only: reads, acts on nothing
 *   99873  Email Test Member   no panel access: must never see the Email entry
 *
 * The mailbox holds one rich conversation (HTML body, attachment, skipped part, reply with per-recipient delivery,
 * internal note, label, assignee), one conversation of very long strings, one automated message and a large
 * mailbox of plain conversations for scrolling and "show older" checks.
 */
final class EmailCenterE2ESeeder extends Seeder
{
    public const BULK = 120;

    public function run(): void
    {
        if (! app()->environment('testing') || config('database.default') !== 'sqlite'
            || basename((string) config('database.connections.sqlite.database')) !== 'email_center_e2e.sqlite') {
            throw new RuntimeException('Email Center browser fixtures require their disposable testing database.');
        }
        $password = env('EMAIL_CENTER_E2E_PASSWORD');
        if (! is_string($password) || $password === '') {
            throw new RuntimeException('The browser test password must be supplied.');
        }

        $admin = $this->person('99871', 'Email Test Admin', 'Captain', $password);
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        $viewer = $this->person('99872', 'Email Test Viewer', 'Lieutenant', $password);
        foreach (['admin.access', 'admin.email.view'] as $permission) {
            $viewer->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $this->person('99873', 'Email Test Member', 'Firefighter', $password);

        config()->set('communications.cloudflare.from_address', 'info@mbfdhub.com');
        $this->richConversation($admin);
        $this->longStrings();
        $this->automated();
        $this->bulk();
    }

    private function person(string $number, string $name, string $rank, string $password): User
    {
        $employee = Employee::query()->create([
            'employee_id' => $number, 'name' => $name, 'rank' => $rank,
            'city_email' => "emailfixture{$number}@miamibeachfl.gov", 'roster_status' => 'active',
        ]);

        return User::factory()->create([
            'name' => $name, 'rank' => $rank, 'email' => $employee->city_email, 'email_verified_at' => now(),
            'employee_id' => $employee->employee_id, 'employee_profile_id' => $employee->id,
            'password' => Hash::make($password), 'must_change_password' => false, 'account_status' => 'active',
        ]);
    }

    private function richConversation(User $admin): void
    {
        $first = InboundEmail::query()->create([
            'provider_message_id' => '<rich.1@vendor.example.test>',
            'from_address' => 'dispatch@vendor.example.test', 'from_display_name' => 'Vendor Dispatch',
            'to_address' => 'info@mbfdhub.com', 'subject' => 'Turnout gear delivery window',
            'received_at' => now()->subHours(5),
            'text_body' => "Chief,\n\nThe turnout gear ships Tuesday. Please confirm the delivery window.\n\nThanks,\nDispatch",
            'sanitized_html_body' => '<p>Chief,</p><p>The turnout gear ships <strong>Tuesday</strong>. Please confirm the delivery window.</p>'
                .'<p><a href="https://example.com/tracking">Track the shipment</a></p><blockquote>Earlier note: sizes are final.</blockquote>',
            'safe_headers' => ['to' => ['info@mbfdhub.com'], 'cc' => ['colleague@example.test'], 'message-id' => '<rich.1@vendor.example.test>'],
            'references' => [], 'processing_status' => 'received',
        ]);
        $path = 'inbound-email-attachments/'.hash('sha256', (string) $first->provider_message_id).'/packing-list.txt';
        Storage::disk('local')->put($path, "Packing list\n12 coats\n12 pants\n");
        $first->forceFill([
            'attachment_metadata' => [['filename' => 'packing-list.txt', 'mime_type' => 'text/plain', 'size' => 32, 'disk' => 'local', 'path' => $path]],
            'skipped_attachments' => [['filename' => 'logo.webp', 'mime_type' => 'image/webp', 'size' => 4096, 'reason' => 'unsupported_type']],
        ])->save();

        $reply = OutboundEmail::query()->create([
            'provider' => 'cloudflare', 'initiated_by_user_id' => $admin->id, 'source_type' => 'admin_reply',
            'from_address' => 'info@mbfdhub.com', 'to_recipients' => ['dispatch@vendor.example.test'],
            'cc_recipients' => ['colleague@example.test'], 'bcc_recipients' => ['private-copy@example.test'],
            'subject' => 'Re: Turnout gear delivery window', 'text_body' => 'Tuesday morning works for us. Please deliver to Station 1.',
            'in_reply_to' => '<rich.1@vendor.example.test>', 'references' => ['<rich.1@vendor.example.test>'],
            'parent_inbound_email_id' => $first->id, 'recipient_count' => 3, 'chargeable_budget_units' => 3, 'status' => 'delivered',
            'queued_at' => now()->subHours(4), 'submitted_at' => now()->subHours(4), 'accepted_at' => now()->subHours(4),
            'message_id' => '<rich.2@mbfdhub.com>', 'provider_message_id' => '<rich.2@mbfdhub.com>',
        ]);
        $ledger = app(OutboundDeliveryLedger::class);
        $at = CarbonImmutable::now()->subHours(3);
        $ledger->record($reply, 'dispatch@vendor.example.test', 'delivered', 'newEmailSending', $at);
        $ledger->record($reply, 'colleague@example.test', 'deferred', 'newEmailSending', $at);
        $ledger->record($reply, 'private-copy@example.test', 'delivered', 'newEmailSending', $at);

        InboundEmail::query()->create([
            'provider_message_id' => '<rich.3@vendor.example.test>',
            'from_address' => 'dispatch@vendor.example.test', 'from_display_name' => 'Vendor Dispatch',
            'to_address' => 'info@mbfdhub.com', 'subject' => 'Re: Turnout gear delivery window',
            'received_at' => now()->subHours(1), 'text_body' => 'Confirmed for Tuesday at 9 AM.',
            'in_reply_to' => '<rich.2@mbfdhub.com>', 'references' => ['<rich.1@vendor.example.test>', '<rich.2@mbfdhub.com>'],
            'safe_headers' => ['to' => ['info@mbfdhub.com'], 'cc' => [], 'message-id' => '<rich.3@vendor.example.test>'], 'processing_status' => 'received',
        ]);

        $thread = $this->threadFor($first);
        $actions = app(MailboxActions::class);
        $actions->addLabel($admin, $thread, 'Logistics');
        $actions->setPriority($admin, $thread, MailThread::PRIORITY_HIGH);
        $actions->assign($admin, $thread, $admin);
        $actions->addNote($admin, $thread, 'Internal: call the vendor if the truck is late.');
    }

    private function longStrings(): void
    {
        $subject = 'Unbroken'.str_repeat('subjectwithnospaces', 12);
        InboundEmail::query()->create([
            'provider_message_id' => '<long.1@example.test>',
            'from_address' => str_repeat('averyveryverylonglocalpart', 5).'@subdomain.example-with-a-long-name.test',
            'from_display_name' => 'Pat '.str_repeat('Hyphenated-', 12).'Name',
            'to_address' => 'info@mbfdhub.com', 'subject' => $subject, 'received_at' => now()->subHours(2),
            'text_body' => str_repeat('averyveryverylongwordthatshouldwrapinsteadofscrolling', 10)."\n\nhttps://example.test/".str_repeat('segment/', 30),
            'safe_headers' => ['to' => ['info@mbfdhub.com'], 'cc' => []], 'processing_status' => 'received',
        ]);
    }

    private function automated(): void
    {
        OutboundEmail::query()->create([
            'provider' => 'cloudflare', 'source_type' => 'new_submission', 'from_address' => 'info@mbfdhub.com',
            'to_recipients' => ['system-recipient@example.test'], 'subject' => 'New submission: take-home vehicle inspection',
            'text_body' => 'A member submitted a form. This message was sent by the Hub.', 'recipient_count' => 1, 'chargeable_budget_units' => 1,
            'status' => 'delivered', 'queued_at' => now()->subHours(6), 'accepted_at' => now()->subHours(6),
            'message_id' => '<auto.1@mbfdhub.com>', 'provider_message_id' => '<auto.1@mbfdhub.com>',
        ]);
    }

    private function bulk(): void
    {
        for ($i = 1; $i <= self::BULK; $i++) {
            InboundEmail::query()->create([
                'provider_message_id' => "<bulk.{$i}@example.test>",
                'from_address' => "member{$i}@example.test", 'from_display_name' => "Bulk Sender {$i}",
                'to_address' => 'info@mbfdhub.com', 'subject' => "Bulk conversation {$i}",
                'received_at' => now()->subDays(2)->subMinutes($i * 7),
                'text_body' => "Message number {$i} in a large mailbox.",
                'safe_headers' => ['to' => ['info@mbfdhub.com'], 'cc' => []], 'processing_status' => 'received',
            ]);
        }
    }

    private function threadFor(InboundEmail $email): MailThread
    {
        $membership = \App\Models\Mail\MailThreadMessage::query()->where('inbound_email_id', $email->getKey())->first();

        return MailThread::query()->findOrFail($membership?->thread_id);
    }
}
