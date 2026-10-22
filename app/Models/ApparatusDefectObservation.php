<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property int $id
 * @property int $apparatus_defect_id
 * @property int $apparatus_inspection_id
 * @property int|null $actor_user_id
 * @property string $observation first_reported | confirmed_again | appears_corrected
 * @property string|null $reported_status
 * @property string|null $notes
 * @property string|null $photo_path
 * @property \Illuminate\Support\Carbon|null $created_at
 */
final class ApparatusDefectObservation extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('Defect observations are append-only.'));
        self::deleting(static fn (): never => throw new LogicException('Defect observations are append-only.'));
    }
}
