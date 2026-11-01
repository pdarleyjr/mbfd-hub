<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use App\Services\Mail\MailAccess;
use App\Services\Security\DailyRosterAccess;
use Filament\Facades\Filament;

final class HubShellNavigation
{
    /**
     * @param  array<string, array<string, mixed>>|null  $applicationStates
     * @return array{applications: list<array{key: string, label: string, href: string}>, memberNavigation: list<array{key: string, label: string, href: string}>, moreNavigation: list<array{key: string, label: string, href: string}>, account: array{name: string, href: string}|null}
     */
    public static function forUser(?User $user, ?array $applicationStates = null): array
    {
        $navigation = [
            'applications' => [],
            'memberNavigation' => [['key' => 'home', 'label' => 'Home', 'href' => '/']],
            'moreNavigation' => [],
            'account' => null,
        ];
        if ($user === null) {
            return $navigation;
        }

        $states = $applicationStates ?? app(ApplicationAccessRegistry::class)->states($user, ['admin', 'bid', 'media_control', 'meeting']);
        foreach ([
            'admin' => ['label' => 'Admin', 'href' => '/admin'],
            'bid' => ['label' => 'Bid', 'href' => 'https://bid.mbfdhub.com/api/auth/start'],
            'media_control' => ['label' => 'Media Control', 'href' => 'https://media.mbfdhub.com/api/auth/hub/start'],
            'meeting' => ['label' => 'Meeting Intelligence', 'href' => 'https://meet.mbfdhub.com/'],
        ] as $key => $application) {
            if (self::launchable($key, $states)) {
                $navigation['applications'][] = ['key' => $key, ...$application];
                // Email is part of the admin panel, so it needs the same entitlement as Admin and its own read
                // level on top: a link that would answer 403 is never offered. It rides this one builder, so the
                // Home menu, the phone More sheet and the React shell all show it.
                if ($key === 'admin' && app(MailAccess::class)->canView($user)) {
                    $navigation['applications'][] = ['key' => 'email', 'label' => 'Email', 'href' => '/admin/email'];
                }
            }
        }
        $canAccessEmployeePanel = false;
        foreach (['employee' => 'Employee', 'workgroups' => 'Workgroups', 'training' => 'Training'] as $key => $label) {
            if ($user->canAccessPanel(Filament::getPanel($key))) {
                $navigation['applications'][] = ['key' => $key, 'label' => $label, 'href' => '/'.$key];
                if ($key === 'employee') {
                    $canAccessEmployeePanel = true;
                    $navigation['memberNavigation'] = [...$navigation['memberNavigation'],
                        ['key' => 'checkout', 'label' => 'Checkout', 'href' => '/daily/stations'],
                        ['key' => 'employee', 'label' => 'Employee', 'href' => '/employee'],
                        ['key' => 'requests', 'label' => 'Requests', 'href' => '/employee/my-requests'],
                    ];
                }
            }
        }
        // Home and Daily Checkout open the app switcher in every shell (Blade, Filament, React), so the list is
        // built once here. Daily Checkout follows the same Employee panel rule as the Checkout tab and Home button.
        $leading = [['key' => 'home', 'label' => 'Home', 'href' => '/']];
        if ($canAccessEmployeePanel) {
            $leading[] = ['key' => 'daily', 'label' => 'Daily Checkout', 'href' => '/daily/stations'];
        }
        $navigation['applications'] = [...$leading, ...$navigation['applications']];
        $navigation['account'] = ['name' => (string) ($user->display_name ?: $user->name), 'href' => route('account.show', absolute: false)];
        $navigation['moreNavigation'] = [
            ['key' => 'updates', 'label' => 'Department Updates', 'href' => route('updates.index', absolute: false)],
            ['key' => 'support', 'label' => 'Support', 'href' => route('hub-support.index', absolute: false)],
        ];
        // Never offered where it would answer 403 or lead to an empty page. Home keeps its own entry.
        if (app(DailyRosterAccess::class)->canSeeMenuEntry($user)) {
            $navigation['moreNavigation'][] = [
                'key' => 'daily-roster',
                'label' => 'Daily Roster',
                'href' => route('daily-roster.show', absolute: false),
            ];
        }
        // Members with an employee profile reach the inspection through Employee > Forms.
        // Accounts without a profile cannot open that panel, so they keep a direct entry here.
        if (! $canAccessEmployeePanel) {
            $navigation['moreNavigation'][] = [
                'key' => 'take-home-inspection',
                'label' => 'Take Home Inspection',
                'href' => route('take-home-inspections.index', absolute: false),
            ];
        }

        return $navigation;
    }

    /**
     * Applications whose consumer enforcement is still being rolled out stay
     * hidden until deployment marks their Hub sign-in as verified.
     *
     * @param  array<string, array<string, mixed>>  $states
     */
    private static function launchable(string $key, array $states): bool
    {
        if (! ($states[$key]['allowed'] ?? false)) {
            return false;
        }

        return $key !== 'meeting' || config('application_access.runtime_verified.'.$key) === true;
    }
}
