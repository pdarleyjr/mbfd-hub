<?php

declare(strict_types=1);

namespace App\Models\Narcotics;

use App\Models\Ems\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit row for a narcotics check. Corrections are new rows, never edits.
 *
 * @property int $id
 * @property int $narcotics_check_id
 * @property int|null $actor_user_id
 * @property string|null $actor_name
 * @property string|null $actor_rank
 * @property string $actor_context
 * @property string $action
 * @property string|null $from_status
 * @property string|null $to_status
 * @property string|null $idempotency_key
 * @property array<string, mixed>|null $payload
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class NarcoticsCheckEvent extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    public const CONTEXT_MEMBER = 'member';

    public const CONTEXT_CAPTAIN5 = 'captain5';

    public const CONTEXT_SYSTEM = 'system';

    public const SUBMITTED = 'submitted';

    public const VIEWED = 'viewed';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const IMAGES_PURGED = 'images_purged';

    /** Events a member sees in their own history (reviewer views are not the member's business). */
    public const MEMBER_VISIBLE = [self::SUBMITTED, self::APPROVED, self::REJECTED];

    protected $table = 'narcotics_check_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    /** @return BelongsTo<NarcoticsCheck, $this> */
    public function check(): BelongsTo
    {
        return $this->belongsTo(NarcoticsCheck::class, 'narcotics_check_id');
    }
}
