<?php

declare(strict_types=1);

namespace Tests\Feature\DailyRoster\Transport;

use App\Filament\Resources\InboundEmailResource;
use App\Models\DailyRoster\RosterActiveVersion;
use App\Models\DailyRoster\RosterImport;
use App\Models\DailyRoster\RosterSnapshot;
use App\Models\InboundEmail;
use App\Services\DailyRoster\RosterImportCleanup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\DailyRosterSnapshotFactory;
use Tests\TestCase;

final class RosterImportCleanupTest extends TestCase
{
    use RefreshDatabase;
    use SendsRosterMail;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Queue::fake();
        Exceptions::fake();
        config()->set('daily_roster.mode', 'publish');
        config()->set('daily_roster.cleanup_enabled', true);
    }

    public function test_cleanup_deletes_only_that_email_and_file_and_the_ledger_survives(): void
    {
        $other = $this->rosterMail(['from' => 'vendor@example.test', 'subject' => 'Quote', 'attachments' => [$this->workbookAttachment('quote.xlsx')]]);
        $this->deliverMail($other)->assertCreated();
        $held = $this->rosterMail(['authentication_evidence' => null]);
        $this->deliverMail($held)->assertCreated();
        $import = $this->verifiedImport();
        $path = $import->attachment_path;

        $this->cleanup($import);

        $import->refresh();
        self::assertSame(RosterImport::STATE_CLEANED, $import->state);
        self::assertSame(RosterImport::CLEANUP_DONE, $import->cleanup_state);
        self::assertNotNull($import->cleaned_at);
        self::assertNull($import->inbound_email_id);
        self::assertSame(1, $import->cleanup_attempts);
        Storage::disk('local')->assertMissing($path);

        $otherEmail = $this->inboundFor($other['message_id']);
        $heldEmail = $this->inboundFor($held['message_id']);
        self::assertNotNull($otherEmail);
        self::assertNotNull($heldEmail);
        Storage::disk('local')->assertExists($otherEmail->attachment_metadata[0]['path']);
        Storage::disk('local')->assertExists($heldEmail->attachment_metadata[0]['path']);
        $this->assertDatabaseCount('inbound_emails', 2);
        $this->assertDatabaseCount('roster_imports', 2);
    }

    public function test_running_again_after_success_changes_nothing(): void
    {
        $import = $this->verifiedImport();

        $this->cleanup($import);
        $this->cleanup($import);

        $import->refresh();
        self::assertSame(RosterImport::STATE_CLEANED, $import->state);
        self::assertSame(1, $import->cleanup_attempts);
    }

    public function test_a_replied_email_is_kept_and_its_state_stays(): void
    {
        $import = $this->verifiedImport();
        $this->addReply($import->inbound_email_id);

        $this->cleanup($import);

        $import->refresh();
        self::assertSame(RosterImport::STATE_VERIFIED, $import->state);
        self::assertSame(RosterImport::CLEANUP_KEPT_REPLIED, $import->cleanup_state);
        self::assertNotNull($import->inbound_email_id);
        $this->assertDatabaseCount('inbound_emails', 1);
        Storage::disk('local')->assertExists($import->attachment_path);
    }

    public function test_an_already_missing_file_counts_as_deleted(): void
    {
        $import = $this->verifiedImport();
        Storage::disk('local')->delete($import->attachment_path);

        $this->cleanup($import);

        $import->refresh();
        self::assertSame(RosterImport::STATE_CLEANED, $import->state);
        $this->assertDatabaseCount('inbound_emails', 0);
    }

    public function test_a_crash_after_the_row_was_deleted_finishes_on_the_next_run(): void
    {
        $import = $this->verifiedImport();
        InboundEmail::query()->whereKey($import->inbound_email_id)->delete();
        $import->refresh()->forceFill(['cleanup_state' => RosterImport::CLEANUP_ROW_DELETED, 'inbound_email_id' => null])->save();

        $this->cleanup($import);

        $import->refresh();
        self::assertSame(RosterImport::STATE_CLEANED, $import->state);
        self::assertSame(RosterImport::CLEANUP_DONE, $import->cleanup_state);
        Storage::disk('local')->assertMissing($import->attachment_path);
    }

    public function test_a_crash_after_the_file_was_deleted_finishes_on_the_next_run(): void
    {
        $import = $this->verifiedImport();
        InboundEmail::query()->whereKey($import->inbound_email_id)->delete();
        Storage::disk('local')->delete($import->attachment_path);
        $import->refresh()->forceFill(['cleanup_state' => RosterImport::CLEANUP_ROW_DELETED, 'inbound_email_id' => null])->save();

        $this->cleanup($import);

        self::assertSame(RosterImport::STATE_CLEANED, $import->refresh()->state);
    }

    public function test_a_crash_while_pending_re_runs_from_the_start(): void
    {
        $import = $this->verifiedImport();
        $import->forceFill(['cleanup_state' => RosterImport::CLEANUP_PENDING])->save();

        $this->cleanup($import);

        self::assertSame(RosterImport::STATE_CLEANED, $import->refresh()->state);
        $this->assertDatabaseCount('inbound_emails', 0);
    }

    /** @return array<string, array{string, bool}> */
    public static function disabledFlags(): array
    {
        return [
            'shadow mode' => ['shadow', true],
            'off mode' => ['off', true],
            'cleanup flag off' => ['publish', false],
        ];
    }

    #[DataProvider('disabledFlags')]
    public function test_cleanup_is_refused_unless_both_flags_allow_it(string $mode, bool $enabled): void
    {
        $import = $this->verifiedImport();
        config()->set('daily_roster.mode', $mode);
        config()->set('daily_roster.cleanup_enabled', $enabled);

        $this->cleanup($import);

        $this->assertUntouched($import);
        self::assertSame(0, $import->refresh()->cleanup_attempts);
    }

    /** @return array<string, array{string}> */
    public static function ineligibleStates(): array
    {
        return [
            'needs review' => [RosterImport::STATE_NEEDS_REVIEW],
            'rejected' => [RosterImport::STATE_REJECTED],
            'retryable failure' => [RosterImport::STATE_RETRYABLE_FAILED],
            'permanent failure' => [RosterImport::STATE_PERMANENT_FAILED],
            'shadowed' => [RosterImport::STATE_SHADOWED],
            'published but unverified' => [RosterImport::STATE_PUBLISHED],
            'validated' => [RosterImport::STATE_VALIDATED],
            'queued' => [RosterImport::STATE_QUEUED],
        ];
    }

    #[DataProvider('ineligibleStates')]
    public function test_cleanup_is_refused_for_every_other_state(string $state): void
    {
        $import = $this->verifiedImport();
        $import->forceFill(['state' => $state])->save();

        $this->cleanup($import);

        $this->assertUntouched($import);
        self::assertSame($state, $import->refresh()->state);
    }

    public function test_cleanup_is_refused_when_the_message_hash_differs(): void
    {
        $import = $this->verifiedImport();
        $import->forceFill(['message_id_sha256' => str_repeat('a', 64)])->save();

        $this->cleanup($import);

        $this->assertUntouched($import);
        self::assertSame(1, $import->refresh()->cleanup_attempts);
    }

    public function test_cleanup_is_refused_when_the_file_no_longer_matches_the_ledger(): void
    {
        $import = $this->verifiedImport();
        Storage::disk('local')->put($import->attachment_path, "PK\x03\x04different-bytes");

        $this->cleanup($import);

        $this->assertUntouched($import);
    }

    public function test_cleanup_never_deletes_a_path_outside_this_messages_directory(): void
    {
        $import = $this->verifiedImport();
        $foreign = 'inbound-email-attachments/'.str_repeat('b', 64).'/other.xlsx';
        Storage::disk('local')->put($foreign, 'someone else');
        $import->forceFill(['attachment_path' => $foreign])->save();

        $this->cleanup($import);

        $this->assertDatabaseCount('inbound_emails', 1);
        Storage::disk('local')->assertExists($foreign);
        self::assertNotSame(RosterImport::STATE_CLEANED, $import->refresh()->state);
    }

    public function test_repeated_refusal_ends_failed_and_reports_a_code_only_exception(): void
    {
        config()->set('daily_roster.processing.max_cleanup_attempts', 2);
        $import = $this->verifiedImport();
        $import->forceFill(['attachment_sha256' => str_repeat('c', 64)])->save();

        $this->cleanup($import);
        self::assertSame(RosterImport::CLEANUP_PENDING, $import->refresh()->cleanup_state);
        Exceptions::assertNothingReported();

        $this->cleanup($import);
        $this->cleanup($import);

        $import->refresh();
        self::assertSame(RosterImport::CLEANUP_FAILED, $import->cleanup_state);
        self::assertSame(2, $import->cleanup_attempts);
        self::assertSame(RosterImport::STATE_VERIFIED, $import->state);
        Exceptions::assertReported(static fn (RuntimeException $exception): bool => $exception->getMessage() === 'roster_cleanup_failed: cleanup_attachment_hash_mismatch');
        $this->assertDatabaseCount('inbound_emails', 1);
    }

    public function test_a_duplicate_of_a_verified_or_cleaned_import_is_cleaned(): void
    {
        foreach ([RosterImport::STATE_VERIFIED, RosterImport::STATE_CLEANED] as $targetState) {
            $target = $this->verifiedImport();
            $target->forceFill(['state' => $targetState])->save();
            $import = $this->verifiedImport();
            $import->forceFill([
                'state' => RosterImport::STATE_DUPLICATE,
                'outcome' => RosterImport::OUTCOME_DUPLICATE,
                'duplicate_of_import_id' => $target->id,
            ])->save();

            $this->cleanup($import);

            self::assertSame(RosterImport::STATE_CLEANED, $import->refresh()->state);
            self::assertSame(RosterImport::OUTCOME_DUPLICATE, $import->outcome);
        }
    }

    public function test_a_duplicate_of_an_unverified_import_is_refused(): void
    {
        $target = $this->verifiedImport();
        $target->forceFill(['state' => RosterImport::STATE_PUBLISHED])->save();
        $import = $this->verifiedImport();
        $import->forceFill(['state' => RosterImport::STATE_DUPLICATE, 'duplicate_of_import_id' => $target->id])->save();

        $this->cleanup($import);

        $this->assertUntouched($import);
    }

    public function test_a_confirmed_duplicate_needs_a_verified_pointer(): void
    {
        $dutyDate = '2026-10-20';
        $import = $this->verifiedImport();
        $import->forceFill([
            'state' => RosterImport::STATE_DUPLICATE,
            'outcome' => RosterImport::OUTCOME_CONFIRMED,
            'duty_date' => $dutyDate,
        ])->save();

        $this->cleanup($import);
        $this->assertUntouched($import);

        DailyRosterSnapshotFactory::publish($dutyDate, []);
        self::assertTrue(RosterActiveVersion::query()->whereDate('duty_date', $dutyDate)->exists());

        $this->cleanup($import);
        self::assertSame(RosterImport::STATE_CLEANED, $import->refresh()->state);
        self::assertSame(RosterImport::OUTCOME_CONFIRMED, $import->outcome);
    }

    public function test_a_superseded_import_needs_its_snapshot_row(): void
    {
        $import = $this->verifiedImport();
        $import->forceFill(['state' => RosterImport::STATE_SUPERSEDED, 'outcome' => RosterImport::OUTCOME_SUPERSEDED])->save();

        $this->cleanup($import);
        $this->assertUntouched($import);

        $snapshot = DailyRosterSnapshotFactory::publish('2026-10-21', [], ['activate' => false]);
        self::assertInstanceOf(RosterSnapshot::class, $snapshot);
        $import->forceFill(['roster_snapshot_id' => $snapshot->id])->save();

        $this->cleanup($import);
        self::assertSame(RosterImport::STATE_CLEANED, $import->refresh()->state);
    }

    public function test_cleaned_mail_redelivered_end_to_end_is_acknowledged_and_not_stored(): void
    {
        $mail = $this->rosterMail();
        $this->deliverMail($mail)->assertCreated();
        $import = $this->ledgerFor($mail['message_id']);
        $import->forceFill(['state' => RosterImport::STATE_VERIFIED])->save();
        $this->cleanup($import);

        $this->deliverMail($mail)->assertCreated()->assertExactJson(['id' => null]);

        $this->assertDatabaseCount('inbound_emails', 0);
        $this->assertDatabaseCount('roster_imports', 1);
        self::assertSame([], $this->storedAttachments());
    }

    public function test_the_admin_inbox_still_cannot_delete_mail(): void
    {
        $import = $this->verifiedImport();

        self::assertFalse(InboundEmailResource::canDelete($import->inboundEmail));
        self::assertFalse(InboundEmailResource::canDeleteAny());
    }

    private function verifiedImport(): RosterImport
    {
        $mail = $this->rosterMail();
        $this->deliverMail($mail)->assertCreated();
        $import = $this->ledgerFor($mail['message_id']);
        $import->forceFill(['state' => RosterImport::STATE_VERIFIED, 'outcome' => RosterImport::OUTCOME_PUBLISHED])->save();

        return $import;
    }

    private function cleanup(RosterImport $import): void
    {
        app(RosterImportCleanup::class)->run($import);
    }

    private function assertUntouched(RosterImport $import): void
    {
        $import->refresh();
        self::assertNotNull($import->inbound_email_id);
        self::assertNotSame(RosterImport::STATE_CLEANED, $import->state);
        self::assertNull($import->cleaned_at);
        self::assertTrue(InboundEmail::query()->whereKey($import->inbound_email_id)->exists());
        Storage::disk('local')->assertExists($import->attachment_path);
    }

    private function addReply(int $inboundEmailId): void
    {
        $now = now();
        DB::table('outbound_emails')->insert(array_merge(
            $this->outboundDefaults(),
            ['parent_inbound_email_id' => $inboundEmailId, 'created_at' => $now, 'updated_at' => $now],
        ));
    }

    /** @return array<string, mixed> */
    private function outboundDefaults(): array
    {
        return [
            'source_type' => 'admin_reply',
            'provider' => 'cloudflare',
            'from_address' => 'info@mbfdhub.com',
            'to_recipients' => json_encode(['sender@example.test']),
            'subject' => 'Re: Roster Report',
            'recipient_count' => 1,
            'chargeable_budget_units' => 1,
            'status' => 'sent',
        ];
    }
}
