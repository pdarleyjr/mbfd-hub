<?php

declare(strict_types=1);

namespace App\Services\Cloud;

use App\Models\NextcloudAccessSync;
use App\Models\OidcAccountLink;
use App\Models\User;
use App\Services\Oidc\CloudIdentityAccess;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class NextcloudAccountSynchronizer
{
    public function __construct(private readonly NextcloudIdentityBridge $bridge, private readonly CloudIdentityAccess $access) {}

    public function request(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $current = User::query()->lockForUpdate()->findOrFail($user->id);
            $link = OidcAccountLink::query()->where('application', 'cloud')->where('user_id', $current->id)->first();
            $row = NextcloudAccessSync::query()->where('user_id', $current->id)->lockForUpdate()->first();
            if ($link === null && $row === null) {
                return;
            }
            $enabled = $this->access->forUser($current) !== null;
            if ($row !== null && $link !== null && $row->external_uid !== $link->external_uid) {
                // Never follow a changed mapping into another person's files.
                // Revoke the previously approved account until separately reviewed.
                $enabled = false;
            }
            if ($row === null) {
                NextcloudAccessSync::create(['user_id' => $current->id, 'external_uid' => $link->external_uid,
                    'desired_enabled' => $enabled, 'security_version' => $current->security_version,
                    'requested_revision' => 1, 'applied_revision' => 0]);
            } elseif ($row->desired_enabled !== $enabled || $row->security_version !== $current->security_version) {
                $row->forceFill(['desired_enabled' => $enabled, 'security_version' => $current->security_version,
                    'requested_revision' => $row->requested_revision + 1, 'verified_at' => null])->save();
            }
        });
    }

    public function synchronize(int $userId): bool
    {
        if (! config('nextcloud_identity.enabled')) {
            return false;
        }

        return DB::transaction(function () use ($userId): bool {
            // Use the same User -> outbox lock order as account administration.
            $user = User::query()->lockForUpdate()->findOrFail($userId);
            $this->request($user);
            $row = NextcloudAccessSync::query()->where('user_id', $userId)->lockForUpdate()->first();
            if ($row === null) {
                return true;
            }
            try {
                $ack = $this->bridge->reconcile($row->external_uid, $row->requested_revision, $row->desired_enabled);
                if (($ack['uid'] ?? null) !== $row->external_uid || ($ack['revision'] ?? null) !== $row->requested_revision
                    || ($ack['enabled'] ?? null) !== $row->desired_enabled || ($ack['old_tokens_purged'] ?? null) !== true) {
                    throw new RuntimeException('Cloud acknowledgement did not match the requested identity and state.');
                }
                $row->forceFill(['applied_revision' => $row->requested_revision, 'verified_at' => now(), 'last_error' => null])->save();

                return true;
            } catch (Throwable) {
                // Hub denial stays committed. Never turn a timeout into an assertion
                // that Cloud was disabled; the durable row remains pending for retry.
                $row->forceFill(['verified_at' => null, 'last_error' => 'Cloud account synchronization is pending; remote enforcement was not verified.'])->save();

                return false;
            }
        });
    }
}
