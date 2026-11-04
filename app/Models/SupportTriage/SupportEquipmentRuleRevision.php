<?php

declare(strict_types=1);

namespace App\Models\SupportTriage;

use App\Models\Ems\Concerns\AppendOnly;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only history of an equipment rule: the full values after every save, with who saved them.
 *
 * @property int $id
 * @property int $rule_id
 * @property int $version
 * @property array<string, mixed> $snapshot
 * @property string $edited_by_name
 */
class SupportEquipmentRuleRevision extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $table = 'support_equipment_rule_revisions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'snapshot' => 'array', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<SupportEquipmentRule, $this> */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(SupportEquipmentRule::class, 'rule_id');
    }

    /** @return BelongsTo<User, $this> */
    public function editedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by_user_id');
    }
}
