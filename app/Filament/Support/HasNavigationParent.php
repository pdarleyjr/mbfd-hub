<?php

declare(strict_types=1);

namespace App\Filament\Support;

/**
 * A Filament page that is a child of another page (a record detail opened from
 * a list) declares its logical parent so the shared Back control returns to
 * that parent instead of the panel root.
 *
 * PageNav still resolves Back through HubNavigation::backUrl(), so a validated
 * same-app return_to (for example the parent list with its filters and page)
 * wins over the declared parent, and Back never points at the current page.
 */
interface HasNavigationParent
{
    /** Same-app path of the parent page, without scheme or host (for example "/employee/my-vehicle-inspections"). */
    public static function navigationParentPath(): string;

    /** Back label used when Back resolves to the parent, for example "Back to my inspections". */
    public static function navigationParentLabel(): string;
}
