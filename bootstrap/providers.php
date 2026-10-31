<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\HubOidcServiceProvider::class,
    App\Providers\HealthServiceProvider::class,
    App\Providers\ConferenceRateLimitServiceProvider::class,
    App\Providers\NotificationServiceProvider::class,
    App\Providers\MailServiceProvider::class,
    App\Providers\DashboardServiceProvider::class,
    App\Providers\ResponsiveTablesServiceProvider::class,
    App\Providers\Filament\AdminPanelProvider::class,
    App\Providers\Filament\TrainingPanelProvider::class,
    App\Providers\Filament\WorkgroupPanelProvider::class,
    App\Providers\Filament\EmployeePanelProvider::class,
    Mbfd\PolicyLibrary\PolicyLibraryServiceProvider::class,
];
