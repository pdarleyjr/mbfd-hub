<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class ExternalSource extends Model
{
    protected $fillable = [
        'division',
        'name',
        'provider',
        'base_url',
        'token_encrypted',
        'token_hint',
        'status',
        'created_by',
    ];

    protected $hidden = [
        'token_encrypted',
    ];

    /**
     * Get decrypted token.
     */
    public function getTokenAttribute(): ?string
    {
        if (empty($this->token_encrypted)) {
            return null;
        }

        return Crypt::decryptString($this->token_encrypted);
    }

    /**
     * Set token – encrypts before storing.
     */
    public function setTokenAttribute(?string $value): void
    {
        $this->attributes['token_encrypted'] = $value ? Crypt::encryptString($value) : null;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function navItems(): HasMany
    {
        return $this->hasMany(ExternalNavItem::class);
    }
}
