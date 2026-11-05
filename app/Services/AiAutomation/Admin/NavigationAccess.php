<?php

declare(strict_types=1);

namespace App\Services\AiAutomation\Admin;

use App\Services\AiAutomation\AiAutomationAccess;

/**
 * Whether to show the Support Services Automation menu group. Answered once per request and remembered on the
 * request itself, so it can never outlive the request or leak between two people. Only the menu uses this;
 * every screen and action checks AiAutomationAccess directly.
 */
final class NavigationAccess
{
    private const KEY = 'support_automation_menu:';

    public static function allowed(): bool
    {
        $user = auth()->user();
        $attributes = request()->attributes;
        $key = self::KEY.($user !== null ? (string) $user->getAuthIdentifier() : 'guest');

        if (! $attributes->has($key)) {
            $attributes->set($key, AiAutomationAccess::allowsCurrentUser());
        }

        return (bool) $attributes->get($key);
    }
}
