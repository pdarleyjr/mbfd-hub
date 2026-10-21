<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A privately stored, re-encoded inspection photo. Uploaded before submission
 * (unattached) and attached to exactly one inspection on submit.
 */
class TakeHomeVehicleInspectionPhoto extends Model
{
    public const DISK = 'local';

    protected $fillable = [
        'uuid',
        'take_home_vehicle_inspection_id',
        'uploaded_by_user_id',
        'angle',
        'check_key',
        'path',
        'width',
        'height',
        'bytes',
        'sha256',
    ];

    protected $casts = [
        'width' => 'integer',
        'height' => 'integer',
        'bytes' => 'integer',
    ];

    /** @return BelongsTo<TakeHomeVehicleInspection, $this> */
    public function inspection(): BelongsTo
    {
        return $this->belongsTo(TakeHomeVehicleInspection::class, 'take_home_vehicle_inspection_id');
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function label(): string
    {
        if ($this->angle === TakeHomeVehicleInspection::ISSUE_ANGLE) {
            $check = TakeHomeVehicleInspection::CHECKS[$this->check_key][0] ?? 'Issue';

            return 'Issue: '.$check;
        }

        return TakeHomeVehicleInspection::PHOTO_ANGLES[$this->angle] ?? $this->angle;
    }
}
