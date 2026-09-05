<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

final class UserNotificationSubscription extends Model
{
    /** @var array{database_enabled: bool, webpush_enabled: bool, email_enabled: bool} */
    public const DEPARTMENT_UPDATE_DEFAULTS = [
        'database_enabled' => true,
        'webpush_enabled' => true,
        'email_enabled' => false,
    ];

    protected $fillable = [
        'user_id',
        'event_key',
        'database_enabled',
        'webpush_enabled',
        'email_enabled',
    ];

    protected function casts(): array
    {
        return [
            'database_enabled' => 'boolean',
            'webpush_enabled' => 'boolean',
            'email_enabled' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function ensureDepartmentUpdatesForUser(int $userId): void
    {
        DB::table((new self)->getTable())->insertOrIgnore([
            'user_id' => $userId,
            'event_key' => User::NOTIFICATION_PREFERENCE_DEPARTMENT_UPDATES,
            ...self::DEPARTMENT_UPDATE_DEFAULTS,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
