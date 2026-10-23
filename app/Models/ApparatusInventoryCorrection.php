<?php

declare(strict_types=1);

namespace App\Models;

use App\Filament\Resources\InventoryCorrectionResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * One member report that the Daily Checkout checklist is wrong for an apparatus
 * (docs/apparatus-inventory-corrections.md). The report never changes the inventory
 * itself; only an approved change set does.
 */
final class ApparatusInventoryCorrection extends Model
{
    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_CLARIFICATION = 'clarification_requested';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_REVERSED = 'reversed';

    /** @var list<string> awaiting a Logistics decision */
    public const OPEN_STATUSES = [self::STATUS_SUBMITTED, self::STATUS_CLARIFICATION];

    protected $guarded = ['id'];

    protected $casts = [
        'target_snapshot' => 'array',
        'proposal' => 'array',
        'decision' => 'array',
        'decided_at' => 'datetime',
        'lock_version' => 'integer',
    ];

    protected static function booted(): void
    {
        // The admin navigation badge caches the open count; any write refreshes it after commit.
        // A cache outage never fails the request: the count simply expires within a minute.
        self::saved(static fn () => DB::afterCommit(static fn () => rescue(static fn () => Cache::forget(InventoryCorrectionResource::OPEN_COUNT_CACHE_KEY), report: false)));
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function reference(): string
    {
        return 'IC-'.str_pad((string) $this->getKey(), 6, '0', STR_PAD_LEFT);
    }

    /** @param Builder<self> $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', self::OPEN_STATUSES);
    }

    /** @return array<string, string> */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_SUBMITTED => 'Awaiting review',
            self::STATUS_CLARIFICATION => 'Question for the member',
            self::STATUS_APPLIED => 'Approved and applied',
            self::STATUS_REJECTED => 'Not approved',
            self::STATUS_REVERSED => 'Reversed',
        ];
    }

    public static function statusLabel(?string $status): string
    {
        return self::statusLabels()[$status] ?? 'Unknown';
    }

    /** @return BelongsTo<Apparatus, $this> */
    public function apparatus(): BelongsTo
    {
        return $this->belongsTo(Apparatus::class);
    }

    /** @return BelongsTo<ApparatusInspection, $this> */
    public function inspection(): BelongsTo
    {
        return $this->belongsTo(ApparatusInspection::class, 'apparatus_inspection_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_user_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function reporterEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reporter_employee_id');
    }

    /** @return BelongsTo<User, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    /** @return HasMany<ApparatusInventoryCorrectionEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(ApparatusInventoryCorrectionEvent::class)->orderBy('created_at')->orderBy('id');
    }

    /** @return HasMany<ApparatusInventoryChangeSet, $this> */
    public function changeSets(): HasMany
    {
        return $this->hasMany(ApparatusInventoryChangeSet::class)->orderBy('id');
    }

    /** @return HasMany<ApparatusInventoryChange, $this> */
    public function changes(): HasMany
    {
        return $this->hasMany(ApparatusInventoryChange::class)->orderBy('id');
    }
}
