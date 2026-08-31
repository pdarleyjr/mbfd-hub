<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\SecurityActionEvent;
use App\Models\User;

final class SecurityAuditRecorder
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        User $actor,
        User $target,
        string $action,
        string $result,
        ?string $reason = null,
        array $metadata = [],
    ): void {
        SecurityActionEvent::query()->create([
            'actor_user_id' => $actor->getKey(),
            'target_user_id' => $target->getKey(),
            'action' => $action,
            'result' => $result,
            'reason' => $reason,
            'metadata' => $metadata,
        ]);
    }
}
