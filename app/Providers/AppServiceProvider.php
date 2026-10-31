<?php

namespace App\Providers;

use App\Contracts\VideoConferencing\ConferenceProvider;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\EvaluationSubmission;
use App\Models\StationInspection;
use App\Models\StationInventorySubmission;
use App\Models\Todo;
use App\Models\Training\TrainingTodo;
use App\Models\UnitMasterVehicle;
use App\Models\User;
use App\Models\WorkgroupSharedUpload;
use App\Notifications\NewSubmissionNotification;
use App\Observers\ApparatusDefectObserver;
use App\Observers\ApparatusMasterLinkObserver;
use App\Observers\ApparatusObserver;
use App\Observers\TodoObserver;
use App\Observers\TrainingTodoObserver;
use App\Observers\UnitMasterVehicleSyncObserver;
use App\Observers\UpstreamIdentityObserver;
use App\Observers\WorkgroupSharedUploadObserver;
use Filament\Forms\Components\DateTimePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Laravel\Pulse\Facades\Pulse;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One messaging access decision per user per request (navigation, badge and page all ask).
        $this->app->scoped(\App\Services\Security\EmployeeLogisticsMessagingAccess::class);
        // Lucide icons Heroicons lacks (e.g. the EMS navigation group's medical bag), as lucide-* blade icons.
        $this->callAfterResolving(\BladeUI\Icons\Factory::class, static function (\BladeUI\Icons\Factory $icons): void {
            $icons->add('lucide', ['path' => resource_path('svg/lucide'), 'prefix' => 'lucide']);
        });
        $this->app->bind(
            \App\Contracts\Identity\IdentityProvider::class,
            \App\Services\Identity\AuthentikIdentityProvider::class,
        );
        $this->app->bind(ConferenceProvider::class, \App\Services\VideoConferencing\LiveKitConferenceProvider::class);

        // Holds notifications only while one bulk dashboard action runs, never across requests or jobs.
        $this->app->scoped(\App\Services\Inspections\InspectionNotificationBatch::class);

        // Captain 5 decisions are memoized per request (scoped), never across requests or jobs.
        $this->app->scoped(\App\Services\Captain5\VerifiedBidSeats::class);
        $this->app->scoped(\App\Services\Captain5\Captain5DutyRoster::class);
        $this->app->scoped(\App\Services\Captain5\MedicalScope::class);
        $this->app->scoped(\App\Services\Captain5\Captain5IssueScope::class);
        $this->app->scoped(\App\Services\Security\Captain5Access::class);

        $this->app->bind(
            \Filament\Http\Responses\Auth\Contracts\LoginResponse::class,
            \App\Http\Responses\LoginResponse::class,
        );

        // Route application AI through the authenticated logical gateway.
        // The legacy "local" driver name is retained only as a non-raw alias
        // during configuration migration; both names resolve the same gateway.
        $this->app->bind(\App\Services\CloudflareAIService::class, function () {
            return match (config('cloudflare.ai.driver')) {
                'gateway', 'local' => new \App\Services\LocalAIService,
                'cloudflare' => new \App\Services\CloudflareAIService,
                default => throw new \RuntimeException('Unsupported AI driver.'),
            };
        });

    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Convert instants at the UI boundary, leaving calendar-only dates and UTC storage unchanged.
        TextColumn::configureUsing(static fn (TextColumn $column) => $column->timezone(
            static fn (): string => (string) config($column->isDateTime() || $column->isTime() ? 'hub.timezone' : 'app.timezone'),
        ));
        TextEntry::configureUsing(static fn (TextEntry $entry) => $entry->timezone(
            static fn (): string => (string) config($entry->isDateTime() || $entry->isTime() ? 'hub.timezone' : 'app.timezone'),
        ));
        DateTimePicker::configureUsing(static fn (DateTimePicker $picker) => $picker->timezone(
            static fn (): string => (string) config($picker->hasDate() && $picker->hasTime() ? 'hub.timezone' : 'app.timezone'),
        ));

        Gate::policy(\App\Models\Training\TrainingCourse::class, \App\Policies\TrainingRosterPolicy::class);
        Gate::policy(\App\Models\Training\TrainingSession::class, \App\Policies\TrainingRosterPolicy::class);

        RateLimiter::for('member-onboarding-invitations', static fn () => Limit::perMinute(4)->by('member-onboarding-global'));
        // Matches the Hub's hard email cap in CloudflareCostGuard.
        RateLimiter::for('budgeted-email', static fn () => Limit::perMinute(5)->by('budgeted-email-global'));

        // Its own counter: a plain throttle:10,1 shares one per user with every other plain throttle in the Hub.
        RateLimiter::for('daily-roster-pdf', static fn (Request $request) => Limit::perMinute(10)->by('daily-roster-pdf:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Pulse defaults to Gravatar URLs built from hashed member emails; keep
        // dashboard user cards local instead of disclosing them to a third party.
        Pulse::user(static fn (User $user): array => ['name' => $user->name, 'extra' => (string) $user->email]);

        Gate::before(function (User $user, string $ability, array $arguments): ?bool {
            $subject = $arguments[0] ?? null;
            $model = is_object($subject) ? $subject::class : $subject;
            if (in_array($model, [\App\Models\PersonnelRequest::class, \App\Models\HubSupportTicket::class], true)
                && in_array($ability, ['update', 'delete', 'deleteAny', 'restore', 'restoreAny', 'forceDelete', 'forceDeleteAny'], true)) {
                // Lifecycle rules must apply to delegated managers and super administrators alike.
                return null;
            }

            if (in_array($model, [\App\Models\Training\TrainingCourse::class, \App\Models\Training\TrainingSession::class], true)) {
                // Retention and lifecycle rules apply even to super administrators.
                return null;
            }

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

        RateLimiter::for('hub-support-submissions', function (Request $request): array {
            $user = $request->user('web');

            return [
                Limit::perHour(10)->by('hub-support-user:'.($user instanceof User ? $user->id : $request->ip())),
                Limit::perHour(60)->by('hub-support-ip:'.$request->ip()),
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
        // The one 300 Command decision (profile, communications, video command
        // controls, Command Board sign-in). Super Administrators also pass it.
        Gate::define(\App\Services\Security\Command300Access::GATE,
            fn (\App\Models\User $user): bool => app(\App\Services\Security\Command300Access::class)->allows($user));
        // Captain 5 EMS Operations Center (docs/captain5-ems-operations-center.md section 2).
        Gate::define(\App\Services\Security\Captain5Access::GATE_VIEW,
            fn (\App\Models\User $user): bool => app(\App\Services\Security\Captain5Access::class)->decide($user)->canViewData);
        Gate::define(\App\Services\Security\Captain5Access::GATE_ACT,
            fn (\App\Models\User $user): bool => app(\App\Services\Security\Captain5Access::class)->decide($user)->canAct);
        Gate::define(\App\Services\Security\Captain5Access::GATE_ADMIN,
            fn (\App\Models\User $user): bool => app(\App\Services\Security\Captain5Access::class)->decide($user)->canConfigure);

        Todo::observe(TodoObserver::class);
        TrainingTodo::observe(TrainingTodoObserver::class);
        // Order matters: the link observer reads change tracking that ApparatusObserver's re-save replaces.
        Apparatus::observe(ApparatusMasterLinkObserver::class);
        Apparatus::observe(ApparatusObserver::class);
        UnitMasterVehicle::observe(UnitMasterVehicleSyncObserver::class);
        ApparatusDefect::observe(ApparatusDefectObserver::class);
        User::observe(UpstreamIdentityObserver::class);

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

        \Filament\Support\Facades\FilamentView::registerRenderHook(
            \Filament\View\PanelsRenderHook::TOPBAR_START,
            fn (): \Illuminate\Contracts\View\View => view('filament.partials.hub-shell'),
        );
        \Filament\Support\Facades\FilamentView::registerRenderHook(
            \Filament\View\PanelsRenderHook::TOPBAR_AFTER,
            fn (): \Illuminate\Contracts\View\View => view('filament.partials.hub-offline-strip'),
        );
        \Filament\Support\Facades\FilamentView::registerRenderHook(
            \Filament\View\PanelsRenderHook::BODY_END,
            fn (): \Illuminate\Contracts\View\View => view('filament.partials.hub-member-navigation'),
        );
        // One set of install and notch metas for every panel; the Admin PWA script stays in head-pwa.
        \Filament\Support\Facades\FilamentView::registerRenderHook(
            \Filament\View\PanelsRenderHook::HEAD_END,
            fn (): string => filament()->getCurrentPanel()?->getId() === 'policy-library' ? '' : view('components.hub.head')->render(),
        );

        \Filament\Support\Facades\FilamentView::registerRenderHook(
            \Filament\View\PanelsRenderHook::HEAD_START,
            fn (): \Illuminate\Contracts\View\View => view('filament.partials.async-components'),
        );
        \Filament\Support\Facades\FilamentView::registerRenderHook(
            \Filament\View\PanelsRenderHook::HEAD_START,
            fn (): \Illuminate\Contracts\View\View => view('filament.partials.navigation-state'),
        );
        \Filament\Support\Facades\FilamentView::registerRenderHook(
            \Filament\View\PanelsRenderHook::PAGE_START,
            fn (array $scopes): \Illuminate\Contracts\View\View => view('filament.partials.page-nav', [
                'nav' => \App\Filament\Support\PageNav::resolve($scopes),
            ]),
        );
        // All Filament panels share one session-expiry boundary, including polls.
        \Filament\Support\Facades\FilamentView::registerRenderHook(
            \Filament\View\PanelsRenderHook::HEAD_END,
            fn (): string => '<link rel="stylesheet" href="/css/hub-wallpaper-v1.css?v=3">',
        );
        \Filament\Support\Facades\FilamentView::registerRenderHook(
            \Filament\View\PanelsRenderHook::HEAD_END,
            fn (): \Illuminate\Contracts\View\View => view('filament.partials.session-expiry'),
        );
        \Filament\Support\Facades\FilamentView::registerRenderHook(
            \Filament\View\PanelsRenderHook::FOOTER,
            function (): string {
                if (! auth('web')->check()) {
                    return '';
                }

                try {
                    return view('components.hub-support-widget')->render();
                } catch (\Throwable $exception) {
                    report($exception);

                    return '';
                }
            },
        );

        // ─── Submission Notification Triggers ──────────────────────────────
        // Dispatch NewSubmissionNotification (database + web push) when new
        // user-facing forms are submitted across the MBFD Hub platform.

        // Every hook below is guarded: whatever goes wrong while telling people about a submission is reported
        // and never fails or rolls back the submission itself.
        StationInspection::created(fn (StationInspection $inspection) => $this->guardNotificationHook(function () use ($inspection): void {
            $stationName = $inspection->station?->name ?? 'Unknown Station';
            $this->notifySubmissionRoles(
                'station_inspection',
                'New Station Inspection Submitted',
                "A station inspection for {$stationName} has been submitted.",
                '/admin/station-inspections/'.$inspection->id,
                $inspection->id,
            );
        }));

        // An evaluation is created as a DRAFT the moment a member opens it, so "has been submitted"
        // is only true when its status becomes 'submitted' (or when one is created already submitted).
        $notifyEvaluationSubmitted = fn (EvaluationSubmission $submission) => $this->guardNotificationHook(function () use ($submission): void {
            $productName = $submission->candidateProduct?->name ?? 'a product';
            // Each submission of the evaluation is its own event (its key carries a fresh id), so returning it for edits
            // and resubmitting it alerts again. The key still collapses a retry of this very fan-out.
            $revision = 'submitted:'.\Illuminate\Support\Str::uuid();
            $this->notifySubmissionRoles(
                'evaluation_submission',
                'New Evaluation Submitted',
                "An evaluation for {$productName} has been submitted.",
                '/admin/evaluation-submissions/'.$submission->id,
                $submission->id,
                event: $revision,
            );
        });
        EvaluationSubmission::created(function (EvaluationSubmission $submission) use ($notifyEvaluationSubmitted): void {
            if ($submission->status === 'submitted') {
                $notifyEvaluationSubmitted($submission);
            }
        });
        EvaluationSubmission::updated(function (EvaluationSubmission $submission) use ($notifyEvaluationSubmitted): void {
            if ($submission->wasChanged('status') && $submission->status === 'submitted') {
                $notifyEvaluationSubmitted($submission);
            }
        });

        // Daily Checkouts have no default fan-out. Reviewers are told by InspectionNotificationDispatcher
        // (review required, significant damage, member replies) and a routine accepted checkout notifies
        // nobody (docs/daily-inspection-continuity.md section 4.5). The ONE exception is the opt-in
        // category "Every Daily Checkout as it's submitted": it shows in the bell by default (phone alerts
        // off), goes only to eligible reviewers, and skips excluded tests and the submitter.
        ApparatusInspection::created(fn (ApparatusInspection $inspection) => $this->guardNotificationHook(function () use ($inspection): void {
            if ($inspection->test_excluded_at !== null) {
                return;
            }
            $unitName = $inspection->designation_at_time
                ?? $inspection->unit_number
                ?? $inspection->apparatus?->designation
                ?? 'Unknown';
            $this->notifySubmissionRoles(
                'daily_checkout_every_submission',
                'New Daily Checkout',
                "A Daily Checkout for {$unitName} has been submitted.",
                \App\Filament\Support\InspectionWorkspace::url($inspection),
                $inspection->id,
                $inspection->actor_user_id !== null ? (int) $inspection->actor_user_id : null,
            );
        }));

        StationInventorySubmission::created(fn (StationInventorySubmission $submission) => $this->guardNotificationHook(function () use ($submission): void {
            $stationName = $submission->station?->station_number ?? 'Unknown Station';
            $employeeName = $submission->employee_name ?: 'Unknown employee';
            $shift = $submission->shift ?: 'Unknown shift';

            $this->notifySubmissionRoles(
                'station_inventory_submission',
                'New Station Inventory Submission',
                "Station {$stationName} submitted an inventory alert for {$shift} shift by {$employeeName}.",
                \App\Filament\Resources\StationResource::getUrl('view', [
                    'record' => $submission->station_id,
                    'activeRelationManager' => array_search(
                        \App\Filament\Resources\StationResource\RelationManagers\InventorySubmissionsRelationManager::class,
                        \App\Filament\Resources\StationResource::getRelations(),
                        true,
                    ),
                ], panel: 'admin'),
                $submission->id,
            );
        }));
    }

    /**
     * Notification hooks run inside the workflow's own save. Whatever they do, the write must stand:
     * any failure is reported and swallowed here.
     */
    private function guardNotificationHook(callable $hook): void
    {
        try {
            $hook();
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Queue a NewSubmissionNotification for the people who are eligible for its category right now (roles
     * and permissions, evaluated live by the queued fan-out after the write commits). A saved preference
     * can only narrow the channels; it never chooses the recipients.
     */
    private function notifySubmissionRoles(
        string $submissionType,
        string $title,
        string $body,
        string $actionUrl,
        int|string $subjectId,
        ?int $exceptUserId = null,
        string $event = 'created',
    ): void {
        $category = User::preferenceKeyForSubmissionType($submissionType);

        if ($category === null) {
            return;
        }

        $eventKey = \App\Services\Notifications\NotificationDispatchService::eventKey($category, $submissionType, $subjectId, $event);

        app(\App\Services\Notifications\NotificationDispatchService::class)->dispatch(
            $category,
            new NewSubmissionNotification(
                submissionType: $submissionType,
                title: $title,
                body: $body,
                actionUrl: $actionUrl,
                eventKey: $eventKey,
            ),
            eventKey: $eventKey,
            exceptUserId: $exceptUserId,
        );
    }
}
