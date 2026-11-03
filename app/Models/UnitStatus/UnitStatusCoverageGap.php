<?php

declare(strict_types=1);

namespace App\Models\UnitStatus;

use Illuminate\Database\Eloquent\Model;

/**
 * A period in which Command reported the PulsePoint feed as anything but live.
 *
 * @property \Carbon\CarbonImmutable $started_at
 * @property \Carbon\CarbonImmutable|null $ended_at
 * @property string $reason
 */
final class UnitStatusCoverageGap extends Model
{
    protected $table = 'unit_status_coverage_gaps';

    protected $guarded = ['id'];

    protected $casts = [
        'started_at' => 'immutable_datetime',
        'ended_at' => 'immutable_datetime',
    ];
}
