<?php

declare(strict_types=1);

namespace App\Support\Home;

use App\Models\User;
use App\Services\Narcotics\NarcoticsCheckAccess;
use App\Services\Security\Command300Access;
use App\Support\Ems\EmsLinks;
use App\Support\Narcotics\NarcoticsLinks;

/**
 * What the member Home offers beyond the fixed tiles: the Rescue Forms shortcuts and the Command Board button.
 *
 * Nothing here decides access on its own. Each entry is shown only when the class that already guards the
 * destination agrees, and every entry is a plain link to a page that re-checks authorization on arrival.
 */
final class HomeQuickActions
{
    /** The Daily app's vehicle inspection landing (the same address the Vehicle Checkout button uses). */
    public const VEHICLE_INSPECTION_PATH = '/daily/vehicle-inspections';

    /**
     * The Rescue Forms this member may open, in display order.
     *
     * - Medical Inventory Forms and Vehicle Inspection: the Employee Portal audience (a linked personnel profile),
     *   which is what the Forms page and the Vehicle Checkout button already require.
     * - Narcotics Check: additionally NarcoticsCheckAccess::canSubmit() (feature switch and usable account),
     *   the same test the Forms page uses before it offers the card.
     *
     * @return list<array{key: string, label: string, href: string}>
     */
    public static function rescueForms(?User $user): array
    {
        if (! $user instanceof User || ! $user->employeeProfile()->exists()) {
            return [];
        }

        $links = [[
            'key' => 'medical-inventory',
            'label' => 'Medical Inventory Forms',
            'href' => url(EmsLinks::memberForm()),
        ]];

        if (app(NarcoticsCheckAccess::class)->canSubmit($user)) {
            $links[] = [
                'key' => 'narcotics-check',
                'label' => 'Narcotics Check',
                'href' => url(NarcoticsLinks::memberForm()),
            ];
        }

        $links[] = [
            'key' => 'vehicle-inspection',
            'label' => 'Vehicle Inspection',
            'href' => url(self::VEHICLE_INSPECTION_PATH),
        ];

        return $links;
    }

    /**
     * Where the Command Board button goes, or null when this member may not use the board.
     *
     * The button opens the board's own front door (the same address the 300 profile opens); the board signs
     * the member in through Hub sign-in (/oauth/authorize), which asks Command300Access again and turns an
     * ineligible member away. Hiding the button is a courtesy, never the control.
     */
    public static function commandBoardUrl(?User $user): ?string
    {
        if (! app(Command300Access::class)->allows($user)) {
            return null;
        }

        $url = trim((string) config('services.command_board.url'));

        return str_starts_with(strtolower($url), 'https://') && is_string(parse_url($url, PHP_URL_HOST)) ? $url : null;
    }
}
