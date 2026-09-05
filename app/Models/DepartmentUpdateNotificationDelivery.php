<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DepartmentUpdateNotificationDelivery extends Model
{
    protected $fillable = [
        'department_update_id',
        'user_id',
        'channel',
        'notification_id',
        'delivered_at',
    ];

    protected function casts(): array
    {
        return ['delivered_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<DepartmentUpdate, $this> */
    public function departmentUpdate(): BelongsTo
    {
        return $this->belongsTo(DepartmentUpdate::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
