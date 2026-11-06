<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One member correction to a submitted Take Home Vehicle Inspection. Written once,
 * never changed: `changed_fields` is {field: {from, to}} and `answers` holds the
 * complete corrected answers (TakeHomeVehicleInspection::REVISABLE_FIELDS).
 */
class TakeHomeVehicleInspectionRevision extends Model
{
    protected $fillable = [
        'take_home_vehicle_inspection_id',
        'revision_number',
        'revised_by_user_id',
        'revised_by_name',
        'client_revision_id',
        'changed_fields',
        'answers',
        'reason',
        'added_problem',
    ];

    protected $casts = [
        'revision_number' => 'integer',
        'changed_fields' => 'array',
        'answers' => 'array',
        'added_problem' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Take Home Vehicle Inspection revisions are permanent records.');
        });
        static::deleting(function (): never {
            throw new LogicException('Take Home Vehicle Inspection revisions cannot be deleted.');
        });
    }

    /** @return BelongsTo<TakeHomeVehicleInspection, $this> */
    public function inspection(): BelongsTo
    {
        return $this->belongsTo(TakeHomeVehicleInspection::class, 'take_home_vehicle_inspection_id');
    }

    /** @return BelongsTo<User, $this> */
    public function revisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revised_by_user_id');
    }

    public function createdAtLocal(): string
    {
        return $this->created_at->clone()->timezone(\App\Support\HubTimezone::NAME)->format('M j, Y g:i A T');
    }

    /**
     * Each change as plain words for the report: label, before and after.
     *
     * @return list<array{label: string, from: string, to: string}>
     */
    public function changeLines(): array
    {
        $lines = [];
        foreach ((array) $this->changed_fields as $field => $change) {
            $lines[] = [
                'label' => TakeHomeVehicleInspection::revisableFieldLabel((string) $field),
                'from' => TakeHomeVehicleInspection::revisableValueLabel((string) $field, $change['from'] ?? null),
                'to' => TakeHomeVehicleInspection::revisableValueLabel((string) $field, $change['to'] ?? null),
            ];
        }

        return $lines;
    }
}
