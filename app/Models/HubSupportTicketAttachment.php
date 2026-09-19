<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property int $hub_support_ticket_id
 * @property int|null $hub_support_ticket_update_id
 * @property string $disk
 * @property string $storage_path
 * @property string $generated_filename
 * @property string $original_filename
 * @property string $mime_type
 * @property int $file_size
 * @property string $sha256
 * @property int $uploaded_by_user_id
 */
final class HubSupportTicketAttachment extends Model
{
    protected $fillable = [
        'public_id', 'hub_support_ticket_id', 'hub_support_ticket_update_id', 'disk', 'storage_path',
        'generated_filename', 'original_filename', 'mime_type', 'file_size', 'sha256', 'uploaded_by_user_id',
    ];

    protected function casts(): array
    {
        return ['file_size' => 'integer'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<HubSupportTicket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(HubSupportTicket::class, 'hub_support_ticket_id');
    }

    /** @return BelongsTo<HubSupportTicketUpdate, $this> */
    public function ticketUpdate(): BelongsTo
    {
        return $this->belongsTo(HubSupportTicketUpdate::class, 'hub_support_ticket_update_id');
    }

    /** @return BelongsTo<User, $this> */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
