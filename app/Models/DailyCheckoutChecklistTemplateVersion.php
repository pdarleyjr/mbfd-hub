<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One version of a Daily Checkout checklist template edited in the admin panel
 * (docs/checklist-template-editor.md section 2).
 *
 * Lifecycle: draft -> published -> retired, or draft -> discarded. At most one draft and one
 * published row per checklist type (enforced by partial unique indexes). The published row, when
 * present, replaces the repository file as the baseline every apparatus on that type resolves to.
 * Written only by App\Services\ChecklistTemplates\ChecklistTemplateEditor.
 *
 * @property int $id
 * @property string $checklist_type
 * @property string $status
 * @property int|null $version_number
 * @property array<string, mixed>|null $checklist never null in the database; null only if stored JSON could not be decoded
 * @property array<string, mixed>|null $base_checklist
 * @property string $checklist_hash
 * @property string $based_on_hash
 * @property int|null $based_on_version_id
 * @property string|null $source_file_hash
 * @property string|null $change_summary
 * @property int $lock_version
 * @property \Carbon\CarbonInterface|null $published_at
 * @property \Carbon\CarbonInterface|null $retired_at
 * @property \Carbon\CarbonInterface|null $discarded_at
 * @property \Carbon\CarbonInterface|null $created_at
 * @property \Carbon\CarbonInterface|null $updated_at
 */
final class DailyCheckoutChecklistTemplateVersion extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_RETIRED = 'retired';

    public const STATUS_DISCARDED = 'discarded';

    protected $table = 'daily_checkout_checklist_template_versions';

    protected $guarded = ['id'];

    protected $casts = [
        'checklist' => 'array',
        'base_checklist' => 'array',
        'version_number' => 'integer',
        'based_on_version_id' => 'integer',
        'lock_version' => 'integer',
        'published_at' => 'datetime',
        'retired_at' => 'datetime',
        'discarded_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** @param Builder<self> $query */
    public function scopeForType(Builder $query, string $checklistType): void
    {
        $query->where('checklist_type', $checklistType);
    }

    /** @param Builder<self> $query */
    public function scopeStatus(Builder $query, string $status): void
    {
        $query->where('status', $status);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function retiredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'retired_by_user_id');
    }

    /** @return BelongsTo<self, $this> */
    public function basedOnVersion(): BelongsTo
    {
        return $this->belongsTo(self::class, 'based_on_version_id');
    }

    /** @return HasMany<DailyCheckoutChecklistTemplateEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(DailyCheckoutChecklistTemplateEvent::class, 'template_version_id');
    }
}
