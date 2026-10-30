<?php

declare(strict_types=1);

namespace Tests\Feature\DailyRoster\Pipeline;

use App\Jobs\DailyRoster\ProcessRosterImport;
use App\Models\DailyRoster\RosterEvent;
use App\Models\DailyRoster\RosterImport;
use App\Models\InboundEmail;
use App\Services\DailyRoster\RosterStateRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\DailyRoster\Pipeline\Support\InteractsWithRosterPipeline;
use Tests\TestCase;

final class DailyRosterImportInboundCommandTest extends TestCase
{
    use InteractsWithRosterPipeline, RefreshDatabase;

    private const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparePipeline();
    }

    public function test_it_refuses_without_attestation(): void
    {
        $email = $this->storedEmail();

        $this->run_('--no-attest', $email->id, $output);

        self::assertStringContainsString('refused:operator_attestation_required', $output);
        self::assertSame(0, RosterImport::query()->count());
    }

    public function test_it_refuses_an_unknown_email(): void
    {
        $exit = $this->run_('--operator-attested', 999999, $output);

        self::assertSame(1, $exit);
        self::assertStringContainsString('refused:inbound_email_not_found', $output);
    }

    public function test_it_refuses_while_the_pipeline_is_off(): void
    {
        config(['daily_roster.mode' => 'off']);

        $this->run_('--operator-attested', $this->storedEmail()->id, $output);

        self::assertStringContainsString('refused:mode_off', $output);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function badShapes(): array
    {
        return [
            'sender' => [['from_address' => 'donotreply@example.test'], 'sender_mismatch'],
            'subject' => [['subject' => 'Roster Report Fwd'], 'subject_mismatch'],
            'subject case' => [['subject' => 'roster report'], 'subject_mismatch'],
            'filename' => [['filename' => 'Other.xlsx'], 'filename_mismatch'],
            'mime' => [['mime_type' => 'text/csv'], 'attachment_mime_mismatch'],
            'no attachment' => [['attachments' => []], 'attachment_count_not_one'],
            'two attachments' => [['attachments' => 2], 'attachment_count_not_one'],
            'missing file' => [['missing_file' => true], 'source_file_missing'],
            'empty file' => [['bytes' => ''], 'attachment_empty'],
            'too large' => [['too_large' => true], 'attachment_too_large'],
        ];
    }

    /** @param array<string, mixed> $shape */
    #[DataProvider('badShapes')]
    public function test_it_refuses_mail_that_does_not_match_exactly(array $shape, string $code): void
    {
        if ($shape['too_large'] ?? false) {
            config(['daily_roster.workbook.max_bytes' => 4]);
        }

        $exit = $this->run_('--operator-attested', $this->storedEmail($shape)->id, $output);

        self::assertSame(1, $exit);
        self::assertStringContainsString('refused:'.$code, $output);
        self::assertSame(0, RosterImport::query()->count());
    }

    public function test_it_registers_the_row_audits_it_and_queues_it(): void
    {
        $email = $this->storedEmail();
        $attachment = $email->attachment_metadata[0];

        $exit = $this->run_('--operator-attested', $email->id, $output);

        self::assertSame(0, $exit);
        $import = RosterImport::query()->sole();
        self::assertStringContainsString('queued import='.$import->id.' bytes=', $output);
        self::assertSame(RosterImport::STATE_QUEUED, $import->state);
        self::assertSame(['verdict' => 'match', 'reasons' => ['operator_attested']], $import->classification);
        self::assertSame(hash('sha256', (string) $email->provider_message_id), $import->message_id_sha256);
        self::assertSame((int) $email->id, (int) $import->inbound_email_id);
        self::assertSame($attachment['path'], $import->attachment_path);
        self::assertSame('local', $import->attachment_disk);
        self::assertSame(hash('sha256', (string) Storage::disk('local')->get($attachment['path'])), $import->attachment_sha256);
        self::assertTrue($import->received_at->equalTo($email->received_at));

        self::assertSame(1, RosterEvent::query()->where('roster_import_id', $import->id)->where('action', RosterStateRecorder::ACTION_ATTESTED_IMPORT)->count());
        Bus::assertDispatched(ProcessRosterImport::class, static fn (ProcessRosterImport $job): bool => $job->importId === (int) $import->id && $job->afterCommit === true);
    }

    public function test_running_it_twice_does_nothing_new(): void
    {
        $email = $this->storedEmail();
        $this->run_('--operator-attested', $email->id, $first);
        $exit = $this->run_('--operator-attested', $email->id, $second);

        self::assertSame(0, $exit);
        self::assertStringContainsString('already_registered', $second);
        self::assertSame(1, RosterImport::query()->count());
        Bus::assertDispatchedTimes(ProcessRosterImport::class, 1);
    }

    /** @return array<string, array{string, array<string, mixed>}> */
    public static function heldByTheMailCheck(): array
    {
        return [
            'rejected' => [RosterImport::STATE_REJECTED, ['verdict' => 'rejected', 'reasons' => ['evidence_dmarc_fail'], 'evidence' => ['envelope_from' => 'a@miamibeachfl.gov']]],
            'needs review' => [RosterImport::STATE_NEEDS_REVIEW, ['verdict' => 'needs_review', 'reasons' => ['dkim_not_aligned'], 'evidence' => ['envelope_from' => 'a@miamibeachfl.gov']]],
        ];
    }

    /** @param array<string, mixed> $classification */
    #[DataProvider('heldByTheMailCheck')]
    public function test_an_already_registered_rejected_or_held_row_is_attested_and_queued(string $state, array $classification): void
    {
        $email = $this->storedEmail();
        $import = $this->registeredRow($email, $state, $classification);

        $exit = $this->run_('--operator-attested', $email->id, $output);

        self::assertSame(0, $exit);
        self::assertStringContainsString('attested import='.$import->id, $output);
        self::assertStringNotContainsString('already_registered', $output);
        $import = $import->fresh();
        self::assertSame(RosterImport::STATE_QUEUED, $import->state);
        self::assertSame('match', $import->classification['verdict']);
        self::assertSame($classification['verdict'], $import->classification['original_verdict']);
        self::assertContains('operator_attested', $import->classification['reasons']);
        self::assertSame($classification['evidence'], $import->classification['evidence']);
        Bus::assertDispatched(ProcessRosterImport::class, static fn (ProcessRosterImport $job): bool => $job->importId === (int) $import->id);
    }

    public function test_an_attested_row_must_still_match_exactly(): void
    {
        $email = $this->storedEmail(['subject' => 'Roster Report Fwd']);
        $import = $this->registeredRow($email, RosterImport::STATE_REJECTED, ['verdict' => 'rejected', 'reasons' => ['near_miss']]);

        $exit = $this->run_('--operator-attested', $email->id, $output);

        self::assertSame(1, $exit);
        self::assertStringContainsString('refused:subject_mismatch', $output);
        self::assertSame(RosterImport::STATE_REJECTED, $import->fresh()->state);
    }

    public function test_a_row_held_after_the_mail_check_passed_stays_as_it_is(): void
    {
        $email = $this->storedEmail();
        $import = $this->registeredRow($email, RosterImport::STATE_NEEDS_REVIEW, ['verdict' => 'match', 'reasons' => []]);

        $this->run_('--operator-attested', $email->id, $output);

        self::assertStringContainsString('already_registered', $output);
        self::assertSame(RosterImport::STATE_NEEDS_REVIEW, $import->fresh()->state);
        Bus::assertNotDispatched(ProcessRosterImport::class);
    }

    public function test_a_new_registration_keeps_whatever_evidence_the_email_has(): void
    {
        $email = $this->storedEmail();
        $email->forceFill(['transport_evidence' => ['envelope_from' => 'a@miamibeachfl.gov', 'authentication_results' => 'mx.cloudflare.net; dmarc=none']])->save();

        $this->run_('--operator-attested', $email->id, $output);

        self::assertSame('mx.cloudflare.net; dmarc=none', RosterImport::query()->sole()->classification['evidence']['authentication_results']);
    }

    public function test_the_output_never_names_anyone(): void
    {
        $email = $this->storedEmail(['from_name' => 'TESTPERSON, ALPHA']);

        $this->run_('--operator-attested', $email->id, $output);

        self::assertMatchesRegularExpression('/^queued import=\d+ bytes=\d+\s*$/', $output);
    }

    private function run_(string $flag, int $emailId, ?string &$output = null): int
    {
        $parameters = ['inboundEmailId' => $emailId];
        if ($flag === '--operator-attested') {
            $parameters['--operator-attested'] = true;
        }
        $exit = Artisan::call('daily-roster:import-inbound', $parameters);
        $output = Artisan::output();

        return $exit;
    }

    /** @param array<string, mixed> $classification */
    private function registeredRow(InboundEmail $email, string $state, array $classification): RosterImport
    {
        return RosterImport::query()->create([
            'correlation_id' => (string) Str::uuid(),
            'state' => $state,
            'state_changed_at' => now(),
            'message_id_sha256' => hash('sha256', (string) $email->provider_message_id),
            'inbound_email_id' => $email->id,
            'sender' => (string) $email->from_address,
            'subject' => $email->subject,
            'filename' => 'Dailyroster.xlsx',
            'attachment_disk' => 'local',
            'attachment_path' => $email->attachment_metadata[0]['path'],
            'attachment_sha256' => hash('sha256', (string) Storage::disk('local')->get($email->attachment_metadata[0]['path'])),
            'received_at' => now(),
            'classification' => $classification,
        ]);
    }

    /** @param array<string, mixed> $shape */
    private function storedEmail(array $shape = []): InboundEmail
    {
        $messageId = '<'.Str::uuid().'@example.test>';
        $path = 'inbound-email-attachments/'.hash('sha256', $messageId).'/'.Str::uuid().'.xlsx';
        $bytes = array_key_exists('bytes', $shape) ? (string) $shape['bytes'] : 'PK'.Str::random(24);
        if (! ($shape['missing_file'] ?? false)) {
            Storage::disk('local')->put($path, $bytes);
        }

        $one = [
            'filename' => $shape['filename'] ?? 'Dailyroster.xlsx',
            'mime_type' => $shape['mime_type'] ?? self::MIME,
            'size' => strlen($bytes),
            'disk' => 'local',
            'path' => $path,
        ];
        $attachments = match ($shape['attachments'] ?? 1) {
            0, [] => [],
            2 => [$one, $one],
            default => [$one],
        };

        $email = new InboundEmail;
        $email->forceFill([
            'provider_message_id' => $messageId,
            'from_address' => $shape['from_address'] ?? 'donotreply@miamibeachfl.gov',
            'from_display_name' => $shape['from_name'] ?? null,
            'to_address' => 'info@mbfdhub.com',
            'subject' => $shape['subject'] ?? 'Roster Report',
            'received_at' => now(),
            'processing_status' => 'received',
            'attachment_metadata' => $attachments,
        ])->save();

        return $email;
    }
}
