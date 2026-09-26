<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\InboundEmail;
use App\Models\OutboundEmail;
use App\Services\Communications\EmailConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class EmailConversationTest extends TestCase
{
    use RefreshDatabase;

    public function test_reply_preserves_valid_reference_chain_and_rejects_injected_ids(): void
    {
        $service = app(EmailConversation::class);
        $email = new InboundEmail(['provider_message_id' => '<third@example.test>', 'references' => ['<first@example.test>', "<bad@example.test>\r\nBCC: victim@example.test", '<second@example.test>']]);
        self::assertSame(['In-Reply-To' => '<third@example.test>', 'References' => '<first@example.test> <second@example.test> <third@example.test>'], $service->headers($email, 'reply'));
        self::assertSame([], $service->headers(new OutboundEmail(['provider_message_id' => 'opaque-provider-id']), 'reply'));
        self::assertSame([], $service->headers($email, 'forward'));
    }

    public function test_legacy_string_recipient_headers_and_reference_bytes_are_bounded(): void
    {
        $service = app(EmailConversation::class);
        $email = new InboundEmail(['from_address' => 'sender@example.test', 'provider_message_id' => '<parent@example.test>', 'safe_headers' => ['to' => 'info@mbfdhub.com', 'cc' => 'colleague@example.test, sender@example.test'], 'references' => array_map(fn ($i) => '<'.str_repeat('a', 200).$i.'@example.test>', range(1, 20))]);
        self::assertSame(['colleague@example.test'], $service->prefill($email, 'reply_all')['cc']);
        $headers = $service->headers($email, 'reply');
        self::assertLessThanOrEqual(1800, strlen($headers['References']));
        self::assertStringEndsWith('<parent@example.test>', $headers['References']);
        self::assertSame(['<a@example.test>', '<b@example.test>'], $service->references('<a@example.test> <b@example.test>'));
    }

    public function test_reply_all_deduplicates_and_excludes_hub_and_bcc(): void
    {
        config(['communications.cloudflare.from_address' => 'info@mbfdhub.com', 'communications.inbound.address' => 'inbox@mbfdhub.com']);
        $email = new OutboundEmail(['source_type' => 'admin_compose', 'to_recipients' => ['ONE@example.test', 'info@mbfdhub.com'], 'cc_recipients' => ['one@example.test', 'two@example.test', 'inbox@mbfdhub.com'], 'bcc_recipients' => ['private@example.test'], 'subject' => 'Operations', 'text_body' => 'Original']);
        $prefill = app(EmailConversation::class)->prefill($email, 'reply_all');
        self::assertSame(['one@example.test'], $prefill['to']);
        self::assertSame(['two@example.test'], $prefill['cc']);
        self::assertSame([], $prefill['bcc']);
        self::assertSame('Re: Operations', $prefill['subject']);
        self::assertStringNotContainsString('private@example.test', json_encode($prefill));
    }

    public function test_forward_has_no_recipients_or_implicitly_selected_attachments(): void
    {
        $prefill = app(EmailConversation::class)->prefill(new InboundEmail(['from_address' => 'one@example.test', 'subject' => 'Operations', 'text_body' => 'Original']), 'forward');
        self::assertSame([], $prefill['to']);
        self::assertSame([], $prefill['forward_attachments']);
        self::assertSame('Fwd: Operations', $prefill['subject']);
        self::assertStringContainsString('Original message', $prefill['text']);
    }

    public function test_reviewed_operational_notifications_can_reply_and_forward_without_opening_unknown_sources(): void
    {
        $service = app(EmailConversation::class);
        foreach ([\App\Notifications\NewSubmissionNotification::class, \App\Notifications\EquipmentDefectNotification::class] as $source) {
            $email = new OutboundEmail(['source_type' => $source, 'to_recipients' => ['member@example.test'], 'subject' => 'Operational alert', 'text_body' => 'Review the authenticated Hub resource.']);
            self::assertTrue($service->canRespond($email));
            self::assertSame(['member@example.test'], $service->prefill($email, 'reply')['to']);
            self::assertSame([], $service->prefill($email, 'forward')['to']);
        }
        foreach (['App\\Notifications\\PasswordResetNotification', 'Other\\NewSubmissionNotification', 'NewSubmissionNotification'] as $source) {
            self::assertFalse($service->canRespond(new OutboundEmail(['source_type' => $source])));
        }
    }

    public function test_sensitive_and_unknown_sources_cannot_be_replied_to_or_forwarded(): void
    {
        $service = app(EmailConversation::class);
        foreach (['password_reset', 'city_email_verification', 'member_onboarding_invitation', 'identity_recovery', 'new_unknown_source'] as $source) {
            self::assertFalse($service->canRespond(new OutboundEmail(['source_type' => $source])));
        }
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $service->prefill(new OutboundEmail(['source_type' => 'member_onboarding_invitation']), 'forward');
    }

    public function test_selected_attachment_uses_only_the_source_messages_private_storage(): void
    {
        Storage::fake('local');
        $path = 'inbound-email-attachments/'.hash('sha256', '<one@example.test>').'/note.txt';
        Storage::disk('local')->put($path, 'private note');
        $email = new InboundEmail(['provider_message_id' => '<one@example.test>', 'attachment_metadata' => [['filename' => 'note.txt', 'mime_type' => 'text/plain', 'disk' => 'local', 'path' => $path, 'size' => 12]]]);
        $service = app(EmailConversation::class);
        self::assertSame([], $service->attachments($email, []));
        self::assertSame(base64_encode('private note'), $service->attachments($email, [0])[0]['content']);
        $email->attachment_metadata = [['filename' => 'secret.txt', 'disk' => 'local', 'path' => 'secrets/secret.txt']];
        self::assertSame([], $service->attachmentOptions($email));
        $this->expectException(ValidationException::class);
        $service->attachments($email, [0]);
    }

    public function test_to_summary_is_scalar_and_never_displays_bcc(): void
    {
        $email = new OutboundEmail(['to_recipients' => ['one@example.test', 'two@example.test'], 'bcc_recipients' => ['private@example.test']]);
        self::assertIsString($email->recipient_summary);
        self::assertStringContainsString('one@example.test', $email->recipient_summary);
        self::assertStringContainsString('+1', $email->recipient_summary);
        self::assertStringNotContainsString('private', $email->recipient_summary);
    }

    public function test_historical_cloudflare_rfc_message_id_is_used_without_inventing_opaque_ids(): void
    {
        $service = app(EmailConversation::class);
        $outbound = OutboundEmail::create(['provider' => 'cloudflare', 'provider_message_id' => '<historical@example.test>', 'source_type' => 'admin_compose', 'from_address' => 'info@mbfdhub.com', 'to_recipients' => ['one@example.test'], 'subject' => 'Historical', 'recipient_count' => 1, 'chargeable_budget_units' => 1, 'status' => 'delivered']);
        self::assertSame(['In-Reply-To' => '<historical@example.test>', 'References' => '<historical@example.test>'], $service->headers($outbound, 'reply'));
        $incoming = InboundEmail::create(['provider_message_id' => '<new-reply@example.test>', 'from_address' => 'one@example.test', 'to_address' => 'info@mbfdhub.com', 'received_at' => now(), 'in_reply_to' => '<historical@example.test>']);
        $service->associateInbound($incoming);
        self::assertSame($outbound->id, $incoming->fresh()->parent_outbound_email_id);
        self::assertSame([], $service->headers(new OutboundEmail(['provider' => 'cloudflare', 'provider_message_id' => 'opaque-id']), 'reply'));
        self::assertSame([], $service->headers(new OutboundEmail(['provider' => 'unknown', 'provider_message_id' => '<untrusted@example.test>']), 'reply'));
        self::assertSame([], $service->headers(new OutboundEmail(['provider' => 'cloudflare', 'provider_message_id' => "<bad@example.test>\r\nBCC: hidden@example.test"]), 'reply'));
        $outbound->update(['provider' => 'unknown']);
        $incoming->update(['parent_outbound_email_id' => null]);
        $service->associateInbound($incoming);
        self::assertNull($incoming->fresh()->parent_outbound_email_id);
    }

    public function test_inbound_association_uses_message_ids_never_subjects(): void
    {
        $outbound = OutboundEmail::create(['provider' => 'cloudflare', 'source_type' => 'admin_compose', 'from_address' => 'info@mbfdhub.com', 'to_recipients' => ['one@example.test'], 'subject' => 'Same subject', 'recipient_count' => 1, 'chargeable_budget_units' => 1, 'status' => 'delivered', 'message_id' => '<known@example.test>']);
        $email = InboundEmail::create(['provider_message_id' => '<reply@example.test>', 'from_address' => 'one@example.test', 'to_address' => 'info@mbfdhub.com', 'subject' => 'Different subject', 'received_at' => now(), 'in_reply_to' => '<known@example.test>']);
        app(EmailConversation::class)->associateInbound($email);
        self::assertSame($outbound->id, $email->fresh()->parent_outbound_email_id);
        $unrelated = InboundEmail::create(['provider_message_id' => '<unrelated@example.test>', 'from_address' => 'two@example.test', 'to_address' => 'info@mbfdhub.com', 'subject' => 'Same subject', 'received_at' => now()]);
        app(EmailConversation::class)->associateInbound($unrelated);
        self::assertNull($unrelated->fresh()->parent_outbound_email_id);
    }
}
