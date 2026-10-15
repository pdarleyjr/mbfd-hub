<?php

declare(strict_types=1);

namespace App\Models\Training;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<string, mixed> $snapshot
 * @property array<string, mixed>|null $oic_snapshot
 * @property array{disk: string, path: string, mime: string, sha256: string}|null $oic_signature
 * @property array{disk: string, path: string, mime: string, sha256: string}|null $pdf_artifact
 * @property array{disk: string, path: string, mime: string, sha256: string}|null $xlsx_artifact
 * @property \Carbon\CarbonImmutable|null $certified_at
 * @property int $revision
 * @property-read TrainingSession $session
 */
final class TrainingRosterVersion extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['snapshot' => 'array', 'oic_snapshot' => 'array', 'oic_signature' => 'array', 'pdf_artifact' => 'array', 'xlsx_artifact' => 'array', 'certified_at' => 'immutable_datetime', 'revision' => 'integer'];
    }

    protected static function booted(): void
    {
        self::deleting(fn () => throw new \LogicException('Training roster versions cannot be deleted.'));
        self::updating(function (self $version): void {
            if (array_diff(array_keys($version->getDirty()), ['pdf_artifact', 'xlsx_artifact', 'updated_at']) !== []) {
                throw new \LogicException('Training roster snapshots are immutable.');
            }
            foreach (['pdf_artifact', 'xlsx_artifact'] as $field) {
                if ($version->isDirty($field) && $version->getOriginal($field) !== null) {
                    throw new \LogicException('Training roster artifacts cannot be replaced.');
                }
            }
        });
    }

    /** @return BelongsTo<TrainingSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class, 'training_session_id');
    }
}
