<?php

namespace App\Providers;

use App\Models\Todo;
use App\Models\ChMessage;
use App\Models\Apparatus;
use App\Models\EvaluationSubmission;
use App\Models\WorkgroupSharedUpload;
use App\Observers\TodoObserver;
use App\Observers\ChMessageObserver;
use App\Observers\ApparatusObserver;
use App\Observers\WorkgroupSharedUploadObserver;
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
        Apparatus::observe(ApparatusObserver::class);
        
        // Auto-vectorize uploaded workgroup files (PDFs, DOCX, etc.) into workgroup-specs index
        WorkgroupSharedUpload::observe(WorkgroupSharedUploadObserver::class);

        // Clear product AI analysis cache when a new evaluation is submitted/updated
        // so the next export generates fresh analysis
        EvaluationSubmission::updated(function (EvaluationSubmission $submission) {
            if ($submission->wasChanged('status') && $submission->status === 'submitted') {
                $cacheKey = "workgroup_ai_product_{$submission->candidate_product_id}";
                \Illuminate\Support\Facades\Cache::forget($cacheKey);
            }
        });

        // Register push notification widget JavaScript
        FilamentAsset::register([
            Js::make('push-notification-widget', Vite::asset('resources/js/push-notification-widget.js')),
        ]);
    }
}
