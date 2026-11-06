<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only audit of an accident report: submitted, routed, completed, acknowledged, amended, resolved. */
class AccidentReportEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['accident_report_id', 'actor_user_id', 'action', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    /** @return BelongsTo<AccidentReport, $this> */
    public function report(): BelongsTo
    {
        return $this->belongsTo(AccidentReport::class, 'accident_report_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
