<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property CarbonImmutable|null $completed_through
 * @property CarbonImmutable|null $attempted_at
 */
final class OutboundEmailReconciliationCheckpoint extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['completed_through' => 'immutable_datetime', 'attempted_at' => 'immutable_datetime'];
    }
}
