<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * An immutable Take Home Vehicle Inspection. Separate from Daily apparatus
 * checkouts. Rows are written once on submission and never updated.
 */
class TakeHomeVehicleInspection extends Model
{
    public const TYPE_LABEL = 'Take Home Vehicle Inspection';

    public const STATUS_GOOD = 'good';

    public const STATUS_ISSUE = 'issue';

    public const STATUS_NOT_CHECKED = 'not_checked';

    public const STATUS_LABELS = [
        'good' => 'Good',
        'issue' => 'Issue found',
        'not_checked' => 'Not checked',
    ];

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
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Take Home Vehicle Inspections are immutable once submitted.');
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

    /** Needs Fleet attention: an issue, an unchecked item, or not acceptable for use. */
    public function needsAttention(): bool
    {
        return ! $this->acceptable_for_use || $this->has_issues || $this->has_not_checked || $this->odometer_flagged;
    }

    public function submittedAtLocal(): string
    {
        return $this->submitted_at->clone()->timezone('America/New_York')->format('M j, Y g:i A T');
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
