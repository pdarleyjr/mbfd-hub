<?php

declare(strict_types=1);

namespace App\Models\SupportTriage;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The one triage answer for an open record. `level` is what staff screens, notifications and the reply
 * engine read: a staff override when there is one, else the level the equipment catalog produced (records
 * flagged significant or out of service always read HIGH). Written only by TriageProjector.
 *
 * @property int $id
 * @property string $subject_type
 * @property int $subject_id
 * @property string $auto_level
 * @property string|null $override_level
 * @property string $level
 * @property string|null $category
 * @property string|null $handler
 * @property int|null $rule_id
 * @property bool $unclassified
 * @property list<string>|null $reasons
 * @property int $policy_version
 * @property int|null $override_by_user_id
 * @property \Illuminate\Support\Carbon|null $override_at
 * @property \Illuminate\Support\Carbon|null $computed_at
 */
class SupportTriageLevel extends Model
{
    protected $table = 'support_triage_levels';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'subject_id' => 'integer',
            'unclassified' => 'boolean',
            'reasons' => 'array',
            'policy_version' => 'integer',
            'override_at' => 'datetime',
            'computed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SupportEquipmentRule, $this> */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(SupportEquipmentRule::class, 'rule_id');
    }

    /** @return BelongsTo<User, $this> */
    public function overriddenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'override_by_user_id');
    }
}
