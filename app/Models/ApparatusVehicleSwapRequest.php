<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A possible truck swap reported by a member at Daily Checkout and held for a
 * Fleet decision. The row records what was reported; it never asserts that the
 * report is true.
 *
 * @property int $id
 * @property int $apparatus_inspection_id
 * @property int $apparatus_id
 * @property int|null $reported_apparatus_id
 * @property string $reporter_name
 * @property string|null $unit_designation
 * @property string|null $assigned_vehicle_number
 * @property string $reported_vehicle_number
 * @property bool $reported_vehicle_listed
 * @property string $answer
 * @property string|null $note
 * @property string|null $meter_baseline_token
 * @property string $status
 * @property array<string, mixed>|null $decision_audit
 */
class ApparatusVehicleSwapRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    /** Both units trade vehicle IDs. */
    public const STATUS_APPROVED_SWAP = 'approved_swap';

    /** The reported vehicle takes the unit; the old vehicle is demoted to reserve or out of service. */
    public const STATUS_APPROVED_REPLACE = 'approved_replace';

    public const STATUS_REJECTED = 'rejected';

    public const ANSWER_SWAP_COMPLETED = 'swap_completed';

    public const ANSWER_UNKNOWN = 'unknown';

    public const ANSWERS = [self::ANSWER_SWAP_COMPLETED, self::ANSWER_UNKNOWN];

    protected $fillable = [
        'apparatus_inspection_id',
        'apparatus_id',
        'reported_apparatus_id',
        'reporter_user_id',
        'reporter_name',
        'unit_designation',
        'assigned_vehicle_number',
        'reported_vehicle_number',
        'reported_vehicle_listed',
        'answer',
        'note',
        'meter_baseline_token',
        'status',
        'decided_by_user_id',
        'decided_at',
        'decision_note',
        'decision_audit',
    ];

    /** @var list<string> */
    protected $hidden = ['meter_baseline_token'];

    protected function casts(): array
    {
        return [
            'reported_vehicle_listed' => 'boolean',
            'decided_at' => 'datetime',
            'decision_audit' => 'array',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_PENDING => 'Pending decision',
            self::STATUS_APPROVED_SWAP => 'Approved, vehicles traded',
            self::STATUS_APPROVED_REPLACE => 'Approved, old vehicle demoted',
            self::STATUS_REJECTED => 'Rejected, no change',
            default => str($status)->headline()->toString(),
        };
    }

    public static function answerLabel(string $answer): string
    {
        return match ($answer) {
            self::ANSWER_SWAP_COMPLETED => 'Yes, swap completed',
            self::ANSWER_UNKNOWN => 'Unknown, not sure why',
            default => str($answer)->headline()->toString(),
        };
    }

    /** @return BelongsTo<ApparatusInspection, $this> */
    public function inspection(): BelongsTo
    {
        return $this->belongsTo(ApparatusInspection::class, 'apparatus_inspection_id');
    }

    /** @return BelongsTo<Apparatus, $this> */
    public function apparatus(): BelongsTo
    {
        return $this->belongsTo(Apparatus::class);
    }

    /** @return BelongsTo<Apparatus, $this> */
    public function reportedApparatus(): BelongsTo
    {
        return $this->belongsTo(Apparatus::class, 'reported_apparatus_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }
}
