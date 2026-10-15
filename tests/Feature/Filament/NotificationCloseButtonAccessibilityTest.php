<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/** Every Filament toast notification's close button must have an accessible name. */
final class NotificationCloseButtonAccessibilityTest extends TestCase
{
    public function test_notification_close_button_is_named(): void
    {
        $html = Blade::render('<x-filament-notifications::close-button />');

        $this->assertStringContainsString('fi-no-notification-close-btn', $html);
        $this->assertStringContainsString('Dismiss notification', $html);
    }
}
