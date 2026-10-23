<?php

declare(strict_types=1);

namespace App\Models\Captain5;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * An administrator's decision that a checklist item is (or is not) Captain 5 medical scope.
 * History is immutable: a new decision supersedes the current row (only superseded_* change, once).
 *
 * @property int $id
 * @property string $checklist_type
 * @property string $item_key
 * @property string $item_name
 * @property string $classification
 * @property string $reason
 * @property int $decided_by_user_id
 * @property \Carbon\CarbonImmutable|null $superseded_at
 */
final class Captain5ItemClassification extends Model
{
    public const MEDICAL = 'medical';

    public const NOT_MEDICAL = 'not_medical';

    protected $table = 'captain5_item_classifications';

    protected $guarded = ['id'];

    private const MUTABLE = ['superseded_at', 'superseded_by_user_id', 'updated_at'];

    protected $casts = ['superseded_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        self::updating(static function (self $row): void {
            if ($row->getOriginal('superseded_at') !== null
                || array_diff(array_keys($row->getDirty()), self::MUTABLE) !== []) {
                throw new LogicException('Medical scope decisions are immutable history.');
            }
        });
        self::deleting(static fn (): never => throw new LogicException('Medical scope decisions are never deleted.'));
    }

    /** @param Builder<self> $query */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('superseded_at');
    }

    /** @return BelongsTo<User, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }
}
