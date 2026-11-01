<?php

declare(strict_types=1);

namespace App\Models\DailyRoster;

use Illuminate\Database\Eloquent\Model;

/** The roster last compared for move alerts on one duty date. */
final class RosterMoveBaseline extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'duty_date';

    protected $keyType = 'string';

    protected $guarded = [];
}
