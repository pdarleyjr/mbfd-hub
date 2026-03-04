<?php

namespace App\Providers;

use App\Events\EvaluationSubmitted;
use App\Listeners\SendEvaluationToAiWorker;
use App\Models\Todo;
use App\Models\ChMessage;
use App\Models\Apparatus;
use App\Models\WorkgroupFile;
use App\Observers\TodoObserver;
use App\Observers\ChMessageObserver;
use App\Observers\ApparatusObserver;
use App\Observers\WorkgroupFileObserver;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Vite;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            \Filament\Http\Responses\Auth\Contracts\LoginResponse::class,
            \App\Http\Responses\LoginResponse::class,
        );
    }

    public function boot(): void
    {
        if (str_contains(config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }
        
        Todo::observe(TodoObserver::class);
        ChMessage::observe(ChMessageObserver::class);
        Apparatus::observe(ApparatusObserver::class);
        WorkgroupFile::observe(WorkgroupFileObserver::class);

        // Register event listener for evaluation submissions
        Event::listen(EvaluationSubmitted::class, SendEvaluationToAiWorker::class);

        // Register push notification widget JavaScript
        FilamentAsset::register([
            Js::make('push-notification-widget', Vite::asset('resources/js/push-notification-widget.js')),
        ]);
    }
}
