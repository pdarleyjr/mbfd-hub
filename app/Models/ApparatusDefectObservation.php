<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class ApparatusDefectObservation extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('Defect observations are append-only.'));
        self::deleting(static fn (): never => throw new LogicException('Defect observations are append-only.'));
    }
}
