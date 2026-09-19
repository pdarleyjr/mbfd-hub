<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\HubSupportTicketStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property int $hub_support_ticket_id
 * @property HubSupportTicketStatus|null $previous_status
 * @property HubSupportTicketStatus $status
 * @property string|null $public_response
 * @property string|null $internal_note
 * @property int|null $changed_by_user_id
 * @property array<string, mixed>|null $metadata
 */
final class HubSupportTicketUpdate extends Model
{
    protected $fillable = [
        'hub_support_ticket_id', 'previous_status', 'status', 'public_response', 'internal_note',
        'changed_by_user_id', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'previous_status' => HubSupportTicketStatus::class,
            'status' => HubSupportTicketStatus::class,
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('Hub support ticket updates are append-only.'));
        self::deleting(static fn (): never => throw new LogicException('Hub support ticket updates are append-only.'));
    }

    /** @return BelongsTo<HubSupportTicket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(HubSupportTicket::class, 'hub_support_ticket_id');
    }

    /** @return BelongsTo<User, $this> */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
