<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SessionContextClass;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AuthenticationSession extends Model
{
    /** @use HasFactory<\Database\Factories\AuthenticationSessionFactory> */
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $hidden = [
        'session_id_hash',
    ];

    protected function casts(): array
    {
        return [
            'context_class' => SessionContextClass::class,
            'user_id' => 'integer',
            'security_version' => 'integer',
            'issued_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'idle_expires_at' => 'datetime',
            'absolute_expires_at' => 'datetime',
            'recent_auth_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<DevicePrincipal, $this> */
    public function devicePrincipal(): BelongsTo
    {
        return $this->belongsTo(DevicePrincipal::class);
    }
}
