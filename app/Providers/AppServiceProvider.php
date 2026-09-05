<?php

namespace App\Providers;

use App\Contracts\VideoConferencing\ConferenceProvider;
use App\Models\Apparatus;
use App\Models\ApparatusInspection;
use App\Models\EvaluationSubmission;
use App\Models\StationInspection;
use App\Models\StationInventorySubmission;
use App\Models\Todo;
use App\Models\Training\TrainingTodo;
use App\Models\User;
use App\Models\WorkgroupSharedUpload;
use App\Notifications\NewSubmissionNotification;
use App\Observers\ApparatusObserver;
use App\Observers\TodoObserver;
use App\Observers\TrainingTodoObserver;
use App\Observers\WorkgroupSharedUploadObserver;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ConferenceProvider::class, \App\Services\VideoConferencing\LiveKitConferenceProvider::class);

        $this->app->bind(
            \Filament\Http\Responses\Auth\Contracts\LoginResponse::class,
            \App\Http\Responses\LoginResponse::class,
        );

        // Route command-center / capital-project AI to the on-prem Ollama
        // (qwen3.6:35b) when AI_DRIVER=local. All callers resolve
        // CloudflareAIService via the container, so this one binding repoints
        // SmartUpdatesWidget, CapitalProject analysis, and the summary commands.
        $this->app->bind(\App\Services\CloudflareAIService::class, function () {
            return config('cloudflare.ai.driver') === 'local'
                ? new \App\Services\LocalAIService
                : new \App\Services\CloudflareAIService;
        });

    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::before(function (User $user, string $ability, array $arguments): ?bool {
            if ($user->hasRole('super_admin')) {
                return true;
            }

            return app(\App\Services\Security\AdminCapabilityGate::class)
                ->decision($user, $ability, $arguments);
        });

        RateLimiter::for('conference-tokens', function (Request $request): array {
            $user = $request->user();
            $employeeId = $user instanceof User ? $user->employee_profile_id : null;
            $launchContext = $request->input('launch_context');
            $subject = $employeeId !== null
                ? 'employee:'.$employeeId
                : (is_string($launchContext) && $launchContext !== ''
                    ? 'launch:'.hash('sha256', $launchContext)
                    : 'ip:'.$request->ip());

            return [
                Limit::perMinute(12)->by('conference-token:'.$subject),
                Limit::perMinute(60)->by('conference-ip:'.$request->ip()),
            ];
        });
        RateLimiter::for('conference-controls', function (Request $request): array {
            $user = $request->user();
            $employeeId = $user instanceof User ? $user->employee_profile_id : null;
            $launchContext = $request->input('launch_context');
            $subject = $employeeId !== null
                ? 'employee:'.$employeeId
                : (is_string($launchContext) && $launchContext !== ''
                    ? 'launch:'.hash('sha256', $launchContext)
                    : 'ip:'.$request->ip());

            return [
                Limit::perMinute(60)->by('conference-controls:'.$subject),
                Limit::perMinute(300)->by('conference-controls-ip:'.$request->ip()),
            ];
        });

        if (str_contains(config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // Super Administrators bypass this Gate; delegated system viewers use
        // the same normalized permission as the Filament page and navigation.
        Gate::define('viewPulse', function (\App\Models\User $user) {
            return $user->hasDirectWebPermission('admin.system.view');
        });

        Todo::observe(TodoObserver::class);
        TrainingTodo::observe(TrainingTodoObserver::class);
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

        $this->registerPushNotificationWidgetAssets();

        // ─── Submission Notification Triggers ──────────────────────────────
        // Dispatch NewSubmissionNotification (database + web push) when new
        // user-facing forms are submitted across the MBFD Hub platform.

        StationInspection::created(function (StationInspection $inspection) {
            $stationName = $inspection->station?->name ?? 'Unknown Station';
            $this->notifySubmissionRoles(
                'station_inspection',
                'New Station Inspection Submitted',
                "A station inspection for {$stationName} has been submitted.",
                '/admin/station-inspections/'.$inspection->id,
            );
        });

        EvaluationSubmission::created(function (EvaluationSubmission $submission) {
            $productName = $submission->candidateProduct?->name ?? 'a product';
            $this->notifySubmissionRoles(
                'evaluation_submission',
                'New Evaluation Submitted',
                "An evaluation for {$productName} has been submitted.",
                '/admin/evaluation-submissions/'.$submission->id,
            );
        });

        ApparatusInspection::created(function (ApparatusInspection $inspection) {
            $unitName = $inspection->designation_at_time
                ?? $inspection->unit_number
                ?? $inspection->apparatus?->designation
                ?? 'Unknown';
            $this->notifySubmissionRoles(
                'apparatus_inspection',
                'New Vehicle Inspection',
                "A vehicle inspection for {$unitName} has been submitted.",
                '/admin/inspections/'.$inspection->id,
            );
        });

        StationInventorySubmission::created(function (StationInventorySubmission $submission) {
            $stationName = $submission->station?->station_number ?? 'Unknown Station';
            $employeeName = $submission->employee_name ?: 'Unknown employee';
            $shift = $submission->shift ?: 'Unknown shift';

            $this->notifySubmissionRoles(
                'station_inventory_submission',
                'New Station Inventory Submission',
                "Station {$stationName} submitted an inventory alert for {$shift} shift by {$employeeName}.",
                '/admin/stations/'.$submission->station_id.'?activeRelationManager=inventoryItems',
            );
        });
    }

    /**
     * Register browser-only assets without making Artisan commands depend on a
     * generated Vite manifest. Deployment separately proves and atomically
     * activates that manifest before serving web traffic.
     */
    protected function registerPushNotificationWidgetAssets(): void
    {
        if ($this->app->runningInConsole()) {
            return;
        }

        FilamentAsset::register([
            Js::make('push-notification-widget', Vite::asset('resources/js/push-notification-widget.js')),
        ]);
    }

    /**
     * Dispatch a NewSubmissionNotification to explicitly subscribed users.
     */
    private function notifySubmissionRoles(
        string $submissionType,
        string $title,
        string $body,
        string $actionUrl,
    ): void {
        $preferenceKey = User::preferenceKeyForSubmissionType($submissionType);

        if ($preferenceKey === null) {
            return;
        }

        $recipients = User::query()
            ->whereHas('notificationSubscriptions', fn ($query) => $query
                ->where('event_key', $preferenceKey)
                ->where(fn ($channels) => $channels
                    ->where('database_enabled', true)
                    ->orWhere('webpush_enabled', true)
                    ->orWhere('email_enabled', true)))
            ->get();

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
