<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One member's standalone stretcher report (docs/unified-activity-and-ownership.md section 4). The issue itself is
 * the apparatus_defects row it created or joined; this row keeps every report and reporter. Never an inspection.
 */
final class StretcherIssueReport extends Model
{
    /** Problem code => member-facing label. */
    public const PROBLEMS = [
        'loading' => 'Loading / unloading',
        'armrest' => 'Armrest',
        'battery' => 'Battery',
        'charger' => 'Charger',
        'restraint' => 'Restraint',
        'mechanical' => 'Mechanical',
        'other' => 'Other',
    ];

    protected $fillable = [
        'client_submission_id', 'apparatus_id', 'apparatus_defect_id', 'reporter_user_id', 'reporter_employee_id',
        'problem', 'description', 'unusable', 'photo_path', 'attached_to_existing',
    ];

    protected $casts = [
        'unusable' => 'boolean',
        'attached_to_existing' => 'boolean',
    ];

    /** @return BelongsTo<Apparatus, $this> */
    public function apparatus(): BelongsTo
    {
        return $this->belongsTo(Apparatus::class);
    }

    /** @return BelongsTo<ApparatusDefect, $this> */
    public function defect(): BelongsTo
    {
        return $this->belongsTo(ApparatusDefect::class, 'apparatus_defect_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_user_id');
    }
}
