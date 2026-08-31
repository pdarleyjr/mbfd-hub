<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SessionContextClass;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PersistentLoginCredential extends Model
{
    /** @use HasFactory<\Database\Factories\PersistentLoginCredentialFactory> */
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $hidden = [
        'selector_hash',
        'validator_hash',
    ];

    protected function casts(): array
    {
        return [
            'context_class' => SessionContextClass::class,
            'issued_at' => 'datetime',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
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
