<?php

namespace App\Providers;

use App\Models\Todo;
use App\Models\ChMessage;
use App\Models\Apparatus;
use App\Models\ApparatusInspection;
use App\Models\EvaluationSubmission;
use App\Models\FireEquipmentRequest;
use App\Models\StationInspection;
use App\Models\WorkgroupSharedUpload;
use App\Models\User;
use App\Notifications\NewSubmissionNotification;
use App\Observers\TodoObserver;
use App\Observers\ChMessageObserver;
use App\Observers\ApparatusObserver;
use App\Observers\WorkgroupSharedUploadObserver;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Facades\Gate;

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

        // Override Chatify's Pusher PHP SDK to use internal Reverb endpoint (127.0.0.1:8080)
        // while config('chatify.pusher') stays frontend-facing (www.mbfdhub.com:443) for the browser.
        $this->app->bind('ChatifyMessenger', function () {
            return new \App\Services\ChatifyMessengerOverride;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (str_contains(config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // Allow super_admin users to access the Laravel Pulse dashboard
        Gate::define('viewPulse', function (\App\Models\User $user) {
            return $user->hasRole('super_admin');
        });

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

        // ─── Submission Notification Triggers ──────────────────────────────
        // Dispatch NewSubmissionNotification (database + web push) when new
        // user-facing forms are submitted across the MBFD Hub platform.

        StationInspection::created(function (StationInspection $inspection) {
            $stationName = $inspection->station?->name ?? 'Unknown Station';
            $this->notifySubmissionRoles(
                ['super_admin', 'logistics_admin'],
                'station_inspection',
                'New Station Inspection Submitted',
                "A station inspection for {$stationName} has been submitted.",
                '/admin/station-inspections/' . $inspection->id,
            );
        });

        FireEquipmentRequest::created(function (FireEquipmentRequest $request) {
            $this->notifySubmissionRoles(
                ['super_admin', 'logistics_admin'],
                'fire_equipment_request',
                'New Fire Equipment Request',
                'A new fire equipment request has been submitted.',
                '/admin/fire-equipment-requests/' . $request->id,
            );
        });

        EvaluationSubmission::created(function (EvaluationSubmission $submission) {
            $productName = $submission->candidateProduct?->name ?? 'a product';
            $this->notifySubmissionRoles(
                ['super_admin', 'workgroup_facilitator'],
                'evaluation_submission',
                'New Evaluation Submitted',
                "An evaluation for {$productName} has been submitted.",
                '/admin/evaluation-submissions/' . $submission->id,
            );
        });

        ApparatusInspection::created(function (ApparatusInspection $inspection) {
            $unitNumber = $inspection->apparatus?->unit_number ?? 'Unknown';
            $this->notifySubmissionRoles(
                ['super_admin', 'logistics_admin'],
                'apparatus_inspection',
                'New Vehicle Inspection',
                "A vehicle inspection for unit {$unitNumber} has been submitted.",
                '/admin/apparatus-inspections/' . $inspection->id,
            );
        });
    }

    /**
     * Dispatch a NewSubmissionNotification to all users with the given roles.
     */
    private function notifySubmissionRoles(
        array $roles,
        string $submissionType,
        string $title,
        string $body,
        string $actionUrl,
    ): void {
        $recipients = User::whereHas('roles', fn ($q) => $q->whereIn('name', $roles))->get();

        if ($recipients->isEmpty()) {
            return;
        }

        \Illuminate\Support\Facades\Notification::send(
            $recipients,
            new NewSubmissionNotification(
                submissionType: $submissionType,
                title: $title,
                body: $body,
                actionUrl: $actionUrl,
            ),
        );
    }
}
