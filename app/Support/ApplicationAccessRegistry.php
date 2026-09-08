<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

final class ApplicationAccessRegistry
{
    /** @return array<string, array{label: string, permission: string|null, description: string}> */
    public function applications(): array
    {
        return [
            'admin' => ['label' => 'Hub administration', 'permission' => 'admin.access', 'description' => 'Admin panel access. Individual administration capabilities are managed separately. Bid currently also uses this access to determine its administrator role.'],
            'bid' => ['label' => 'Bid', 'permission' => 'app.bid.access', 'description' => 'Hub sign-in to Bid. Bid administrator status follows Hub administration access.'],
            'media_control' => ['label' => 'Media Control administration', 'permission' => 'app.media_control.access', 'description' => 'The current handshake grants platform administrator access to an existing linked Media Control account. Revocation blocks new Hub handoffs; an already-issued Media Control session may remain active for up to 15 minutes.'],
            'cmd' => ['label' => 'CMD — cmd.mbfdhub.com', 'permission' => 'app.cmd.access', 'description' => 'Hub sign-in through the configured CMD client. Current account status and CMD access are checked during use.'],
            'cloud' => ['label' => 'Cloud — cloud.mbfdhub.com', 'permission' => 'app.cloud.access', 'description' => 'Requires a separately approved link to the existing Cloud account. Account and device-token changes synchronize through the audited Cloud lifecycle.'],
        ];
    }

    /** @return array<string, string> */
    public function applicationOptions(): array
    {
        $options = [];
        foreach ($this->applications() as $key => $application) {
            if ($application['permission'] !== null) {
                $options[$key] = $application['label'];
            }
        }

        return $options;
    }

    /** @return array<string, string> */
    public function capabilityOptions(): array
    {
        $options = [];
        foreach ([
            'members' => 'Members', 'fleet' => 'Fleet', 'stations' => 'Stations',
            'equipment' => 'Equipment', 'personnel' => 'Personnel', 'training' => 'Training',
            'workgroups' => 'Workgroups', 'forms' => 'Forms', 'projects' => 'Projects',
            'notifications' => 'Notifications', 'communications' => 'Communications',
            'system' => 'System', 'department_updates' => 'Department updates',
        ] as $key => $label) {
            foreach (['view' => 'view', 'manage' => 'manage'] as $action => $actionLabel) {
                if ($key === 'communications' && $action === 'manage') {
                    continue;
                }
                $options['admin.'.$key.'.'.$action] = $label.' — '.$actionLabel;
            }
        }
        $options['admin.members.security'] = 'Members — account security';
        $options['admin.communications.send'] = 'Communications — send email';

        return $options;
    }

    /** @return list<string> */
    public function selectedApplications(User $user): array
    {
        $selected = [];
        foreach ($this->applications() as $key => $application) {
            if ($application['permission'] !== null && $user->hasDirectWebPermission($application['permission'])) {
                $selected[] = $key;
            }
        }

        return $selected;
    }

    public function cloudEnforcementStatus(User $user): string
    {
        if (! config('nextcloud_identity.enabled')) {
            return 'Cloud enforcement is not activated. A saved Hub grant does not confirm remote account or device-token changes.';
        }
        $link = \App\Models\OidcAccountLink::query()->where('application', 'cloud')->where('user_id', $user->id)->first();
        $sync = \App\Models\NextcloudAccessSync::query()->where('user_id', $user->id)->first();
        if ($link === null) {
            return 'No approved Cloud account link. Any previously linked account still requires remote reconciliation.';
        }
        if ($sync !== null && $link->external_uid !== $sync->external_uid) {
            return 'Mapping conflict — enforcement targets the previously linked account; the replacement account is not approved for synchronization.';
        }
        $current = $user->fresh();
        $desiredEnabled = $current !== null && app(\App\Services\Oidc\CloudIdentityAccess::class)->forUser($current) !== null;
        if ($sync === null || $sync->verified_at === null || $sync->requested_revision !== $sync->applied_revision
            || $sync->desired_enabled !== $desiredEnabled || $sync->security_version !== $current?->security_version || filled($sync->last_error)) {
            return 'Pending remote Cloud enforcement.'.($sync !== null && filled($sync->last_error) ? ' The last attempt failed; reconciliation is queued for retry.' : ' Remote account and device-token changes have not yet been confirmed.');
        }

        return 'Last remote verification: '.($sync->desired_enabled ? 'enabled' : 'disabled').' at '.$sync->verified_at->utc()->format('Y-m-d H:i:s').' UTC. Reconciliation is periodic, not instantaneous.';
    }

    /** @return array<string, array{allowed: bool, operational: bool, status: string}> */
    public function states(User $user): array
    {
        $current = $user->fresh();
        $states = [];
        foreach ($this->applications() as $key => $application) {
            $operational = $application['permission'] !== null && (! in_array($key, ['cmd', 'cloud'], true) || filled(config('oidc.clients.'.$key)));
            $entitled = $current !== null && match ($key) {
                'admin' => $current->hasCurrentAdminPanelEntitlement(),
                'bid' => $current->hasCurrentBidEntitlement(),
                'media_control' => $current->hasCurrentMediaControlEntitlement(),
                'cmd' => $current->hasRole('super_admin') || $current->hasDirectWebPermission('app.cmd.access'),
                'cloud' => app(\App\Services\Oidc\CloudIdentityAccess::class)->forUser($current) !== null,
                default => false,
            };
            $active = $current?->isAuthenticationAllowed() === true;
            $states[$key] = [
                'allowed' => $entitled && $active && $operational,
                'operational' => $operational,
                'status' => ! $operational ? 'SSO client not configured — access unavailable' : (! $active ? 'Account inactive — access blocked' : ($key === 'cloud' && ! $entitled ? 'Cloud grant or approved account link missing' : ($current->hasRole('super_admin') ? 'Inherited from Super Administrator — managed through roles' : ($entitled ? 'Direct access granted' : 'No direct access')))),
            ];
        }

        return $states;
    }
}
