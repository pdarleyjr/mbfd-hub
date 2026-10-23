<?php

declare(strict_types=1);

namespace App\Services\HubSupport;

use App\Models\HubSupportTicket;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class HubSupportTicketRecoveryService
{
    public function trash(HubSupportTicket $ticket, User $actor, string $reason): HubSupportTicket
    {
        return $this->change($ticket, $actor, true, $reason);
    }

    public function restore(HubSupportTicket $ticket, User $actor): HubSupportTicket
    {
        return $this->change($ticket, $actor, false);
    }

    private function change(HubSupportTicket $ticket, User $actor, bool $trash, ?string $reason = null): HubSupportTicket
    {
        abort_unless($actor->can($trash ? 'delete' : 'restore', $ticket), 403);
        $reason = trim($reason ?? '');
        if ($trash) {
            validator(['reason' => $reason], ['reason' => ['required', 'string', 'max:2000']])->validate();
        }

        return DB::transaction(function () use ($ticket, $actor, $trash, $reason): HubSupportTicket {
            $locked = HubSupportTicket::withTrashed()->lockForUpdate()->findOrFail($ticket->id);
            abort_unless($actor->can($trash ? 'delete' : 'restore', $locked), 403);
            if ($locked->trashed() === $trash) {
                return $locked;
            }
            $metadata = ['event' => $trash ? 'trashed' : 'trash_restored', 'delete_reason' => $trash ? $reason : $locked->delete_reason];
            if ($trash) {
                $locked->forceFill(['deleted_by' => $actor->id, 'delete_reason' => $reason])->save();
                $locked->delete();
            } else {
                $locked->restore();
                $locked->forceFill(['deleted_by' => null, 'delete_reason' => null])->save();
            }
            $locked->updates()->create([
                'previous_status' => $locked->status, 'status' => $locked->status,
                'changed_by_user_id' => $actor->id,
                'internal_note' => $trash ? 'Moved to Trash. Reason: '.$reason : 'Restored from Trash. Original workflow and archive state retained.',
                'metadata' => $metadata,
            ]);

            return $locked->refresh();
        }, 3);
    }
}
