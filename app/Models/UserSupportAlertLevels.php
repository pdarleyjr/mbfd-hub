<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One staff member's choice of Support Services priority levels (low, medium, high) for the optional
 * new-issue and new-request alerts. No row means all three are on.
 */
final class UserSupportAlertLevels extends Model
{
    protected $table = 'user_support_alert_levels';

    protected $fillable = [
        'user_id',
        'low_enabled',
        'medium_enabled',
        'high_enabled',
    ];

    protected function casts(): array
    {
        return [
            'low_enabled' => 'boolean',
            'medium_enabled' => 'boolean',
            'high_enabled' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
