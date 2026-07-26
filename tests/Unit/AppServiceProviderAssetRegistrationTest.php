<?php

namespace Tests\Unit;

use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;

class AppServiceProviderAssetRegistrationTest extends TestCase
{
    public function test_console_boot_does_not_resolve_the_vite_manifest(): void
    {
        $application = $this->createMock(Application::class);
        $application->expects($this->once())
            ->method('runningInConsole')
            ->willReturn(true);

        $provider = new class($application) extends AppServiceProvider
        {
            public function registerPushNotificationWidgetAssetsForTest(): void
            {
                $this->registerPushNotificationWidgetAssets();
            }
        };

        // Vite and Filament facades are deliberately not initialized. Any
        // attempt to resolve the manifest in console mode makes this test fail.
        $provider->registerPushNotificationWidgetAssetsForTest();
        $this->addToAssertionCount(1);
    }
}
