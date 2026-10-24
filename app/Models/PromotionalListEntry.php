<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One candidate on a promotional list. Names and scores are snapshots exactly as printed;
 * current_rank is materialized by PromotionalListService and is never edited directly.
 *
 * @property int $id
 * @property string $list_id
 * @property int|null $employee_id
 * @property string|null $soi_id
 * @property string $last_name
 * @property string $first_name
 * @property int $original_rank
 * @property array<string, string> $scores
 * @property string $status
 * @property string|null $status_reason
 * @property Carbon|null $status_changed_at
 * @property int|null $current_rank
 */
class PromotionalListEntry extends Model
{
    public const STATUS_ELIGIBLE = 'eligible';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['scores' => 'array', 'original_rank' => 'integer', 'current_rank' => 'integer', 'status_changed_at' => 'datetime'];
    }

    /** @return array<string, string> */
    public static function statuses(): array
    {
        return (array) config('promotional_lists.entry_statuses', []);
    }

    public function statusLabel(): string
    {
        return self::statuses()[$this->status] ?? ucfirst($this->status);
    }

    public function printedName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    /** @return BelongsTo<PromotionalList, $this> */
    public function list(): BelongsTo
    {
        return $this->belongsTo(PromotionalList::class, 'list_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    /** @return BelongsTo<User, $this> */
    public function statusChangedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'status_changed_by');
    }
}
