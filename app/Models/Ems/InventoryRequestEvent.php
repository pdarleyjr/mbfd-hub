<?php

declare(strict_types=1);

namespace App\Models\Ems;

use App\Models\Ems\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only request history. Corrections are new events, never edits. */
class InventoryRequestEvent extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    public const CONTEXT_MEMBER = 'member';

    public const CONTEXT_CAPTAIN5 = 'captain5';

    public const CONTEXT_EMS_ADMIN = 'ems_admin';

    public const CONTEXT_SYSTEM = 'system';

    /** Events a member may see in their own request history. */
    public const MEMBER_VISIBLE = [
        'submitted', 'reviewed', 'prepared', 'issued', 'issue_corrected', 'not_issued',
        'closed', 'reopened', 'cancelled', 'status_changed',
    ];

    protected $table = 'ems_inventory_request_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    /** @return BelongsTo<InventoryRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(InventoryRequest::class, 'request_id');
    }

    /** @return BelongsTo<InventoryRequestLine, $this> */
    public function line(): BelongsTo
    {
        return $this->belongsTo(InventoryRequestLine::class, 'request_line_id');
    }
}
