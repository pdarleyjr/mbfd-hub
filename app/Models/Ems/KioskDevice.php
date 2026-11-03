<?php

declare(strict_types=1);

namespace App\Models\Ems;

use Illuminate\Database\Eloquent\Model;

/**
 * A screen paired to the supply room kiosk (docs/ems/supply-room-kiosk.md section 1). The secret is stored only
 * as a SHA-256 hash; a pairing token is single use and short lived.
 *
 * @property int $id
 * @property string $name
 * @property string|null $pairing_token_hash
 * @property string|null $pairing_code_hash
 * @property \Illuminate\Support\Carbon|null $pairing_expires_at
 * @property string|null $secret_hash
 * @property \Illuminate\Support\Carbon|null $paired_at
 * @property int|null $created_by_user_id
 * @property string $created_by_name
 * @property \Illuminate\Support\Carbon|null $last_seen_at
 * @property \Illuminate\Support\Carbon|null $offline_alerted_at
 * @property \Illuminate\Support\Carbon|null $revoked_at
 * @property string|null $revoked_by_name
 */
class KioskDevice extends Model
{
    protected $table = 'ems_kiosk_devices';

    protected $guarded = [];

    protected $hidden = ['pairing_token_hash', 'pairing_code_hash', 'secret_hash'];

    protected function casts(): array
    {
        return [
            'pairing_expires_at' => 'datetime', 'paired_at' => 'datetime', 'last_seen_at' => 'datetime',
            'offline_alerted_at' => 'datetime', 'revoked_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->paired_at !== null && $this->revoked_at === null && $this->secret_hash !== null;
    }
}
