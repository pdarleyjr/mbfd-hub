<?php

declare(strict_types=1);

namespace App\Models\Ems;

use App\Models\Apparatus;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What one member took at the supply room screen (docs/ems/supply-room-kiosk.md section 4).
 *
 * @property int $id
 * @property string $uuid
 * @property string $payload_hash
 * @property int $device_id
 * @property int $employee_profile_id
 * @property string $employee_number
 * @property string $employee_name
 * @property int|null $user_id
 * @property int|null $apparatus_id
 * @property string|null $unit_label
 * @property string|null $note
 * @property string $status
 * @property string $copy
 * @property \Illuminate\Support\Carbon $taken_at
 * @property \Illuminate\Support\Carbon|null $undone_at
 */
class KioskWithdrawal extends Model
{
    public const TAKEN = 'taken';

    public const UNDONE = 'undone';

    public const UNDO_SECONDS = 60;

    protected $table = 'ems_kiosk_withdrawals';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['taken_at' => 'datetime', 'undone_at' => 'datetime'];
    }

    public function number(): string
    {
        return 'W-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    /** @return HasMany<KioskWithdrawalLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(KioskWithdrawalLine::class, 'withdrawal_id')->orderBy('id');
    }

    /** @return BelongsTo<KioskDevice, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(KioskDevice::class, 'device_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_profile_id');
    }

    /** @return BelongsTo<Apparatus, $this> */
    public function apparatus(): BelongsTo
    {
        return $this->belongsTo(Apparatus::class, 'apparatus_id');
    }
}
