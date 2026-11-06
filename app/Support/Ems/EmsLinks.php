<?php

declare(strict_types=1);

namespace App\Support\Ems;

use App\Services\Ems\FormFamilies;

/**
 * Where a notification opens. The pages are built on the JSON API; these are the one
 * place to repoint a link when the final routes are chosen.
 *
 * The member links open the Employee Portal Forms app (OperationalForms page): `form=<form_key>` opens a new request on
 * one of the four medical inventory forms (App\Services\Ems\FormFamilies; add `view=mine` for the member's own list), the
 * old `form=medical-inventory-request` still opens ALS Transport, and `ems_request=<uuid>` opens one request (which opens
 * in its own form). `OperationalForms::emsDeepLink()` is the server side that accepts these parameters.
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

    /**
     * A new request in the member's Forms app. With a family key (FormFamilies::*) it opens that form; with none, or one this
     * release does not know, it is the original link (ALS Transport), which every older notification and bookmark uses.
     */
    public static function memberForm(?string $family = null): string
    {
        $formKey = FormFamilies::find($family)['form_key'] ?? self::MEMBER_FORM_KEY;

        return self::MEMBER_FORMS.'?form='.$formKey;
    }

    /** The member's own list of medical inventory requests. */
    public static function memberRequests(): string
    {
        return self::MEMBER_FORMS.'?form='.self::MEMBER_FORM_KEY.'&view=mine';
    }
}
