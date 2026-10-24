<?php

declare(strict_types=1);

namespace App\Support\Ems;

/**
 * Where a notification opens. The pages are built on the JSON API; these are the one
 * place to repoint a link when the final routes are chosen.
 *
 * The member links open the Employee Portal Forms app (OperationalForms page): `form=medical-inventory-request`
 * opens a new Medical Inventory Request (add `view=mine` for the member's own list) and `ems_request=<uuid>`
 * opens one request. `OperationalForms::emsDeepLink()` is the server side that accepts these parameters.
 */
final class EmsLinks
{
    public const STAFF_WORKSPACE = '/daily/captain-5';

    public const ADMIN_REQUESTS = '/admin/ems/requests';

    public const MEMBER_FORMS = '/employee/forms';

    public const MEMBER_FORM_KEY = 'medical-inventory-request';

    public static function staff(string $uuid): string
    {
        return self::STAFF_WORKSPACE.'?tab=rescue&request='.$uuid;
    }

    public static function admin(string $uuid): string
    {
        return self::ADMIN_REQUESTS.'?request='.$uuid;
    }

    public static function member(string $uuid): string
    {
        return self::MEMBER_FORMS.'?ems_request='.$uuid;
    }

    /** A new Medical Inventory Request in the member's Forms app. */
    public static function memberForm(): string
    {
        return self::MEMBER_FORMS.'?form='.self::MEMBER_FORM_KEY;
    }

    /** The member's own list of medical inventory requests. */
    public static function memberRequests(): string
    {
        return self::MEMBER_FORMS.'?form='.self::MEMBER_FORM_KEY.'&view=mine';
    }
}
