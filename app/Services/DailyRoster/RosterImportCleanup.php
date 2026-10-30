<?php

declare(strict_types=1);

namespace App\Services\DailyRoster;

use App\Models\DailyRoster\RosterActiveVersion;
use App\Models\DailyRoster\RosterImport;
use App\Models\DailyRoster\RosterSnapshot;
use App\Models\InboundEmail;
use App\Models\OutboundEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Deletes one verified TeleStaff email and its attachment; the ledger row survives. This is the
 * only automated deletion of inbound mail. Each step is recorded on the ledger before the next
 * starts, so a crash at any point re-runs cleanly. Records codes only, never mail content.
 */
final class RosterImportCleanup
{
    private const ATTACHMENT_ROOT = 'inbound-email-attachments/';

    private const ATTACHMENT_DISK = 'local';

    public function run(RosterImport $import): void
    {
        if (config('daily_roster.mode') !== 'publish' || ! config('daily_roster.cleanup_enabled')) {
            return;
        }

        $import = RosterImport::query()->find($import->getKey());
        if ($import === null || ! $this->isDue($import)) {
            return;
        }

        $attempts = $import->cleanup_attempts + 1;
        $import->forceFill(['cleanup_attempts' => $attempts])->save();

        try {
            if ($import->cleanup_state !== RosterImport::CLEANUP_ROW_DELETED && ! $this->deleteRow($import)) {
                return;
            }
            $this->deleteFile($import);
            app(RosterStateRecorder::class)->transition($import, RosterImport::STATE_CLEANED, [
                'cleanup_state' => RosterImport::CLEANUP_DONE,
                'cleaned_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $this->recordFailure($import, $attempts, $exception);
        }
    }

    private function isDue(RosterImport $import): bool
    {
        if ($import->state === RosterImport::STATE_CLEANED || in_array($import->cleanup_state, [
            RosterImport::CLEANUP_DONE, RosterImport::CLEANUP_KEPT_REPLIED, RosterImport::CLEANUP_FAILED,
        ], true)) {
            return false;
        }

        return $import->cleanup_state === RosterImport::CLEANUP_ROW_DELETED || $this->isEligible($import);
    }

    /** Rejected, held and failed mail is never eligible. */
    private function isEligible(RosterImport $import): bool
    {
        return match ($import->state) {
            RosterImport::STATE_VERIFIED => true,
            RosterImport::STATE_DUPLICATE => $this->duplicateIsBacked($import),
            RosterImport::STATE_SUPERSEDED => $import->roster_snapshot_id !== null
                && RosterSnapshot::query()->whereKey($import->roster_snapshot_id)->exists(),
            default => false,
        };
    }

    private function duplicateIsBacked(RosterImport $import): bool
    {
        if ($import->duplicateOf !== null && self::isVerified($import->duplicateOf)) {
            return true;
        }
        if ($import->outcome !== RosterImport::OUTCOME_CONFIRMED || $import->duty_date === null) {
            return false;
        }

        $pointer = RosterActiveVersion::query()->whereDate('duty_date', $import->duty_date->toDateString())->first();
        $source = $pointer?->snapshot?->import;

        return $source !== null && self::isVerified($source);
    }

    private static function isVerified(RosterImport $import): bool
    {
        return in_array($import->state, [RosterImport::STATE_VERIFIED, RosterImport::STATE_CLEANED], true);
    }

    /** @return bool False when the row must stay because someone replied to it. */
    private function deleteRow(RosterImport $import): bool
    {
        $import->forceFill(['cleanup_state' => RosterImport::CLEANUP_PENDING])->save();

        $rowDeleted = DB::transaction(function () use ($import): bool {
            $locked = RosterImport::query()->whereKey($import->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->cleanup_state !== RosterImport::CLEANUP_PENDING) {
                return $locked->cleanup_state === RosterImport::CLEANUP_ROW_DELETED;
            }

            $email = $locked->inbound_email_id === null
                ? null
                : InboundEmail::query()->whereKey($locked->inbound_email_id)->lockForUpdate()->first();
            $this->assertMatchesLedger($locked, $email);

            if (OutboundEmail::query()->where('parent_inbound_email_id', $email->getKey())->exists()) {
                $locked->forceFill(['cleanup_state' => RosterImport::CLEANUP_KEPT_REPLIED])->save();
                $this->recordEvent($locked, 'cleanup_kept_replied');

                return false;
            }

            $email->delete();
            $locked->forceFill(['cleanup_state' => RosterImport::CLEANUP_ROW_DELETED, 'inbound_email_id' => null])->save();
            $this->recordEvent($locked, 'cleanup_row_deleted');

            return true;
        });
        $import->refresh();

        return $rowDeleted;
    }

    private function assertMatchesLedger(RosterImport $import, ?InboundEmail $email): void
    {
        if ($email === null) {
            throw new RuntimeException('cleanup_inbound_row_missing');
        }
        if (! hash_equals($import->message_id_sha256, hash('sha256', (string) $email->provider_message_id))) {
            throw new RuntimeException('cleanup_message_hash_mismatch');
        }

        $this->assertSafeAttachmentPath($import);
        $attachments = is_array($email->attachment_metadata) ? array_values($email->attachment_metadata) : [];
        if (count($attachments) !== 1
            || ($attachments[0]['path'] ?? null) !== $import->attachment_path
            || ($attachments[0]['disk'] ?? null) !== $import->attachment_disk) {
            throw new RuntimeException('cleanup_attachment_mismatch');
        }

        $disk = Storage::disk(self::ATTACHMENT_DISK);
        if (! $disk->exists($import->attachment_path)) {
            return;
        }

        $stream = $disk->readStream($import->attachment_path);
        if (! is_resource($stream)) {
            throw new RuntimeException('cleanup_attachment_unreadable');
        }
        $context = hash_init('sha256');
        hash_update_stream($context, $stream);
        fclose($stream);
        if (! is_string($import->attachment_sha256) || ! hash_equals($import->attachment_sha256, hash_final($context))) {
            throw new RuntimeException('cleanup_attachment_hash_mismatch');
        }
    }

    /** The path must sit directly inside the directory derived from this message's hash. */
    private function assertSafeAttachmentPath(RosterImport $import): void
    {
        $path = $import->attachment_path;
        $directory = self::ATTACHMENT_ROOT.$import->message_id_sha256.'/';
        if ($import->attachment_disk !== self::ATTACHMENT_DISK
            || ! is_string($path)
            || ! str_starts_with($path, $directory)
            || str_contains($path, '..')
            || str_contains($path, '\\')
            || str_contains(substr($path, strlen($directory)), '/')
            || substr($path, strlen($directory)) === '') {
            throw new RuntimeException('cleanup_attachment_path_unsafe');
        }
    }

    /** A file that is already gone counts as deleted. */
    private function deleteFile(RosterImport $import): void
    {
        $this->assertSafeAttachmentPath($import);
        $disk = Storage::disk(self::ATTACHMENT_DISK);
        if ($disk->exists($import->attachment_path) && ! $disk->delete($import->attachment_path)) {
            throw new RuntimeException('cleanup_file_delete_failed');
        }

        $directory = self::ATTACHMENT_ROOT.$import->message_id_sha256;
        if ($disk->exists($directory) && $disk->allFiles($directory) === []) {
            $disk->deleteDirectory($directory);
        }
    }

    private function recordFailure(RosterImport $import, int $attempts, Throwable $exception): void
    {
        $code = str_starts_with($exception->getMessage(), 'cleanup_') ? $exception->getMessage() : 'cleanup_error';
        $this->recordEvent($import, 'cleanup_refused', ['code' => $code, 'attempt' => $attempts]);

        if ($attempts < (int) config('daily_roster.processing.max_cleanup_attempts', 5)) {
            return;
        }

        $import->forceFill(['cleanup_state' => RosterImport::CLEANUP_FAILED, 'error_code' => $code])->save();
        $this->recordEvent($import, 'cleanup_failed', ['code' => $code]);
        report(new RuntimeException('roster_cleanup_failed: '.$code));
    }

    /** @param array<string, int|string> $context */
    private function recordEvent(RosterImport $import, string $action, array $context = []): void
    {
        app(RosterStateRecorder::class)->appendEvent($import, $action, $import->state, $import->state, null, $context);
    }
}
