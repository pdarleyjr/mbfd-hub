<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
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

        $states = $applicationStates ?? app(ApplicationAccessRegistry::class)->states($user);
        foreach ([
            'admin' => ['label' => 'Admin', 'href' => '/admin'],
            'bid' => ['label' => 'Bid', 'href' => 'https://bid.mbfdhub.com/api/auth/start'],
            'media_control' => ['label' => 'Media Control', 'href' => 'https://media.mbfdhub.com/api/auth/hub/start'],
            'cmd' => ['label' => 'Command Board', 'href' => self::commandBoardUrl() ?? ''],
            'meeting' => ['label' => 'Meeting Intelligence', 'href' => 'https://meet.mbfdhub.com/'],
        ] as $key => $application) {
            if ($application['href'] !== '' && self::launchable($key, $states)) {
                $navigation['applications'][] = ['key' => $key, ...$application];
            }
        }
        foreach (['employee' => 'Employee', 'workgroups' => 'Workgroups', 'training' => 'Training'] as $key => $label) {
            if ($user->canAccessPanel(Filament::getPanel($key))) {
                $navigation['applications'][] = ['key' => $key, 'label' => $label, 'href' => '/'.$key];
                if ($key === 'employee') {
                    $navigation['memberNavigation'] = [...$navigation['memberNavigation'],
                        ['key' => 'checkout', 'label' => 'Checkout', 'href' => '/daily/stations'],
                        ['key' => 'employee', 'label' => 'Employee', 'href' => '/employee'],
                        ['key' => 'requests', 'label' => 'Requests', 'href' => '/employee/my-requests'],
                    ];
                }
            }
        }
        $navigation['account'] = ['name' => (string) ($user->display_name ?: $user->name), 'href' => route('account.show', absolute: false)];
        $navigation['moreNavigation'] = [
            ['key' => 'updates', 'label' => 'Updates', 'href' => route('updates.index', absolute: false)],
            ['key' => 'support', 'label' => 'Support', 'href' => route('hub-support.index', absolute: false)],
        ];

        return $navigation;
    }

    /** The Command Board origin members are sent to (COMMAND_BOARD_URL), or null when it is not a usable https URL. */
    public static function commandBoardUrl(): ?string
    {
        $url = trim((string) config('services.command_board.url'));

        return str_starts_with(strtolower($url), 'https://') && is_string(parse_url($url, PHP_URL_HOST)) ? $url : null;
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

        return ! in_array($key, ['cmd', 'meeting'], true) || config('application_access.runtime_verified.'.$key) === true;
    }
}
