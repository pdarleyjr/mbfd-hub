<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/** Synthetic records for local presentation coverage; never an operational import. */
final class ProtectedUiInventorySeeder extends Seeder
{
    private array $records = [];

    public function run(): void
    {
        if (! app()->environment('testing') || config('database.default') !== 'sqlite'
            || basename((string) config('database.connections.sqlite.database')) !== 'protected_ui_e2e.sqlite'
            || config('mail.default') !== 'array') {
            throw new RuntimeException('Inventory fixtures require the isolated UI SQLite database and array mailer.');
        }
        if (filter_var(config('workgroup.ai_worker_enabled'), FILTER_VALIDATE_BOOL)
            || trim((string) config('workgroup.ai_worker_url')) !== ''
            || trim((string) config('workgroup.ai_worker_secret')) !== ''
            || app(\App\Services\Workgroup\WorkgroupAIService::class)->isEnabled()) {
            throw new RuntimeException('Inventory rendering requires the AI worker explicitly disabled and unconfigured.');
        }

        $user = Models\User::query()->where('employee_id', '99871')->firstOrFail();
        $employee = Models\Employee::query()->where('employee_id', '99871')->firstOrFail();
        $station = Models\Station::query()->firstOrFail();
        $apparatus = Models\Apparatus::query()->firstOrFail();
        Models\Apparatus::query()->get()->each(function (Models\Apparatus $fixture): void {
            $fixture->forceFill(['name' => $fixture->designation])->saveQuietly();
        });
        foreach ([$user, $employee, $station, $apparatus, Models\DepartmentUpdate::query()->firstOrFail(),
            Models\HubSupportTicket::query()->firstOrFail(), Models\StationRequest::query()->firstOrFail(),
            \Spatie\Permission\Models\Role::query()->where('name', 'super_admin')->firstOrFail()] as $record) {
            $this->records[$record::class] = $record;
        }

        // Suppress observer-driven notifications, AI jobs and integration work for
        // synthetic fixture inserts. Casts and real relationships are retained.
        Model::withoutEvents(function () use ($user, $employee, $station, $apparatus): void {
            $location = $this->fixture(Models\InventoryLocation::class, ['location_name' => 'Local UI test supply room', 'shelf' => 'A']);
            $category = $this->fixture(Models\InventoryCategory::class, ['name' => 'Local UI test supplies']);
            $this->fixture(Models\InventoryItem::class, ['category_id' => $category->id, 'name' => 'Local UI test batteries', 'par_quantity' => 12]);
            $equipment = $this->fixture(Models\EquipmentItem::class, ['name' => 'Local UI test flashlight', 'normalized_name' => 'local ui test flashlight', 'location_id' => $location->id, 'category' => 'Tool']);
            $uniform = $this->fixture(Models\Uniform::class, ['item_name' => 'Local UI test station shirt', 'size' => 'L', 'quantity_on_hand' => 10]);
            $this->fixture(Models\AssignedEquipment::class, ['user_id' => $user->id, 'employee_portal_id' => $employee->id, 'uniform_id' => $uniform->id, 'category' => 'Uniform Inventory', 'item_description' => 'Local UI test station shirt', 'issued_at' => today()]);
            $this->fixture(Models\EmployeeEquipmentRequest::class, ['user_id' => $user->id, 'employee_portal_id' => $employee->id, 'requested_items' => 'Local UI test flashlight', 'status' => 'Pending']);
            $this->fixture(Models\FireEquipmentRequest::class, ['station_id' => $station->id, 'requested_by' => $user->id, 'equipment_type' => 'Tool', 'description' => 'Local UI test replacement request', 'priority' => 'normal', 'form_data' => []]);
            $this->fixture(Models\PersonnelRequest::class, [
                'public_id' => (string) Str::uuid(), 'request_number' => 'UI-LOCAL-0001', 'type' => 'uniform',
                'beneficiary_employee_id' => $employee->id, 'requester_employee_id' => $employee->id, 'originating_station_id' => $station->id,
                'beneficiary_name' => $employee->name, 'beneficiary_employee_number' => $employee->employee_id,
                'requester_name' => $employee->name, 'requester_employee_number' => $employee->employee_id,
                'idempotency_key' => (string) Str::uuid(), 'metadata' => ['fixture' => 'local presentation only'],
            ]);
            $this->fixture(Models\Todo::class, ['title' => 'Local UI test stock review', 'created_by' => $user->id, 'assigned_to' => [$user->id]]);
            $this->fixture(Models\Training\TrainingTodo::class, ['title' => 'Local UI test training preparation', 'created_by' => $user->id, 'assigned_to' => [$user->id]]);
            $this->fixture(Models\CapitalProject::class, ['project_number' => 'FIRE-UI-LOCAL-01', 'name' => 'Local UI test station upgrade', 'budget_amount' => 1000, 'station_id' => $station->id, 'status' => 'pending', 'priority' => 'medium']);
            $this->fixture(Models\Under25kProject::class, ['project_number' => 'FIRE-UI-LOCAL-02', 'name' => 'Local UI test room repair', 'budget_amount' => 500, 'station_id' => $station->id, 'status' => 'pending']);
            $this->fixture(Models\ShopWork::class, ['project_name' => 'Local UI test equipment service', 'apparatus_id' => $apparatus->id, 'status' => 'Pending']);
            $this->fixture(Models\UnitMasterVehicle::class, ['veh_number' => 'UI-LOCAL-01', 'make' => 'Local fixture', 'model' => 'Test vehicle', 'year' => 2026]);
            $this->fixture(Models\SingleGasMeter::class, ['apparatus_id' => $apparatus->id, 'serial_number' => 'UI-LOCAL-METER-01', 'activation_date' => today(), 'expiration_date' => today()->addYear()]);
            $room = $this->fixture(Models\Room::class, ['station_id' => $station->id, 'name' => 'Local UI test day room']);
            $this->fixture(Models\RoomAsset::class, ['room_id' => $room->id, 'name' => 'Local UI test table', 'quantity' => 1, 'category' => 'Furniture', 'condition' => 'Good']);
            $inspection = $this->fixture(Models\ApparatusInspection::class, [
                'apparatus_id' => $apparatus->id, 'operator_name' => $employee->name, 'rank' => 'Captain', 'shift' => 'A',
                'actor_user_id' => $user->id, 'employee_id' => $employee->id, 'vehicle_number' => $apparatus->vehicle_number,
                'designation_at_time' => $apparatus->designation, 'completed_at' => now(), 'results' => [], 'checklist_evidence' => [], 'review_status' => 'approved',
            ]);
            $defect = $this->fixture(Models\ApparatusDefect::class, ['apparatus_id' => $apparatus->id, 'apparatus_inspection_id' => $inspection->id, 'compartment' => 'Local test bay', 'item' => 'Local test flashlight', 'status' => 'Missing', 'notes' => 'Synthetic UI fixture only']);
            $this->fixture(Models\ApparatusDefectRecommendation::class, ['apparatus_defect_id' => $defect->id, 'equipment_item_id' => $equipment->id, 'match_method' => 'manual', 'reasoning' => 'Synthetic UI fixture only', 'created_by_user_id' => $user->id]);
            $this->fixture(Models\ApparatusServiceTicket::class, ['apparatus_id' => $apparatus->id, 'station_id' => $station->id, 'unit_designation_snapshot' => $apparatus->designation, 'origin' => 'manual', 'category' => 'other', 'title' => 'Local UI test service request', 'description' => 'Synthetic UI fixture only', 'ticket_number' => 'AST-UI-LOCAL-01', 'created_by_user_id' => $user->id]);
            $this->fixture(Models\StationInspection::class, ['station_id' => $station->id, 'inspector_id' => $user->id, 'inspection_date' => today(), 'inspection_type' => 'daily', 'form_data' => [], 'overall_status' => 'pass']);
            $this->fixture(Models\OperationalFormRecord::class, ['employee_id' => $employee->id, 'form_type' => 'ics_214', 'form_version' => '1.0', 'title' => 'Local UI test activity log', 'data' => [], 'status' => 'draft']);

            $workgroup = $this->fixture(Models\Workgroup::class, ['name' => 'Local UI test workgroup', 'is_active' => true, 'created_by' => $user->id]);
            $member = $this->fixture(Models\WorkgroupMember::class, ['workgroup_id' => $workgroup->id, 'user_id' => $user->id, 'role' => 'facilitator', 'is_active' => true]);
            $session = $this->fixture(Models\WorkgroupSession::class, ['workgroup_id' => $workgroup->id, 'name' => 'Local UI test session', 'start_date' => today(), 'end_date' => today()->addWeek(), 'status' => 'active']);
            $category = $this->fixture(Models\EvaluationCategory::class, ['name' => 'Local UI test equipment', 'assessment_profile' => 'generic_apparatus', 'is_active' => true]);
            $template = $this->fixture(Models\EvaluationTemplate::class, ['name' => 'Local UI test rubric', 'category_id' => $category->id, 'is_active' => true]);
            $this->fixture(Models\EvaluationCriterion::class, ['template_id' => $template->id, 'name' => 'Local UI test handling', 'max_score' => 10, 'weight' => 1]);
            $product = $this->fixture(Models\CandidateProduct::class, ['workgroup_session_id' => $session->id, 'category_id' => $category->id, 'name' => 'Local UI test product', 'manufacturer' => 'Synthetic fixture']);
            $this->fixture(Models\EvaluationSubmission::class, ['workgroup_member_id' => $member->id, 'candidate_product_id' => $product->id, 'status' => 'draft', 'assessment_profile' => 'generic_apparatus', 'rubric_version' => \App\Support\Workgroups\UniversalEvaluationRubric::VERSION]);
            $survey = $this->fixture(Models\WorkgroupSurvey::class, ['workgroup_id' => $workgroup->id, 'workgroup_session_id' => $session->id, 'title' => 'Local UI test usability survey', 'status' => 'active', 'created_by' => $user->id]);
            $this->fixture(Models\WorkgroupSurveyQuestion::class, ['survey_id' => $survey->id, 'position' => 1, 'type' => 'single', 'prompt' => 'Local fixture: assess handling.', 'configuration' => ['options' => [['key' => 'ready', 'label' => 'Ready for local visual review'], ['key' => 'review', 'label' => 'Needs local review']]]]);
            $filePath = 'protected-ui-inventory/local-fixture.txt';
            Storage::disk('local')->put($filePath, 'Synthetic local UI fixture. No operational content.');
            $this->fixture(Models\WorkgroupFile::class, ['workgroup_id' => $workgroup->id, 'workgroup_session_id' => $session->id, 'filename' => 'local-fixture.txt', 'filepath' => $filePath, 'file_type' => 'txt', 'file_size' => Storage::disk('local')->size($filePath), 'uploaded_by' => $user->id]);
            Cache::put("workgroup_saver_report_{$workgroup->id}_{$session->id}", '<h2>Local UI fixture report</h2><p>This synthetic report tests the presentation shell only.</p><table><tr><th>Test item</th><th>Status</th></tr><tr><td>Local fixture</td><td>Ready for visual inspection</td></tr></table>', now()->addHour());

            $this->fixture(Models\InboundEmail::class, ['provider_message_id' => 'local-ui-inbound-01', 'from_address' => 'sender@example.test', 'to_address' => 'recipient@example.test', 'subject' => 'Local UI test inbound message', 'received_at' => now(), 'text_body' => 'Synthetic fixture; no message received externally.']);
            $this->fixture(Models\OutboundEmail::class, ['source_type' => 'manual', 'from_address' => 'sender@example.test', 'to_recipients' => ['recipient@example.test'], 'subject' => 'Local UI test outbound draft', 'text_body' => 'Synthetic fixture; never sent.', 'recipient_count' => 1, 'chargeable_budget_units' => 0, 'status' => 'draft', 'initiated_by_user_id' => $user->id]);
        });

        $destinations = [];
        $catalog = json_decode(file_get_contents(base_path('tests/e2e/support/protected-ui-inventory.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach ($catalog['pages'] as $entry) {
            if (! str_contains($entry['route'], '{record}') || str_contains($entry['route'], '{inspection}')) {
                continue;
            }
            $registered = Route::getRoutes()->getByName($entry['route_name'] ?? '');
            $page = $registered?->getActionName();
            if (! is_string($page) || ! is_subclass_of($page, \Filament\Resources\Pages\Page::class)) {
                continue;
            }
            $modelClass = $page::getResource()::getModel();
            if (isset($this->records[$modelClass])) {
                $destinations[$entry['route']] = route($entry['route_name'], ['record' => $this->records[$modelClass]->getRouteKey()], false);
            }
        }
        $survey = $this->records[Models\WorkgroupSurvey::class];
        $destinations['/workgroups/survey-form-page'] = '/workgroups/survey-form-page?surveyId='.$survey->id;
        $destinations['/workgroups/survey-results-page'] = '/workgroups/survey-results-page?surveyId='.$survey->id;
        $destinations['/workgroups/evaluation-form-page'] = '/workgroups/evaluation-form-page?productId='.$this->records[Models\CandidateProduct::class]->id;
        $destinations['/admin/apparatuses/{record}/inspections/{inspection}'] = '/admin/apparatuses/'.$apparatus->id.'/inspections/'.$this->records[Models\ApparatusInspection::class]->id;
        $destinations['/updates/{departmentUpdate}'] = '/updates/'.$this->records[Models\DepartmentUpdate::class]->getRouteKey();
        $destinations['/support/issues/{ticket}'] = '/support/issues/'.$this->records[Models\HubSupportTicket::class]->getRouteKey();
        $destinations['/employee/my-requests/{personnelRequest}'] = '/employee/my-requests/'.$this->records[Models\PersonnelRequest::class]->getRouteKey();
        $destinations['/workgroup/saver-report'] = '/workgroup/saver-report?session_id='.$this->records[Models\WorkgroupSession::class]->id;
        $destinations['/video-conferencing/stations/{station}'] = '/video-conferencing/stations/1';
        $destinations['/daily/stations/:id'] = '/daily/stations/'.$station->id;
        $destinations['/daily/stations/:stationId/rooms/:roomId'] = '/daily/stations/'.$station->id.'/rooms/'.$this->records[Models\Room::class]->id;
        $accountException = $this->fixture(Models\User::class, [
            'name' => 'Local UI Test Account Exception', 'email' => 'protected-ui-account-exception@example.test',
            'password' => \Illuminate\Support\Facades\Hash::make(bin2hex(random_bytes(32))),
            'account_status' => 'active', 'account_classification' => 'approved_nonemployee',
        ]);
        $destinations['/admin/employees/accounts/{record}/edit'] = '/admin/employees/accounts/'.$accountException->id.'/edit';
        $token = Password::broker('users')->createToken($user);
        $destinations['/reset-password/{token}'] = route('password.reset.form', ['token' => $token, 'employee_id' => $employee->employee_id], false);
        // Supply a local confirmation-page token without invoking email delivery.
        $cityToken = bin2hex(random_bytes(32));
        Models\CityEmailVerification::query()->where('user_id', $user->id)->firstOrFail()
            ->forceFill(['token_hash' => hash('sha256', $cityToken), 'token_expires_at' => now()->addHour()])->saveQuietly();
        $destinations['/account/city-email/verify/{token}'] = route('city-email.verify', ['token' => $cityToken], false);
        $onboardingEmployee = $this->fixture(Models\Employee::class, [
            'employee_id' => '99872', 'name' => 'Local UI Test New Member', 'rank' => 'Firefighter',
            'roster_status' => 'active', 'city_email' => 'protected-ui-new-member@miamibeachfl.gov',
            'password' => \Illuminate\Support\Facades\Hash::make(bin2hex(random_bytes(32))),
        ]);
        $onboardingUser = $this->fixture(Models\User::class, [
            'name' => $onboardingEmployee->name, 'email' => $onboardingEmployee->city_email,
            'employee_id' => $onboardingEmployee->employee_id, 'employee_profile_id' => $onboardingEmployee->id,
            'password' => \Illuminate\Support\Facades\Hash::make(bin2hex(random_bytes(32))),
            'account_status' => 'pending_activation', 'bootstrap_onboarding_eligible' => true,
        ]);
        $this->fixture(Models\MemberOnboardingRosterBinding::class, [
            'employee_profile_id' => $onboardingEmployee->id, 'employee_id' => $onboardingEmployee->employee_id,
            'city_email' => $onboardingEmployee->city_email, 'source_sha256' => hash('sha256', 'Synthetic local UI fixture only'), 'approved_at' => now(),
        ]);
        $invitationToken = bin2hex(random_bytes(32));
        $this->fixture(Models\MemberOnboardingInvitation::class, [
            'user_id' => $onboardingUser->id, 'employee_profile_id' => $onboardingEmployee->id,
            'email' => $onboardingEmployee->city_email, 'security_version' => $onboardingUser->fresh()->security_version,
            'token_hash' => hash('sha256', $invitationToken), 'expires_at' => now()->addHour(), 'delivery_status' => 'pending',
        ]);
        $destinations['/member-onboarding/invite'] = '/member-onboarding/invite#'.$invitationToken;
        $directory = base_path('test-results/protected-ui-auth');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        file_put_contents($directory.'/fixture-routes.json', json_encode($destinations, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function fixture(string $class, array $attributes): Model
    {
        $record = new $class;
        $record->forceFill($attributes)->saveQuietly();
        $this->records[$class] = $record;

        return $record;
    }
}
