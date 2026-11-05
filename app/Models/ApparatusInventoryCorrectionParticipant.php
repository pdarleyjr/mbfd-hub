<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A member who is part of an equipment change request: the one who filed it, or a member who
 * reported the same change later and was added instead of opening a second request.
 * Participants see the request, reply on it and are told the decision, like the reporter.
 */
final class ApparatusInventoryCorrectionParticipant extends Model
{
    public const SOURCE_REPORTER = 'reporter';

    public const SOURCE_JOINED = 'joined';

    protected $guarded = ['id'];

    protected $casts = [
        'joined_at' => 'datetime',
    ];

    /** @return BelongsTo<ApparatusInventoryCorrection, $this> */
    public function correction(): BelongsTo
    {
        return $this->belongsTo(ApparatusInventoryCorrection::class, 'apparatus_inventory_correction_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUser(User $user): bool
    {
        return ($this->user_id !== null && (int) $this->user_id === (int) $user->getKey())
            || ($this->employee_id !== null && $user->employee_profile_id !== null && (int) $this->employee_id === (int) $user->employee_profile_id);
    }
}
