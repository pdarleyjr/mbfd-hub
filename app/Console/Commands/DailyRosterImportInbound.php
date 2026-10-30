<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DailyRoster\RosterImport;
use App\Models\InboundEmail;
use App\Services\DailyRoster\RosterImportOperations;
use App\Services\DailyRoster\RosterStateRecorder;
use App\Services\DailyRoster\TeleStaffMailClassification;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Operator path for a real export already in the inbox from before the Worker carried authentication
 * results, so the classifier cannot vouch for it. The operator attests to the source, and it must still
 * match the configured sender, subject, filename and attachment shape exactly. Prints codes and counts only.
 */
final class DailyRosterImportInbound extends Command
{
    protected $signature = 'daily-roster:import-inbound
        {inboundEmailId : ID of the stored inbound email}
        {--operator-attested : The operator confirms this email is the genuine TeleStaff export}';

    protected $description = 'Queue one stored TeleStaff email, on an operator\'s attestation';

    private const SPREADSHEET_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public function handle(RosterStateRecorder $recorder, RosterImportOperations $operations): int
    {
        if (! $this->option('operator-attested')) {
            return $this->refuse('operator_attestation_required');
        }
        if (config('daily_roster.mode') === 'off') {
            return $this->refuse('mode_off');
        }

        $email = InboundEmail::query()->find((int) $this->argument('inboundEmailId'));
        if ($email === null) {
            return $this->refuse('inbound_email_not_found');
        }

        $messageHash = hash('sha256', (string) $email->provider_message_id);
        if (RosterImport::query()->where('message_id_sha256', $messageHash)->exists()) {
            return $this->alreadyRegistered($messageHash);
        }

        $problem = $this->shapeProblem($email);
        if ($problem !== null) {
            return $this->refuse($problem);
        }

        /** @var array{filename: string, disk: string, path: string} $attachment */
        $attachment = array_values($email->attachment_metadata)[0];
        $disk = Storage::disk($attachment['disk']);
        $bytes = (int) $disk->size($attachment['path']);
        $hash = hash_file('sha256', $disk->path($attachment['path']));
        if ($hash === false) {
            return $this->refuse('source_file_unreadable');
        }

        try {
            $import = DB::transaction(function () use ($recorder, $email, $messageHash, $attachment, $bytes, $hash): RosterImport {
                $import = RosterImport::query()->create([
                    'correlation_id' => (string) Str::uuid(),
                    'state' => RosterImport::STATE_CLASSIFIED,
                    'state_changed_at' => CarbonImmutable::now('UTC'),
                    'message_id_sha256' => $messageHash,
                    'inbound_email_id' => $email->getKey(),
                    'sender' => (string) $email->from_address,
                    'subject' => $email->subject,
                    'filename' => $attachment['filename'],
                    'attachment_bytes' => $bytes,
                    'attachment_sha256' => $hash,
                    'attachment_disk' => $attachment['disk'],
                    'attachment_path' => $attachment['path'],
                    'received_at' => $email->received_at,
                    'classification' => ['verdict' => TeleStaffMailClassification::MATCH, 'reasons' => ['operator_attested']],
                ]);
                $recorder->appendEvent($import, RosterStateRecorder::ACTION_ATTESTED_IMPORT, to: RosterImport::STATE_CLASSIFIED, reason: 'operator_attested');

                return $import;
            });
        } catch (UniqueConstraintViolationException) {
            return $this->alreadyRegistered($messageHash);
        }

        $operations->enqueue($import, 'operator_attested');
        $this->line(sprintf('queued import=%d bytes=%d', $import->getKey(), $bytes));

        return self::SUCCESS;
    }

    private function shapeProblem(InboundEmail $email): ?string
    {
        if (strtolower(trim((string) $email->from_address)) !== strtolower(trim((string) config('daily_roster.classifier.sender')))) {
            return 'sender_mismatch';
        }
        if ((string) $email->subject !== (string) config('daily_roster.classifier.subject')) {
            return 'subject_mismatch';
        }

        $attachments = is_array($email->attachment_metadata) ? array_values($email->attachment_metadata) : [];
        if (count($attachments) !== 1) {
            return 'attachment_count_not_one';
        }

        $attachment = $attachments[0];
        if (($attachment['filename'] ?? null) !== (string) config('daily_roster.classifier.filename')) {
            return 'filename_mismatch';
        }
        if (strtolower((string) ($attachment['mime_type'] ?? '')) !== self::SPREADSHEET_MIME) {
            return 'attachment_mime_mismatch';
        }

        return $this->storedFileProblem((string) ($attachment['disk'] ?? ''), (string) ($attachment['path'] ?? ''));
    }

    private function storedFileProblem(string $disk, string $path): ?string
    {
        if ($disk === '' || $path === '') {
            return 'source_file_missing';
        }

        try {
            $storage = Storage::disk($disk);
            if (! $storage->exists($path)) {
                return 'source_file_missing';
            }
            $bytes = (int) $storage->size($path);
            if ($bytes < 1) {
                return 'attachment_empty';
            }

            return $bytes > (int) config('daily_roster.workbook.max_bytes') ? 'attachment_too_large' : null;
        } catch (Throwable) {
            return 'source_file_unreadable';
        }
    }

    private function alreadyRegistered(string $messageHash): int
    {
        $import = RosterImport::query()->where('message_id_sha256', $messageHash)->firstOrFail();
        $this->line(sprintf('already_registered import=%d state=%s', $import->getKey(), $import->state));

        return self::SUCCESS;
    }

    private function refuse(string $code): int
    {
        $this->error('refused:'.$code);

        return self::FAILURE;
    }
}
