<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * An official promotional eligibility list (for example "Lieutenant Promotional Results 2025").
 *
 * @property string $id
 * @property string $list_type
 * @property string|null $type_label
 * @property string $title
 * @property Carbon $effective_date
 * @property Carbon|null $expires_on
 * @property string $status
 * @property string $source_reference
 * @property string|null $notes
 */
class PromotionalList extends Model
{
    use HasUuids;
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_SUPERSEDED = 'superseded';

    public const TYPE_OTHER = 'other';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['effective_date' => 'date:Y-m-d', 'expires_on' => 'date:Y-m-d'];
    }

    /** @return array<string, string> */
    public static function types(): array
    {
        return (array) config('promotional_lists.types', []);
    }

    /** @return array<string, string> */
    public static function statuses(): array
    {
        return (array) config('promotional_lists.list_statuses', []);
    }

    /** Today in the department's timezone, as stored in date columns. */
    public static function today(): string
    {
        return now('America/New_York')->toDateString();
    }

    public function typeName(): string
    {
        if ($this->list_type === self::TYPE_OTHER && filled($this->type_label)) {
            return (string) $this->type_label;
        }

        return self::types()[$this->list_type] ?? ucfirst(str_replace('_', ' ', $this->list_type));
    }

    /** True when the list is active and its expiry date has not passed. */
    public function isInEffect(): bool
    {
        return $this->status === self::STATUS_ACTIVE && ! $this->isPastExpiry();
    }

    public function isPastExpiry(): bool
    {
        return $this->expires_on !== null && $this->expires_on->toDateString() < self::today();
    }

    /** The stored status, except an active list past its expiry date reads as expired. */
    public function effectiveStatus(): string
    {
        return $this->status === self::STATUS_ACTIVE && $this->isPastExpiry() ? self::STATUS_EXPIRED : $this->status;
    }

    public function statusLabel(): string
    {
        return self::statuses()[$this->effectiveStatus()] ?? ucfirst($this->effectiveStatus());
    }

    /** @return HasMany<PromotionalListEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(PromotionalListEntry::class, 'list_id');
    }

    /** @return HasMany<PromotionalListEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(PromotionalListEvent::class, 'list_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
