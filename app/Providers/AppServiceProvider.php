<?php

namespace App\Providers;

use App\Models\Todo;
use App\Models\ChMessage;
use App\Observers\TodoObserver;
use App\Observers\ChMessageObserver;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Vite;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            \Filament\Http\Responses\Auth\Contracts\LoginResponse::class,
            \App\Http\Responses\LoginResponse::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (str_contains(config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }
        
        Todo::observe(TodoObserver::class);
        ChMessage::observe(ChMessageObserver::class);

        // Register push notification widget JavaScript
        FilamentAsset::register([
            Js::make('push-notification-widget', Vite::asset('resources/js/push-notification-widget.js')),
        ]);
    }
}
