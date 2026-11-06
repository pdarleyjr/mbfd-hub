<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasArchive;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * An immutable Take Home Vehicle Inspection. Separate from Daily apparatus
 * checkouts. Rows are written once on submission and their answers are never
 * updated: a member's correction is a new TakeHomeVehicleInspectionRevision, and
 * effective() lays the latest one over the signed original for display. The only
 * columns that may change afterwards are the Fleet clear (archive) columns.
 */
class TakeHomeVehicleInspection extends Model
{
    use HasArchive;

    public const TYPE_LABEL = 'Take Home Vehicle Inspection';

    /** Columns Fleet may change on a submitted inspection: clearing and restoring it. */
    public const ARCHIVE_COLUMNS = ['archived_at', 'archived_by', 'archive_reason', 'archive_history', 'updated_at'];

    /** Answers a member may correct after submitting, plus the values derived from them. */
    public const REVISABLE_FIELDS = [
        'driver_employee_id',
        'driver_name',
        'driver_employee_number',
        'driver_verified',
        'odometer',
        'odometer_exception',
        'odometer_flagged',
        'odometer_note',
        'checks',
        'has_issues',
        'has_not_checked',
        'keys_present',
        'acceptable_for_use',
        'comments',
    ];

    /** Plain labels for the answers a revision can change (check fields are labelled per check). */
    public const REVISABLE_LABELS = [
        'driver_name' => 'Assigned driver',
        'driver_employee_number' => 'Driver employee ID',
        'odometer' => 'Odometer',
        'odometer_exception' => 'No working odometer',
        'keys_present' => 'Keys present',
        'acceptable_for_use' => 'Acceptable for use',
        'comments' => 'Additional comments',
    ];

    public const STATUS_GOOD = 'good';

    public const STATUS_ISSUE = 'issue';

    public const STATUS_NOT_CHECKED = 'not_checked';

    public const STATUS_LABELS = [
        'good' => 'Good',
        'issue' => 'Issue found',
        'not_checked' => 'Not checked',
    ];

    public const NOT_ANSWERED = 'Not answered';

    /** The six condition checks, in order. */
    public const CHECKS = [
        'exterior_body' => ['Exterior body', 'Dents, scratches, bumpers or body damage.'],
        'windows_mirrors' => ['Windows & mirrors', 'Cracks, chips, damaged or missing mirrors.'],
        'tires_wheels' => ['Tires & wheels', 'Visible damage, flat or obviously low tires.'],
        'interior' => ['Interior', 'Seats, cleanliness, missing equipment.'],
        'dashboard_lights' => ['Dashboard / warning lights', 'Warning indicators with the vehicle safely parked and ignition on.'],
        'exterior_lights' => ['Exterior lights / signals', 'Headlights, brake lights, turn signals and other exterior lighting.'],
    ];

    /** The six required standard photos, in order. */
    public const PHOTO_ANGLES = [
        'front' => 'Front',
        'rear' => 'Rear',
        'driver_side' => 'Driver side (left)',
        'passenger_side' => 'Passenger side (right)',
        'interior' => 'Interior / cabin',
        'dashboard' => 'Dashboard / instrument cluster',
    ];

    public const ISSUE_ANGLE = 'issue';

    public const ODOMETER_EXCEPTIONS = [
        'not_equipped' => 'No odometer / not equipped',
        'nonroad' => 'Non-road asset (trailer, boat, cart)',
    ];

    protected $fillable = [
        'reference',
        'client_submission_id',
        'unit_master_vehicle_id',
        'inspector_user_id',
        'inspector_name',
        'driver_employee_id',
        'driver_name',
        'driver_employee_number',
        'driver_verified',
        'vehicle_snapshot',
        'odometer',
        'odometer_exception',
        'odometer_flagged',
        'odometer_note',
        'checks',
        'has_issues',
        'has_not_checked',
        'keys_present',
        'acceptable_for_use',
        'comments',
        'acknowledged_at',
        'signature_path',
        'signature_sha256',
        'started_at',
        'submitted_at',
    ];

