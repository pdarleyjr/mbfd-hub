<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Support;

use Illuminate\Support\Facades\DB;

final class Audit
{
    public static function record(string $action, ?int $userId, array $ids = [], array $metadata = []): void
    {
        DB::table('policy_audit_events')->insert([
            'action' => $action, 'user_id' => $userId,
            'manual_id' => $ids['manual_id'] ?? null, 'node_id' => $ids['node_id'] ?? null,
            'revision_id' => $ids['revision_id'] ?? null,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }
}
