<?php

namespace App\Filament\Employee\Pages;

use App\Concerns\ResolvesCanonicalEmployee;
use App\Models\AccidentReport;
use App\Models\User;
use App\Services\Accidents\AccidentReportService;
use App\Services\Narcotics\NarcoticsCheckAccess;
use App\Services\OperationalForms\FrocImportLimits;
use App\Support\Ems\EmsLinks;
use App\Support\Narcotics\NarcoticsLinks;
use App\Support\Personnel\PersonnelAccess;
use Filament\Pages\Page;
use Illuminate\Support\Str;
use Throwable;

class OperationalForms extends Page
{
    use ResolvesCanonicalEmployee;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'My work';

    protected static string $view = 'filament.employee.pages.operational-forms';

    protected static ?string $title = 'Operational Forms';

    protected ?string $heading = '';

    protected static ?string $navigationLabel = 'Forms';

    protected static ?string $slug = 'forms';

    protected static ?int $navigationSort = 1;

    /** Deep-link keys (?form=...) for the accident reports and the memorandum; the notifications link to the first. */
    public const ACCIDENT_FORM_KEY = 'accident-report';

    public const SUPERVISOR_REPORT_FORM_KEY = 'supervisor-accident-report';

    public const MEMORANDUM_FORM_KEY = 'memorandum';

    public function getViewData(): array
    {
        $employee = $this->authenticatedEmployee();

        return [
            'operationalFormsBootstrap' => [
                'employee' => [
                    'id' => $employee->getKey(),
                    'employee_id' => $employee->employee_id,
                    'name' => $employee->name,
                    'rank' => $employee->rank,
                ],
                'endpoints' => [
                    'form_types' => route('employee.forms.api.form-types'),
                    'records' => route('employee.forms.api.records.index'),
                    'uploads' => route('employee.forms.api.uploads.store'),
                    'guide' => url('/documents/MBFD_Operational_Forms_User_Guide.pdf'),
                    'ems' => $this->emsEndpoints(),
                    'narcotics' => $this->narcoticsEndpoints(),
                    'personnel' => $this->personnelEndpoints(),
                ],
                'deep_link' => $this->emsDeepLink(),
                'narcotics_deep_link' => $this->narcoticsDeepLink(),
                'personnel_deep_link' => $this->personnelDeepLink(),
                'personnel' => [
                    'can_supervise' => PersonnelAccess::canSupervise(auth('web')->user()),
                    'awaiting_supervisor' => $this->awaitingSupervisorCount(),
                ],
                'library_links' => $this->libraryLinks(),
                'csrf_token' => csrf_token(),
                'build' => config('app.version', 'development'),
                ...FrocImportLimits::bootstrap(),
            ],
        ];
    }

    /**
     * The Medical Inventory Request JSON API (routes/ems.php). The three per-request URLs carry the
     * placeholder __UUID__, which the app replaces with the request id.
     *
     * @return array{form: string, requests: string, request: string, messages: string, cancel: string, placeholder: string}
     */
    private function emsEndpoints(): array
    {
        $placeholder = '__UUID__';

        return [
            'form' => route('employee.forms.api.ems.form'),
            'requests' => route('employee.forms.api.ems.requests.index'),
            'request' => route('employee.forms.api.ems.requests.show', ['uuid' => $placeholder]),
            'messages' => route('employee.forms.api.ems.requests.messages.store', ['uuid' => $placeholder]),
            'cancel' => route('employee.forms.api.ems.requests.cancel', ['uuid' => $placeholder]),
            'placeholder' => $placeholder,
        ];
    }

    /**
     * The Narcotics check JSON API (routes/narcotics.php), or null when the check is switched off or this account cannot send one.
     * The two per-check URLs carry the placeholder __UUID__, which the app replaces with the check id.
     *
     * @return array{form: string, mine: string, check: string, store: string, placeholder: string}|null
     */
    private function narcoticsEndpoints(): ?array
    {
        if (! app(NarcoticsCheckAccess::class)->canSubmit(auth('web')->user())) {
            return null;
        }
        $placeholder = '__UUID__';

        return [
            'form' => route('narcotics.api.form'),
            'mine' => route('narcotics.api.mine.index'),
            'check' => route('narcotics.api.mine.show', ['uuid' => $placeholder]),
            'store' => route('narcotics.api.store'),
            'placeholder' => $placeholder,
        ];
    }

