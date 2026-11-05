<?php

declare(strict_types=1);

namespace App\Models\AiAutomation;

use App\Models\Ems\Concerns\AppendOnly;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only history: every saved body of a playbook, with who saved it. */
class AiAutomationPlaybookRevision extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $table = 'ai_automation_playbook_revisions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<AiAutomationPlaybook, $this> */
    public function playbook(): BelongsTo
    {
        return $this->belongsTo(AiAutomationPlaybook::class, 'playbook_id');
    }

    /** @return BelongsTo<User, $this> */
    public function editedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by_user_id');
    }
}
