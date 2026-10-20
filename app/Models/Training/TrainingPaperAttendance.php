<?php

declare(strict_types=1);

namespace App\Models\Training;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property-read TrainingPaperRosterImport $sourceImport
 * @property-read TrainingSession $session
 * @property-read Employee|null $employee
 */
final class TrainingPaperAttendance extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['evidence_present' => 'boolean', 'reviewed_at' => 'immutable_datetime', 'review_version' => 'integer'];
    }

    protected static function booted(): void
    {
        self::deleting(fn () => throw new \LogicException('Paper attendance requires an audited void.'));
        self::updating(function (self $entry): void {
            if ($entry->isDirty(['training_paper_roster_import_id', 'training_session_id', 'source_page', 'source_row', 'raw_transcription', 'evidence_present'])) {
                throw new \LogicException('Original paper observations are immutable.');
            }
        });
    }

    /** @return BelongsTo<TrainingPaperRosterImport, $this> */
    public function sourceImport(): BelongsTo
    {
        return $this->belongsTo(TrainingPaperRosterImport::class, 'training_paper_roster_import_id');
    }

    /** @return BelongsTo<TrainingSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class, 'training_session_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_profile_id');
    }

    /** @return HasMany<TrainingPaperReviewAudit, $this> */
    public function audits(): HasMany
    {
        return $this->hasMany(TrainingPaperReviewAudit::class);
    }
}