    /**
     * Which Narcotics check view the page opens first: ?form=narcotics-check (new check), &view=mine (own history),
     * &check=<uuid> (one check). Anything else is ignored.
     *
     * @return array{view: string|null, check: string|null}
     */
    private function narcoticsDeepLink(): array
    {
        if (request()->query('form') !== NarcoticsLinks::FORM_KEY) {
            return ['view' => null, 'check' => null];
        }
        $check = Str::lower(trim((string) request()->query('check', '')));
        if (Str::isUuid($check)) {
            return ['view' => 'detail', 'check' => $check];
        }

        return ['view' => request()->query('view') === 'mine' ? 'mine' : 'form', 'check' => null];
    }

    /**
     * The accident report and memorandum JSON API (routes/personnel-forms.php). The per-report URLs carry the
     * placeholder __ULID__, which the app replaces with the report id. The employee search is the lookup that
     * returns the employee record id the accident API expects (the Forms employee search returns badge numbers only).
     *
     * @return array<string, string>
     */
    private function personnelEndpoints(): array
    {
        $placeholder = '__ULID__';

        return [
            'accidents_form' => route('employee.forms.api.accidents.form'),
            'accidents' => route('employee.forms.api.accidents.index'),
            'accident' => route('employee.forms.api.accidents.show', ['report' => $placeholder]),
            'accident_submit' => route('employee.forms.api.accidents.submit', ['report' => $placeholder]),
            'accident_supervisor' => route('employee.forms.api.accidents.supervisor', ['report' => $placeholder]),
            'accident_preview' => route('employee.forms.api.accidents.preview', ['report' => $placeholder]),
            'memorandum_form' => route('employee.forms.api.memorandum.form'),
            'memorandum_pdf' => route('employee.forms.api.memorandum.pdf'),
            'memorandum_support_services' => route('employee.forms.api.memorandum.support-services'),
            'memorandum_email' => route('employee.forms.api.memorandum.email'),
            'employee_search' => route('take-home-inspections.employees.search'),
            'placeholder' => $placeholder,
        ];
    }

    /**
     * Which accident report or memorandum view the page opens first: ?form=accident-report (new vehicle report),
     * &report=<ulid> (one report; the supervisor notification links here), &view=mine (the member's reports),
     * ?form=supervisor-accident-report, ?form=memorandum. Anything else is ignored.
     *
     * @return array{view: string|null, report: string|null}
     */
    private function personnelDeepLink(): array
    {
        $form = request()->query('form');
        if ($form === self::ACCIDENT_FORM_KEY) {
            $report = trim((string) request()->query('report', ''));
            if (Str::isUlid($report)) {
                return ['view' => 'report', 'report' => $report];
            }

            return ['view' => request()->query('view') === 'mine' ? 'mine' : 'vehicle', 'report' => null];
        }

        return match ($form) {
            self::SUPERVISOR_REPORT_FORM_KEY => ['view' => 'supervisor-report', 'report' => null],
            self::MEMORANDUM_FORM_KEY => ['view' => 'memo', 'report' => null],
            default => ['view' => null, 'report' => null],
        };
    }

    /** Vehicle Accident Reports routed to this member that wait for the supervisor's part. */
    private function awaitingSupervisorCount(): int
    {
        $user = auth('web')->user();
        if (! $user instanceof User || $user->employeeProfile === null) {
            return 0;
        }
        try {
            $service = app(AccidentReportService::class);

            return $service->forMember($user)
                ->where('status', AccidentReport::STATUS_AWAITING_SUPERVISOR)
                ->limit(100)
                ->get()
                ->filter(fn (AccidentReport $report): bool => $service->canCompleteSupervisorPart($user, $report))
                ->count();
        } catch (Throwable $exception) {
            // The Forms page must still open; the reports list shows the same items with an "Awaiting you" badge.
            report($exception);

            return 0;
        }
    }

