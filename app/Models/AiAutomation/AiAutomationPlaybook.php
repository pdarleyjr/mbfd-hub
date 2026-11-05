<?php

declare(strict_types=1);

namespace App\Models\AiAutomation;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One editable reply template or word list. Changed only through PlaybookRepository::update(), which bumps
 * the version and writes a revision row.
 */
class AiAutomationPlaybook extends Model
{
    public const KIND_TEMPLATE = 'template';

    public const KIND_LIST = 'list';

    protected $table = 'ai_automation_playbooks';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    public function isList(): bool
    {
        return $this->kind === self::KIND_LIST;
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /** @return HasMany<AiAutomationPlaybookRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(AiAutomationPlaybookRevision::class, 'playbook_id')->orderByDesc('version');
    }
}
