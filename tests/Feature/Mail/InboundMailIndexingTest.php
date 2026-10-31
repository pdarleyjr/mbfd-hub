<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Models\InboundEmail;
use App\Models\Mail\MailThread;
use App\Models\Mail\MailThreadMessage;
use App\Services\DailyRoster\TeleStaffMailClassifier;
use App\Services\Mail\AddressParser;
use App\Services\Mail\MailText;
use App\Services\Mail\MailThreadIndexer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Feature\DailyRoster\Transport\SendsRosterMail;
use Tests\TestCase;

/** The real inbound endpoint: skipped parts, threading after commit, and the promise that indexing never fails a request. */
final class InboundMailIndexingTest extends TestCase
{
    use RefreshDatabase;
    use SendsRosterMail;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
        config()->set('daily_roster.mode', 'shadow');
    }

    /** @return array<string, mixed> */
    private function ordinary(array $overrides = []): array
    {
        return array_replace([
            'message_id' => '<ordinary.'.bin2hex(random_bytes(4)).'@sender.test>',
            'from' => 'sender@example.test',
            'to' => 'info@mbfdhub.com',
            'subject' => 'Operational question',
            'text' => 'Please review.',
            'received_at' => now()->toIso8601String(),
            'in_reply_to' => null,
            'references' => [],
            'attachments' => [],
        ], $overrides);
    }

    private function part(?string $filename, string $mime, string $bytes = 'bytes'): array
    {
        return ['filename' => $filename, 'mime_type' => $mime, 'content' => base64_encode($bytes), 'disposition' => 'inline'];
    }

    public function test_an_unsupported_or_unnamed_part_is_recorded_instead_of_bouncing_the_message(): void
    {
        $payload = $this->ordinary(['attachments' => [
            $this->part('note.txt', 'text/plain', 'keep me'),
            $this->part(null, 'image/webp', 'inline logo'),
            $this->part('invite.ics', 'text/calendar', 'BEGIN:VCALENDAR'),
            $this->part('../../escape.txt', 'text/plain', 'path in the name'),
        ]]);

        $this->deliverMail($payload)->assertCreated();

        $email = InboundEmail::query()->sole();
        self::assertCount(1, $email->attachment_metadata, 'attachment_metadata keeps only parts that were stored');
        self::assertSame('note.txt', $email->attachment_metadata[0]['filename']);
        self::assertCount(1, $this->storedAttachments());
        $skipped = json_decode((string) $email->getRawOriginal('skipped_attachments'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(
            [['image/webp', 'unsupported_type', null], ['text/calendar', 'unsupported_type', 'invite.ics'], ['text/plain', 'unusable_filename', 'escape.txt']],
            array_map(fn (array $part): array => [$part['mime_type'], $part['reason'], $part['filename']], $skipped),
        );
        self::assertArrayNotHasKey('content', $skipped[0], 'skipped bytes are never kept');
        self::assertSame(11, $skipped[0]['size']);
    }

    public function test_a_message_with_only_supported_parts_records_no_skipped_column_value(): void
    {
        $this->deliverMail($this->ordinary(['attachments' => [$this->part('note.txt', 'text/plain')]]))->assertCreated();

        self::assertNull(InboundEmail::query()->sole()->getRawOriginal('skipped_attachments'));
    }

    public function test_count_total_size_and_malformed_content_still_reject_the_whole_message(): void
    {
        $many = array_map(fn (int $i): array => $this->part("n{$i}.txt", 'text/plain'), range(1, 6));
        $this->deliverMail($this->ordinary(['attachments' => $many]))->assertStatus(422);

        config()->set('communications.inbound.max_attachment_bytes', 8);
        $this->deliverMail($this->ordinary(['attachments' => [$this->part(null, 'image/webp', 'longer than eight bytes')]]))->assertStatus(413);

        config()->set('communications.inbound.max_attachment_bytes', 2000000);
        $bad = $this->ordinary(['attachments' => [['filename' => 'a.txt', 'mime_type' => 'text/plain', 'content' => '***not base64***']]]);
        $this->deliverMail($bad)->assertStatus(422);

        self::assertSame(0, InboundEmail::query()->count());
    }

    public function test_a_roster_mail_with_an_extra_inline_part_is_still_a_roster_mail_with_exactly_one_stored_attachment(): void
    {
        $mail = $this->rosterMail();
        $mail['attachments'][] = $this->part(null, 'image/png', 'signature logo, but nameless');

        $this->deliverMail($mail)->assertCreated();

        $email = $this->inboundFor($mail['message_id']);
        self::assertCount(1, $email->attachment_metadata, 'cleanup requires exactly one entry here');
        self::assertSame('Dailyroster.xlsx', $email->attachment_metadata[0]['filename']);
        self::assertNotNull($this->ledgerFor($mail['message_id']));
        self::assertCount(1, json_decode((string) $email->getRawOriginal('skipped_attachments'), true, flags: JSON_THROW_ON_ERROR));
        $thread = MailThread::query()->sole();
        self::assertSame('automated', $thread->kind);
        self::assertTrue($thread->has_attachments, 'the workbook is a real attachment');
    }

    public function test_inbound_mail_is_threaded_after_commit_and_redelivery_adds_nothing(): void
    {
        $first = $this->ordinary(['message_id' => '<root@sender.test>']);
        $this->deliverMail($first)->assertCreated();
        $reply = $this->ordinary(['message_id' => '<reply@sender.test>', 'in_reply_to' => '<root@sender.test>', 'references' => ['<root@sender.test>'], 'subject' => 'Re: Operational question']);
        $this->deliverMail($reply)->assertCreated();
        $this->deliverMail($reply)->assertCreated(); // the Worker retried: same Message-ID, new nonce

        self::assertSame(2, InboundEmail::query()->count());
        self::assertSame(1, MailThread::query()->count());
        self::assertSame(2, MailThreadMessage::query()->count());
        self::assertSame(2, MailThread::query()->sole()->message_count);
    }

    public function test_a_failing_indexer_can_never_fail_the_inbound_request_and_logs_no_content(): void
    {
        app()->bind(MailThreadIndexer::class, fn () => throw new RuntimeException('boom for secret-subject@leak.test'));
        Log::spy();

        $this->deliverMail($this->ordinary(['subject' => 'secret-subject', 'from' => 'private.sender@leak.test']))->assertCreated();

        self::assertSame(1, InboundEmail::query()->count(), 'the message is stored even though indexing failed');
        self::assertSame(0, MailThread::query()->count());
        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context = []): bool {
            $flat = json_encode([$message, $context]);

            return $message === 'mail_thread_index_failed'
                && ! str_contains($flat, 'leak.test') && ! str_contains($flat, 'secret-subject') && ! str_contains($flat, 'boom');
        })->once();

        // The next sweep picks the message up.
        app()->bind(MailThreadIndexer::class, fn () => new MailThreadIndexer(new MailText, new AddressParser, new TeleStaffMailClassifier));
        $stats = app(\App\Services\Mail\MailIndexSweeper::class)->run();
        self::assertSame(1, $stats['indexed']);
        self::assertSame(1, MailThread::query()->count());
    }

    public function test_a_failing_indexer_does_not_break_roster_registration(): void
    {
        app()->bind(MailThreadIndexer::class, fn () => throw new RuntimeException('boom'));
        $mail = $this->rosterMail();

        $this->deliverMail($mail)->assertCreated();

        self::assertNotNull($this->ledgerFor($mail['message_id']));
        self::assertNotNull($this->inboundFor($mail['message_id']));
    }
}
