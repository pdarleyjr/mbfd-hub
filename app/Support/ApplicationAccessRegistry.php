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
            'cmd' => ['label' => 'CMD — cmd.mbfdhub.com', 'permission' => null, 'description' => 'Integration pending. Access is managed outside Hub until an authorization integration is available.'],
            'cloud' => ['label' => 'Cloud — cloud.mbfdhub.com', 'permission' => null, 'description' => 'Integration pending. Access is managed outside Hub until an authorization integration is available.'],
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

    /** @return array<string, array{allowed: bool, operational: bool, status: string}> */
    public function states(User $user): array
    {
        $current = $user->fresh();
        $states = [];
        foreach ($this->applications() as $key => $application) {
            $operational = $application['permission'] !== null;
            $entitled = $current !== null && match ($key) {
                'admin' => $current->hasCurrentAdminPanelEntitlement(),
                'bid' => $current->hasCurrentBidEntitlement(),
                'media_control' => $current->hasCurrentMediaControlEntitlement(),
                default => false,
            };
            $active = $current?->isAuthenticationAllowed() === true;
            $states[$key] = [
                'allowed' => $entitled && $active,
                'operational' => $operational,
                'status' => ! $operational ? 'Integration pending — unavailable in Hub' : (! $active ? 'Account inactive — access blocked' : ($current->hasRole('super_admin') ? 'Inherited from Super Administrator — managed through roles' : ($entitled ? 'Direct access granted' : 'No direct access'))),
            ];
        }

        return $states;
    }
}