    protected $casts = [
        'vehicle_snapshot' => 'array',
        'checks' => 'array',
        'driver_verified' => 'boolean',
        'odometer' => 'integer',
        'odometer_flagged' => 'boolean',
        'has_issues' => 'boolean',
        'has_not_checked' => 'boolean',
        'keys_present' => 'boolean',
        'acceptable_for_use' => 'boolean',
        'acknowledged_at' => 'datetime',
        'started_at' => 'datetime',
        'submitted_at' => 'datetime',
        'archived_at' => 'datetime',
        'archive_history' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $inspection): void {
            // Only Fleet's clear/restore columns may change; every answer stays as signed.
            if (array_diff(array_keys($inspection->getDirty()), self::ARCHIVE_COLUMNS) !== []) {
                throw new LogicException('Take Home Vehicle Inspections are immutable once submitted.');
            }
        });
        static::deleting(function (): never {
            throw new LogicException('Take Home Vehicle Inspections cannot be deleted.');
        });
    }

    /** @return BelongsTo<UnitMasterVehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(UnitMasterVehicle::class, 'unit_master_vehicle_id');
    }

    /** @return BelongsTo<User, $this> */
    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspector_user_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'driver_employee_id');
    }

    /** @return HasMany<TakeHomeVehicleInspectionPhoto, $this> */
    public function photos(): HasMany
    {
        return $this->hasMany(TakeHomeVehicleInspectionPhoto::class)->orderBy('id');
    }

    /** @return HasMany<UnitMasterVehicleChange, $this> */
    public function vehicleChanges(): HasMany
    {
        return $this->hasMany(UnitMasterVehicleChange::class);
    }

    /** @return HasMany<TakeHomeVehicleInspectionRevision, $this> oldest first */
    public function revisions(): HasMany
    {
        return $this->hasMany(TakeHomeVehicleInspectionRevision::class)->orderBy('revision_number');
    }

    /** @return HasOne<TakeHomeVehicleInspectionRevision, $this> */
    public function latestRevision(): HasOne
    {
        return $this->hasOne(TakeHomeVehicleInspectionRevision::class)->latestOfMany('revision_number');
    }

    public function isEdited(): bool
    {
        return $this->latestRevision !== null;
    }

    /**
     * The inspection as it reads now: the latest member correction laid over the
     * signed original, in memory only. The returned copy is never saved (its answers
     * are dirty, so the update guard refuses it); the stored row is untouched.
     */
    public function effective(): static
    {
        $revision = $this->latestRevision;
        if ($revision === null) {
            return $this;
        }

        $copy = clone $this;
        $copy->forceFill(array_intersect_key((array) $revision->answers, array_flip(self::REVISABLE_FIELDS)));
        $copy->setRelation('latestRevision', $revision);

        return $copy;
    }

    /**
     * The revisable answers as currently held by this instance (the original row, or
     * an effective() copy).
     *
     * @return array<string, mixed>
     */
    public function answers(): array
    {
        $answers = [];
        foreach (self::REVISABLE_FIELDS as $field) {
            $answers[$field] = $this->getAttribute($field);
        }

        return $answers;
    }

    /** "Exterior body: result" for a check field, otherwise the plain label. */
    public static function revisableFieldLabel(string $field): string
    {
        if (preg_match('/^checks\.([a-z_]+)\.(status|issue_description|notes)$/', $field, $match) === 1) {
            $check = self::CHECKS[$match[1]][0] ?? $match[1];

            return $check.': '.match ($match[2]) {
                'status' => 'result',
                'issue_description' => 'issue description',
                default => 'notes',
            };
        }

        return self::REVISABLE_LABELS[$field] ?? str_replace('_', ' ', ucfirst($field));
    }

    public static function revisableValueLabel(string $field, mixed $value): string
    {
        if (str_ends_with($field, '.status')) {
            return self::statusLabel(is_string($value) ? $value : null);
        }

        return match ($field) {
            'keys_present', 'acceptable_for_use' => self::answerLabel($value === null ? null : (bool) $value),
            'odometer' => $value === null ? 'Not recorded' : number_format((int) $value).' mi',
            'odometer_exception' => self::ODOMETER_EXCEPTIONS[(string) $value] ?? 'No',
            default => filled($value) ? (string) $value : 'Blank',
        };
    }

    /** Standard photos keyed by angle, in the canonical order. */
    public function standardPhotos(): array
    {
        $byAngle = $this->photos->keyBy('angle');
        $ordered = [];
        foreach (array_keys(self::PHOTO_ANGLES) as $angle) {
            if ($byAngle->has($angle)) {
                $ordered[$angle] = $byAngle->get($angle);
            }
        }

        return $ordered;
    }

    public function issuePhotos(?string $checkKey = null)
    {
        return $this->photos
            ->where('angle', self::ISSUE_ANGLE)
            ->when($checkKey !== null, fn ($photos) => $photos->where('check_key', $checkKey))
            ->values();
    }

    /** Needs Fleet attention: an issue, an unchecked or unanswered item, or not confirmed acceptable. */
    public function needsAttention(): bool
    {
        return $this->acceptable_for_use !== true || $this->has_issues || $this->has_not_checked || $this->odometer_flagged;
    }

    public static function statusLabel(?string $status): string
    {
        return self::STATUS_LABELS[$status ?? ''] ?? self::NOT_ANSWERED;
    }

    /** Yes / No / Not answered for an optional yes-no answer. */
    public static function answerLabel(?bool $answer): string
    {
        return $answer === null ? self::NOT_ANSWERED : ($answer ? 'Yes' : 'No');
    }

    /** success / danger / warning colour for an optional yes-no answer. */
    public static function answerColor(?bool $answer): string
    {
        return $answer === null ? 'warning' : ($answer ? 'success' : 'danger');
    }

    public function acceptableLabel(): string
    {
        return self::answerLabel($this->acceptable_for_use);
    }

    public function keysLabel(): string
    {
        return self::answerLabel($this->keys_present);
    }

    /** Checks left Not checked or unanswered (neither Good nor Issue found). */
    public function uncheckedChecks(): \Illuminate\Support\Collection
    {
        return collect($this->checks ?? [])
            ->reject(fn (array $check): bool => in_array($check['status'] ?? null, [self::STATUS_GOOD, self::STATUS_ISSUE], true))
            ->values();
    }

    public function driverLabel(): string
    {
        return filled($this->driver_name) ? (string) $this->driver_name : 'Not recorded';
    }

    public function submittedAtLocal(): string
    {
        return $this->submitted_at->clone()->timezone(\App\Support\HubTimezone::NAME)->format('M j, Y g:i A T');
    }

    public function vehicleLabel(): string
    {
        $snap = $this->vehicle_snapshot ?? [];

        return trim(implode(' ', array_filter([
            ! empty($snap['veh_number']) ? '#'.$snap['veh_number'] : null,
            $snap['year'] ?? null,
            $snap['make'] ?? null,
            $snap['model'] ?? null,
        ])));
    }

    public function pdfFilename(): string
    {
        $veh = preg_replace('/[^A-Za-z0-9\-]/', '', (string) ($this->vehicle_snapshot['veh_number'] ?? 'vehicle'));

        return 'MBFD - Take Home Vehicle Inspection - '.$veh.' - '.$this->reference.'.pdf';
    }
}
