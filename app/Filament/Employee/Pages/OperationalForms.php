<?php

namespace App\Filament\Employee\Pages;

use App\Concerns\ResolvesCanonicalEmployee;
use App\Services\Narcotics\NarcoticsCheckAccess;
use App\Services\OperationalForms\FrocImportLimits;
use App\Support\Ems\EmsLinks;
use App\Support\Narcotics\NarcoticsLinks;
use Filament\Pages\Page;
use Illuminate\Support\Str;

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
                ],
                'deep_link' => $this->emsDeepLink(),
                'narcotics_deep_link' => $this->narcoticsDeepLink(),
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

        return [
            [
                'key' => EmsLinks::MEMBER_FORM_KEY,
                'title' => 'Medical Inventory Request',
                'description' => 'Tell Captain 5 what your Airway Bag and ALS Med Bag or Box need. Fill in only what you need and send it when you are ready.',
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
        ];
    }
}
