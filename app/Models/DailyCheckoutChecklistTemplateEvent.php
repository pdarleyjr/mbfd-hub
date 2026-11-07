<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only audit trail of the Daily Checkout checklist template editor
 * (docs/checklist-template-editor.md section 2): who started, saved, imported, restored,
 * discarded, published or reverted which template, with the diff counts and per-unit versions.
 *
 * @property int $id
 * @property string $checklist_type
 * @property int|null $template_version_id
 * @property string $action
 * @property int|null $user_id
 * @property array<string, mixed>|null $details
 * @property \Carbon\CarbonInterface|null $created_at
 */
final class DailyCheckoutChecklistTemplateEvent extends Model
{
    public const DRAFT_STARTED = 'draft_started';

    public const DRAFT_SAVED = 'draft_saved';

    public const DRAFT_IMPORTED = 'draft_imported';

    public const DRAFT_RESTORED = 'draft_restored';

    public const DRAFT_DISCARDED = 'draft_discarded';

    public const PUBLISHED = 'published';

    public const REVERTED_TO_FILE = 'reverted_to_file';

    public const UPDATED_AT = null;

    protected $table = 'daily_checkout_checklist_template_events';

    protected $guarded = ['id'];

    protected $casts = [
        'details' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('Checklist template events are append-only.'));
        self::deleting(static fn (): never => throw new LogicException('Checklist template events are append-only.'));
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<DailyCheckoutChecklistTemplateVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(DailyCheckoutChecklistTemplateVersion::class, 'template_version_id');
    }
}
