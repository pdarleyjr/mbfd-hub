<?php

declare(strict_types=1);

namespace App\Models\Ems;

use Illuminate\Database\Eloquent\Model;

/**
 * One change to a supply order (created, status changed, quantity or date changed, arrived, cancelled). Append-only.
 *
 * @property int $id
 * @property int $supply_order_id
 * @property string|null $from_status
 * @property string $to_status
 * @property int|null $quantity_base
 * @property int|null $actor_user_id
 * @property string $actor_name
 * @property string|null $note
 * @property \Illuminate\Support\Carbon $created_at
 */
class SupplyOrderEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'ems_supply_order_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity_base' => 'integer', 'created_at' => 'datetime'];
    }
}
