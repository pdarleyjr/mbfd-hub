<?php

declare(strict_types=1);

namespace Tests\Feature\DailyRoster\Transport;

use App\Jobs\DailyRoster\ProcessRosterImport;
use App\Models\DailyRoster\RosterEvent;
use App\Models\DailyRoster\RosterImport;
use App\Models\InboundEmail;
use App\Services\DailyRoster\RosterImportRegistrar;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class RosterInboundTransportTest extends TestCase
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

    public function test_ordinary_mail_keeps_the_exact_response_and_writes_no_ledger_row(): void
    {
        $payload = [
            'message_id' => '<ordinary@sample.test>',
            'from' => 'sender@example.test',
            'to' => 'info@mbfdhub.com',
            'subject' => 'Operational question',
            'text' => 'Please review.',
            'received_at' => now()->toIso8601String(),
            'attachments' => [[
                'filename' => 'note.txt',
                'mime_type' => 'text/plain',
                'content' => base64_encode('attachment body'),
            ]],
        ];

        $response = $this->deliverMail($payload)->assertCreated();

        $email = InboundEmail::query()->sole();
        self::assertSame(['id' => $email->id], $response->json());
        self::assertSame('Please review.', $email->text_body);
        self::assertNull($email->transport_evidence);
        self::assertCount(1, $email->attachment_metadata);
        Storage::disk('local')->assertExists($email->attachment_metadata[0]['path']);
        $this->assertDatabaseCount('roster_imports', 0);
        $this->assertDatabaseCount('roster_events', 0);
        Queue::assertNothingPushed();
    }

    public function test_other_spreadsheet_mail_from_outside_is_untouched(): void
    {
        $this->deliverMail($this->rosterMail([
            'from' => 'vendor@example.test',
            'subject' => 'Quote',
            'attachments' => [$this->workbookAttachment('quote.xlsx')],
        ]))->assertCreated();

        self::assertCount(1, $this->storedAttachments());
        $this->assertDatabaseCount('roster_imports', 0);
        Queue::assertNothingPushed();
    }

    public function test_same_domain_staff_spreadsheet_under_another_name_stays_ordinary(): void
    {
        $this->deliverMail($this->rosterMail([
            'from' => 'staff.member@miamibeachfl.gov',
            'envelope_from' => 'staff.member@miamibeachfl.gov',
            'subject' => 'Re: Test hub email',
            'in_reply_to' => '<earlier@sample.test>',
            'attachments' => [$this->workbookAttachment('budget.xlsx')],
        ]))->assertCreated();

        $this->assertDatabaseCount('inbound_emails', 1);
        $this->assertDatabaseCount('roster_imports', 0);
        Queue::assertNothingPushed();
    }

    public function test_match_creates_a_queued_ledger_row_and_dispatches_once(): void
    {
        $mail = $this->rosterMail();

        $response = $this->deliverMail($mail)->assertCreated();

        $email = $this->inboundFor($mail['message_id']);
        $import = $this->ledgerFor($mail['message_id']);
        self::assertSame(['id' => $email->id], $response->json());
        self::assertSame(RosterImport::STATE_QUEUED, $import->state);
        self::assertSame($email->id, $import->inbound_email_id);
        self::assertSame('donotreply@miamibeachfl.gov', $import->sender);
        self::assertSame('Roster Report', $import->subject);
        self::assertSame('Dailyroster.xlsx', $import->filename);
        $bytes = "PK\x03\x04synthetic-workbook";
        self::assertSame(strlen($bytes), $import->attachment_bytes);
        self::assertSame(hash('sha256', $bytes), $import->attachment_sha256);
        self::assertSame('local', $import->attachment_disk);
        self::assertSame($email->attachment_metadata[0]['path'], $import->attachment_path);
        self::assertSame('match', $import->classification['verdict']);
        self::assertNotNull($import->last_dispatched_at);
        self::assertTrue(RosterEvent::query()->where('roster_import_id', $import->id)->where('action', 'registered')->exists());
        Queue::assertPushed(ProcessRosterImport::class, 1);
        Queue::assertPushed(ProcessRosterImport::class, static fn (ProcessRosterImport $job): bool => $job->importId === $import->id);
    }

    public function test_dispatch_waits_for_the_commit_and_never_happens_on_rollback(): void
    {
        $mail = $this->rosterMail();
        $this->deliverMail($mail)->assertCreated();
        $email = $this->inboundFor($mail['message_id']);
        RosterImport::query()->delete();
        Queue::fake();

        try {
            DB::transaction(function () use ($email): void {
                app(RosterImportRegistrar::class)->register($email);
                Queue::assertNothingPushed();

                throw new RuntimeException('rollback');
            });
            self::fail('The transaction should have thrown.');
        } catch (RuntimeException $exception) {
            self::assertSame('rollback', $exception->getMessage());
        }

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('roster_imports', 0);
    }

    public function test_a_failed_dispatch_keeps_the_committed_mail_and_attachment_for_the_reconciler(): void
    {
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->andThrow(new RuntimeException('queue is down'));
        $this->app->instance(Dispatcher::class, $dispatcher);
        $mail = $this->rosterMail();

        $this->deliverMail($mail)->assertCreated();

        $email = $this->inboundFor($mail['message_id']);
        self::assertNotNull($email);
        Storage::disk('local')->assertExists($email->attachment_metadata[0]['path']);
        self::assertNotNull($this->ledgerFor($mail['message_id']));
    }

    public function test_mode_off_is_exactly_the_previous_behaviour(): void
    {
        config()->set('daily_roster.mode', 'off');
        $mail = $this->rosterMail();

        $this->deliverMail($mail)->assertCreated();

        self::assertCount(1, $this->storedAttachments());
        self::assertNotNull($this->inboundFor($mail['message_id']));
        $this->assertDatabaseCount('roster_imports', 0);
        $this->assertDatabaseCount('roster_events', 0);
        Queue::assertNothingPushed();
    }

    public function test_invalid_signature_expired_timestamp_and_replayed_nonce_are_still_rejected(): void
    {
        $mail = $this->rosterMail();

        $this->deliverMail($mail, secret: 'wrong-secret')->assertForbidden();
        $this->deliverMail($mail, timestamp: now()->subHour()->timestamp)->assertForbidden();
        $this->assertDatabaseCount('inbound_emails', 0);
        $this->assertDatabaseCount('roster_imports', 0);

        $this->deliverMail($mail, nonce: 'single-use')->assertCreated();
        $this->deliverMail($mail, nonce: 'single-use')->assertConflict();
        $this->assertDatabaseCount('inbound_emails', 1);
        $this->assertDatabaseCount('roster_imports', 1);
    }

    public function test_duplicate_delivery_of_the_same_message_creates_one_row_and_one_job(): void
    {
        $mail = $this->rosterMail();

        $first = $this->deliverMail($mail)->assertCreated();
        $second = $this->deliverMail($mail)->assertCreated();

        self::assertSame($first->json('id'), $second->json('id'));
        $this->assertDatabaseCount('inbound_emails', 1);
        $this->assertDatabaseCount('roster_imports', 1);
        self::assertCount(1, $this->storedAttachments());
        Queue::assertPushed(ProcessRosterImport::class, 1);
    }

    public function test_spoofed_from_with_failing_evidence_is_rejected_and_stays_in_the_inbox(): void
    {
        $mail = $this->rosterMail([
            'envelope_from' => 'attacker@example.test',
            'authentication_evidence' => [
                'authentication_results' => 'mx.cloudflare.net; dkim=none; spf=fail smtp.mailfrom=attacker@example.test; dmarc=fail header.from=miamibeachfl.gov',
            ],
        ]);

        $this->deliverMail($mail)->assertCreated();

        $import = $this->ledgerFor($mail['message_id']);
        self::assertSame(RosterImport::STATE_REJECTED, $import->state);
        self::assertSame('rejected', $import->classification['verdict']);
        self::assertContains('evidence_dmarc_fail', $import->classification['reasons']);
        self::assertNotNull($this->inboundFor($mail['message_id']));
        Queue::assertNothingPushed();
    }

    public function test_missing_evidence_is_held_for_review(): void
    {
        $mail = $this->rosterMail();
        unset($mail['envelope_from'], $mail['authentication_evidence']);

        $this->deliverMail($mail)->assertCreated();

        $import = $this->ledgerFor($mail['message_id']);
        self::assertSame(RosterImport::STATE_NEEDS_REVIEW, $import->state);
        self::assertSame(['authentication_results_missing', 'arc_authentication_results_missing'], $import->classification['reasons']);
        self::assertNull($this->inboundFor($mail['message_id'])->transport_evidence);
        Queue::assertNothingPushed();
    }

    public function test_malformed_evidence_is_held_for_review(): void
    {
        $mail = $this->rosterMail([
            'authentication_evidence' => ['authentication_results' => 'mx.cloudflare.net; dmarc=pass (unbalanced', 'arc_authentication_results' => ['not', 'a', 'string']],
        ]);

        $this->deliverMail($mail)->assertCreated();

        $import = $this->ledgerFor($mail['message_id']);
        self::assertSame(RosterImport::STATE_NEEDS_REVIEW, $import->state);
        self::assertSame(['authentication_results_unparseable', 'arc_authentication_results_missing'], $import->classification['reasons']);
        Queue::assertNothingPushed();
    }

    public function test_transport_evidence_is_stored_and_a_value_over_its_cap_stays_null(): void
    {
        $mail = $this->rosterMail([
            'authentication_evidence' => [
                'authentication_results' => self::PASSING_RESULTS,
                'arc_authentication_results' => str_repeat('x', 2049),
                'received_spf' => "pass\u{0007} (mx.cloudflare.net)",
            ],
        ]);

        $this->deliverMail($mail)->assertCreated();

        self::assertSame([
            'envelope_from' => 'donotreply@miamibeachfl.gov',
            'authentication_results' => self::PASSING_RESULTS,
            'arc_authentication_results' => null,
            'received_spf' => 'pass  (mx.cloudflare.net)',
        ], $this->inboundFor($mail['message_id'])->transport_evidence);
    }

    public function test_a_redelivery_after_cleanup_is_acknowledged_and_stores_nothing(): void
    {
        $mail = $this->rosterMail();
        $this->deliverMail($mail)->assertCreated();
        $email = $this->inboundFor($mail['message_id']);
        Storage::disk('local')->delete($email->attachment_metadata[0]['path']);
        $email->delete();
        $this->ledgerFor($mail['message_id'])->forceFill([
            'state' => RosterImport::STATE_CLEANED,
            'cleanup_state' => RosterImport::CLEANUP_DONE,
            'cleaned_at' => now(),
        ])->save();
        Queue::fake();

        $response = $this->deliverMail($mail, nonce: 'redelivery')->assertCreated();

        self::assertSame(['id' => null], $response->json());
        $this->assertDatabaseCount('inbound_emails', 0);
        $this->assertDatabaseCount('roster_imports', 1);
        self::assertSame([], $this->storedAttachments());
        Queue::assertNothingPushed();

        $this->deliverMail($mail, nonce: 'redelivery')->assertConflict();
    }

    public function test_a_redelivery_while_cleanup_is_finishing_does_not_bring_the_mail_back(): void
    {
        $mail = $this->rosterMail();
        $this->deliverMail($mail)->assertCreated();
        $email = $this->inboundFor($mail['message_id']);
        $email->delete();
        $this->ledgerFor($mail['message_id'])->forceFill([
            'state' => RosterImport::STATE_VERIFIED,
            'cleanup_state' => RosterImport::CLEANUP_ROW_DELETED,
        ])->save();

        $this->deliverMail($mail)->assertCreated()->assertExactJson(['id' => null]);

        $this->assertDatabaseCount('inbound_emails', 0);
    }

    public function test_a_failure_while_registering_a_match_rolls_back_so_the_worker_retries(): void
    {
        Schema::drop('roster_events');
        $mail = $this->rosterMail();

        $this->deliverMail($mail)->assertServerError();

        self::assertNull($this->inboundFor($mail['message_id']));
        self::assertSame([], $this->storedAttachments());
    }

    public function test_ordinary_mail_never_touches_the_ledger_write_path(): void
    {
        Schema::drop('roster_events');

        $this->deliverMail([
            'message_id' => '<ordinary-safe@sample.test>',
            'from' => 'sender@example.test',
            'to' => 'info@mbfdhub.com',
            'subject' => 'Hello',
            'received_at' => now()->toIso8601String(),
        ])->assertCreated();

        self::assertNotNull($this->inboundFor('<ordinary-safe@sample.test>'));
    }

    public function test_attachment_limits_still_apply_before_any_classification(): void
    {
        config()->set('communications.inbound.max_attachment_bytes', 4);
        $mail = $this->rosterMail();

        $this->deliverMail($mail)->assertStatus(413);

        $this->assertDatabaseCount('inbound_emails', 0);
        $this->assertDatabaseCount('roster_imports', 0);
        self::assertSame([], $this->storedAttachments());
    }

    public function test_a_workbook_over_the_roster_limit_is_stored_but_held(): void
    {
        config()->set('daily_roster.workbook.max_bytes', 4);
        $mail = $this->rosterMail();

        $this->deliverMail($mail)->assertCreated();

        $import = $this->ledgerFor($mail['message_id']);
        self::assertSame(RosterImport::STATE_NEEDS_REVIEW, $import->state);
        self::assertSame(['attachment_too_large'], $import->classification['reasons']);
        self::assertCount(1, $this->storedAttachments());
    }
}
