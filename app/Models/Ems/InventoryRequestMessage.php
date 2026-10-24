<?php

declare(strict_types=1);

namespace App\Models\Ems;

use App\Models\Ems\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A message on a request. Visible to the requester and to Captain 5 / EMS Admin. */
class InventoryRequestMessage extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $table = 'ems_inventory_request_messages';

    protected $guarded = [];

    /** @return BelongsTo<InventoryRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(InventoryRequest::class, 'request_id');
    }
}
