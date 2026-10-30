<?php

declare(strict_types=1);

namespace App\Services\DailyRoster;

use App\Jobs\DailyRoster\ProcessRosterImport;
use App\Models\DailyRoster\RosterImport;
use App\Models\InboundEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Writes the ledger row for mail the classifier recognises, inside the inbound transaction.
 * Ordinary mail writes nothing. A lost dispatch is repaired by daily-roster:reconcile.
 */
final class RosterImportRegistrar
{
    public function __construct(private readonly TeleStaffMailClassifier $classifier) {}

    public function register(InboundEmail $email): ?RosterImport
    {
        if (! self::isActive()) {
            return null;
        }

        $classification = $this->classifier->classify($email);
        if ($classification->isOrdinary()) {
            return null;
        }

        $messageHash = hash('sha256', (string) $email->provider_message_id);
        $attachment = $this->describeAttachment($email, $classification->isMatch());
        $state = match ($classification->verdict) {
            TeleStaffMailClassification::MATCH => RosterImport::STATE_CLASSIFIED,
            TeleStaffMailClassification::NEEDS_REVIEW => RosterImport::STATE_NEEDS_REVIEW,
            default => RosterImport::STATE_REJECTED,
        };
        $now = now();

        $inserted = DB::table('roster_imports')->insertOrIgnore([
            'correlation_id' => (string) Str::uuid(),
            'state' => $state,
            'state_changed_at' => $now,
            'message_id_sha256' => $messageHash,
            'inbound_email_id' => $email->getKey(),
            'sender' => Str::limit((string) $email->from_address, 320, ''),
            'subject' => $email->subject === null ? null : Str::limit((string) $email->subject, 998, ''),
            'filename' => $attachment['filename'],
            'attachment_bytes' => $attachment['bytes'],
            'attachment_sha256' => $attachment['sha256'],
            'attachment_disk' => $attachment['disk'],
            'attachment_path' => $attachment['path'],
            'received_at' => $email->received_at,
            'classification' => json_encode($classification->toLedger(), JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $import = RosterImport::query()->where('message_id_sha256', $messageHash)->firstOrFail();
        if ($inserted === 0) {
            return $import;
        }

        app(RosterStateRecorder::class)->appendEvent($import, 'registered', null, $state, null, [
            'verdict' => $classification->verdict,
            'reasons' => $classification->reasons,
        ]);

        if ($classification->isMatch()) {
            $this->queueAfterCommit((int) $import->getKey());
        }

        return $import;
    }

    /** True once the email is gone, so a redelivery must not bring it back. */
    public function isCleanedRedelivery(string $messageId): bool
    {
        if (! self::isActive()) {
            return false;
        }

        return RosterImport::query()
            ->where('message_id_sha256', hash('sha256', $messageId))
            ->where(static fn ($query) => $query
                ->where('state', RosterImport::STATE_CLEANED)
                ->orWhereIn('cleanup_state', [RosterImport::CLEANUP_ROW_DELETED, RosterImport::CLEANUP_DONE]))
            ->exists();
    }

    private static function isActive(): bool
    {
        return in_array(config('daily_roster.mode'), ['shadow', 'publish'], true);
    }

    /**
     * Runs after the mail is durable. It must not throw: a failure here cannot unwind the commit,
     * and the caller would then treat stored mail as lost. The reconciler picks the row up instead.
     */
    private function queueAfterCommit(int $importId): void
    {
        DB::afterCommit(static function () use ($importId): void {
            try {
                $import = RosterImport::query()->find($importId);
                if ($import?->state !== RosterImport::STATE_CLASSIFIED) {
                    return;
                }

                app(RosterStateRecorder::class)->transition($import, RosterImport::STATE_QUEUED, ['last_dispatched_at' => now()]);
                ProcessRosterImport::dispatch($importId)->afterCommit();
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    /** @return array{filename: ?string, bytes: ?int, sha256: ?string, disk: ?string, path: ?string} */
    private function describeAttachment(InboundEmail $email, bool $mustBeReadable): array
    {
        $attachment = $this->primaryAttachment($email);
        if ($attachment === null) {
            return ['filename' => null, 'bytes' => null, 'sha256' => null, 'disk' => null, 'path' => null];
        }

        $disk = (string) ($attachment['disk'] ?? '');
        $path = (string) ($attachment['path'] ?? '');
        [$bytes, $sha256] = $this->measure($disk, $path);
        if ($mustBeReadable && $sha256 === null) {
            throw new RuntimeException('roster_attachment_unreadable');
        }

        return [
            'filename' => Str::limit((string) ($attachment['filename'] ?? ''), 255, '') ?: null,
            'bytes' => $bytes,
            'sha256' => $sha256,
            'disk' => $disk === '' ? null : Str::limit($disk, 32, ''),
            'path' => $path === '' ? null : Str::limit($path, 255, ''),
        ];
    }

    /** @return array<string, mixed>|null The attachment carrying the configured name, else the first. */
    private function primaryAttachment(InboundEmail $email): ?array
    {
        $attachments = $email->attachment_metadata ?? [];
        $expected = mb_strtolower((string) config('daily_roster.classifier.filename'));
        foreach ($attachments as $attachment) {
            if (mb_strtolower($attachment['filename']) === $expected) {
                return $attachment;
            }
        }

        return $attachments[0] ?? null;
    }

    /** @return array{0: ?int, 1: ?string} Size and hash of what is actually on disk. */
    private function measure(string $disk, string $path): array
    {
        if ($disk === '' || $path === '') {
            return [null, null];
        }

        try {
            $stream = Storage::disk($disk)->readStream($path);
            if (! is_resource($stream)) {
                return [null, null];
            }
            $context = hash_init('sha256');
            $bytes = hash_update_stream($context, $stream);
            fclose($stream);

            return [$bytes, hash_final($context)];
        } catch (Throwable) {
            return [null, null];
        }
    }
}
