<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class DevicePrincipal extends Model
{
    /** @use HasFactory<\Database\Factories\DevicePrincipalFactory> */
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $hidden = [
        'credential_key_hash',
    ];

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'security_version' => 'integer',
            'issued_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Station, $this> */
    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    /** @return HasMany<AuthenticationSession, $this> */
    public function authenticationSessions(): HasMany
    {
        return $this->hasMany(AuthenticationSession::class);
    }

    /** @return HasMany<PersistentLoginCredential, $this> */
    public function persistentLoginCredentials(): HasMany
    {
        return $this->hasMany(PersistentLoginCredential::class);
    }
}
