<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only history. Rows are written once and can never be changed or removed.
 *
 * @property array<string, mixed> $metadata
 */
class PromotionalListEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new \LogicException('Promotional list history is append-only.');
        });
        static::deleting(function (): never {
            throw new \LogicException('Promotional list history is append-only.');
        });
    }

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    /** @return BelongsTo<PromotionalList, $this> */
    public function list(): BelongsTo
    {
        return $this->belongsTo(PromotionalList::class, 'list_id');
    }

    /** @return BelongsTo<PromotionalListEntry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(PromotionalListEntry::class, 'entry_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