    /**
     * Which Medical Inventory Request view the page opens first, from the query string:
     * ?form=medical-inventory-request (new request), &view=mine (own list), ?ems_request=<uuid> (one request).
     * Anything else is ignored, so a mistyped link just opens the Forms library.
     *
     * @return array{view: string|null, request: string|null}
     */
    private function emsDeepLink(): array
    {
        $request = Str::lower(trim((string) request()->query('ems_request', '')));
        if (Str::isUuid($request)) {
            return ['view' => 'detail', 'request' => $request];
        }
        if (request()->query('form') === EmsLinks::MEMBER_FORM_KEY) {
            return ['view' => request()->query('view') === 'mine' ? 'mine' : 'form', 'request' => null];
        }

        return ['view' => null, 'request' => null];
    }

    /**
     * Forms that live in their own app, or in this app's own view, and open from the library as a card.
     * Add one array entry per destination; ICS 214 and F-ROC are in-app forms and are not listed here.
     * An entry with in_app set opens inside this app (href is the shareable deep link, secondary a second action).
     *
     * @return list<array<string, mixed>>
     */
    private function libraryLinks(): array
    {
        $narcotics = app(NarcoticsCheckAccess::class)->canSubmit(auth('web')->user()) ? [[
            'key' => NarcoticsLinks::FORM_KEY,
            'title' => 'Narcotics check',
            'description' => 'Take or upload a photo of your narcotics check and send it to Captain 5. You see when it is approved.',
            'href' => url(NarcoticsLinks::memberForm()),
            'action_label' => 'Start a check',
            'in_app' => true,
            'secondary' => ['label' => 'My narcotics checks', 'href' => url(NarcoticsLinks::memberChecks())],
        ]] : [];
        $forms = url('/employee/forms');
        $supervisor = PersonnelAccess::canSupervise(auth('web')->user()) ? [[
            'key' => self::SUPERVISOR_REPORT_FORM_KEY,
            'title' => "Supervisor's Report of Accident",
            'description' => 'For supervisors: report any accident involving an employee. Sign in the Hub, then download the official form for Risk Management.',
            'href' => $forms.'?form='.self::SUPERVISOR_REPORT_FORM_KEY,
            'action_label' => 'Start a report',
            'in_app' => true,
        ]] : [];

        $personnel = [
            [
                'key' => self::ACCIDENT_FORM_KEY,
                'title' => 'Vehicle Accident Report',
                'description' => 'The driver completes this side after any accident in a City vehicle. Your supervisor completes their part next.',
                'href' => $forms.'?form='.self::ACCIDENT_FORM_KEY,
                'action_label' => 'Start a report',
                'in_app' => true,
                'secondary' => ['label' => 'My accident reports', 'href' => $forms.'?form='.self::ACCIDENT_FORM_KEY.'&view=mine'],
            ],
            ...$supervisor,
            [
                'key' => self::MEMORANDUM_FORM_KEY,
                'title' => 'Interoffice Memorandum',
                'description' => 'Write an MBFD Interoffice Memorandum. Send it to Support Services, or download the PDF for anyone else.',
                'href' => $forms.'?form='.self::MEMORANDUM_FORM_KEY,
                'action_label' => 'Write a memorandum',
                'in_app' => true,
            ],
        ];

        return [
            [
                'key' => EmsLinks::MEMBER_FORM_KEY,
                'title' => 'Medical Inventory Request',
                'description' => 'Tell Captain 5 what your Airway Bag, ALS Med Bag or Box, and ALS Transport Vehicle need. Fill in only what you need and send it when you are ready.',
                'href' => url(EmsLinks::memberForm()),
                'action_label' => 'Start a request',
                'in_app' => true,
                'secondary' => ['label' => 'My medical requests', 'href' => url(EmsLinks::memberRequests())],
            ],
            [
                'key' => 'take-home-vehicle-inspection',
                'title' => 'Take Home Vehicle Inspection',
                'description' => 'Complete a take-home vehicle inspection with photos and review your submitted reports.',
                'href' => route('take-home-inspections.index'),
                'action_label' => 'Open inspections',
            ],
            ...$narcotics,
            ...$personnel,
        ];
    }
}
