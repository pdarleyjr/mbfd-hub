<?php

declare(strict_types=1);

namespace App\Models\Ems;

use App\Models\Ems\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A message on a request. Visible to the requester and to Captain 5 / EMS Admin.
 *
 * @property int $id
 * @property int $request_id
 * @property int|null $author_user_id
 * @property string $author_name
 * @property string|null $author_rank
 * @property string $author_context
 * @property string $body
 * @property string $client_message_id
 * @property \Illuminate\Support\Carbon|null $created_at
 */
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
