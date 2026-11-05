<?php

declare(strict_types=1);

namespace App\Models\SupportTriage;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One row of the Support Services equipment catalog: what an item (or a group of look-alike items) is worth
 * (LOW, MEDIUM, HIGH), who follows up and how a member is guided. Written only by EquipmentRuleService, which
 * also keeps the append-only revision history and the policy version.
 *
 * @property int $id
 * @property string $name
 * @property string $normalized_name
 * @property list<string>|null $aliases
 * @property string $category
 * @property string $level
 * @property string $handler
 * @property string|null $apparatus_scope
 * @property string|null $member_response
 * @property bool $is_ppe
 * @property bool $is_critical
 * @property bool $is_expensive
 * @property string $contact_300
 * @property bool $active
 * @property string $source
 * @property int $version
 */
class SupportEquipmentRule extends Model
{
    public const SOURCE_SEEDED = 'seeded';

    public const SOURCE_ADMIN = 'admin';

    protected $table = 'support_equipment_rules';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'aliases' => 'array',
            'is_ppe' => 'boolean',
            'is_critical' => 'boolean',
            'is_expensive' => 'boolean',
            'active' => 'boolean',
            'version' => 'integer',
        ];
    }

    /** @return HasMany<SupportEquipmentRuleRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(SupportEquipmentRuleRevision::class, 'rule_id')->orderByDesc('version');
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /**
     * The values a revision stores and a restore applies.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'name' => $this->name,
            'aliases' => $this->aliases ?? [],
            'category' => $this->category,
            'level' => $this->level,
            'handler' => $this->handler,
            'apparatus_scope' => $this->apparatus_scope,
            'member_response' => $this->member_response,
            'is_ppe' => $this->is_ppe,
            'is_critical' => $this->is_critical,
            'is_expensive' => $this->is_expensive,
            'contact_300' => $this->contact_300,
            'active' => $this->active,
        ];
    }
}
