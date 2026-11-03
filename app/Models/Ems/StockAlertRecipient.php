<?php

declare(strict_types=1);

namespace App\Models\Ems;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person on the EMS low-stock alert list. Being on the list grants nothing: the `ems_low_stock`
 * category also requires the person to be able to read the supply room inventory, asked again each time.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $added_by_user_id
 * @property string $added_by_name
 * @property \Illuminate\Support\Carbon $created_at
 */
class StockAlertRecipient extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'ems_stock_alert_recipients';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
