<?php

declare(strict_types=1);

namespace Tests\Feature\PersonnelRequests;

use App\Enums\PersonnelRequestStatus;
use App\Models\Employee;
use App\Models\PersonnelRequest;
use App\Models\Station;
use App\Models\Uniform;
use App\Models\User;
use App\Services\PersonnelRequests\PersonnelRequestFulfillmentService;
use App\Services\PersonnelRequests\PersonnelRequestSubmissionService;
use App\Services\PersonnelRequests\PersonnelRequestWorkflowService;
use App\Services\RequestArchivalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PersonnelRequestDomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_can_submit_multiple_allowed_uniforms_without_decrementing_inventory(): void
    {
        $employee = $this->employee('20001', 'Firefighter');
        $inventory = Uniform::query()->create(['item_name' => 'T-Shirt', 'size' => 'L', 'quantity_on_hand' => 8, 'reorder_level' => 2]);

        $request = app(PersonnelRequestSubmissionService::class)->submitUniform(
            $employee,
            [
                ['item_code' => 't_shirt', 'size' => 'L', 'quantity' => 2],
                ['item_code' => 'uniform_pants', 'size' => '34x32', 'quantity' => 1],
            ],
            'uniform-submit-1',
        );

        $this->assertSame('uniform', $request->type->value);
        $this->assertSame($employee->id, $request->beneficiary_employee_id);
        $this->assertSame($employee->id, $request->requester_employee_id);
        $this->assertCount(2, $request->items);
        $this->assertDatabaseCount('assigned_equipment', 0);
        $this->assertSame(8, $inventory->refresh()->quantity_on_hand);
    }

    #[DataProvider('prohibitedUniformCodes')]
    public function test_ppe_and_unknown_codes_are_rejected_by_uniform_submission(string $code): void
    {
        $employee = $this->employee('20002', 'Firefighter');

        try {
            app(PersonnelRequestSubmissionService::class)->submitUniform(
                $employee,
                [['item_code' => $code, 'size' => 'L', 'quantity' => 1]],
                'uniform-prohibited-'.$code,
            );
            $this->fail("The prohibited uniform code [{$code}] was accepted.");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items.0.item_code', $exception->errors());
        }

        $this->assertDatabaseCount('personnel_requests', 0);
    }

    public static function prohibitedUniformCodes(): array
    {
        return [
            'bunker coat' => ['bunker_coat'],
            'bunker pants' => ['bunker_pants'],
            'bunker boots' => ['bunker_boots'],
            'helmet' => ['structural_firefighting_helmet'],
            'firefighting gloves' => ['firefighting_gloves'],
            'protective hood' => ['protective_hood'],
            'unknown browser-crafted value' => ['made_up_item'],
        ];
    }

    public function test_authorized_officer_submission_is_signed_owned_by_beneficiary_and_idempotent(): void
    {
        Storage::fake((string) config('filesystems.private'));
        $officer = $this->employee('21001', 'Captain');
        $beneficiary = $this->employee('21002', 'Firefighter');
        $station = Station::query()->create([
            'station_number' => '1',
            'address' => '1051 Jefferson Ave',
            'zip_code' => '33139',
        ]);
        $items = [
            ['item_code' => 'bunker_coat', 'reason' => 'damaged', 'quantity' => 1],
            ['item_code' => 'other', 'reason' => 'lost', 'quantity' => 1, 'other_description' => 'Personal escape harness'],
        ];

        $service = app(PersonnelRequestSubmissionService::class);
        $first = $service->submitEquipment(
            $officer,
            $beneficiary,
            $station,
            $items,
            $this->signatureDataUrl(),
            'ppe-submit-1',
        );
        $retry = $service->submitEquipment(
            $officer,
            $beneficiary,
            $station,
            $items,
            $this->signatureDataUrl(),
            'ppe-submit-1',
        );

        $this->assertTrue($first->is($retry));
        $this->assertSame($beneficiary->id, $first->beneficiary_employee_id);
        $this->assertSame($officer->id, $first->requester_employee_id);
        $this->assertSame($station->id, $first->originating_station_id);
        $this->assertCount(2, $first->items);
        $this->assertNotNull($first->signed_at);
        Storage::disk((string) config('filesystems.private'))->assertExists($first->officer_signature_path);
        $this->assertDatabaseCount('personnel_requests', 1);
        $this->assertDatabaseCount('personnel_request_items', 2);
    }

    public function test_officer_request_validates_authority_items_reasons_other_and_signature(): void
    {
        Storage::fake((string) config('filesystems.private'));
        $firefighter = $this->employee('22001', 'Firefighter');
        $beneficiary = $this->employee('22002', 'Firefighter');
        $station = Station::query()->create([
            'station_number' => '2',
            'address' => '2300 Pine Tree Dr',
            'zip_code' => '33140',
        ]);

        foreach ([
            'unauthorized officer' => [$firefighter, [['item_code' => 'bunker_coat', 'reason' => 'lost', 'quantity' => 1]], $this->signatureDataUrl(), 'officer'],
            'missing items' => [$this->employee('22003', 'Lieutenant'), [], $this->signatureDataUrl(), 'items'],
            'missing reason' => [$this->employee('22004', 'Captain'), [['item_code' => 'bunker_coat', 'quantity' => 1]], $this->signatureDataUrl(), 'items.0.reason'],
            'other description' => [$this->employee('22005', 'Division Chief'), [['item_code' => 'other', 'reason' => 'stolen', 'quantity' => 1]], $this->signatureDataUrl(), 'items.0.other_description'],
            'blank signature' => [$this->employee('22006', 'Deputy Fire Chief'), [['item_code' => 'bunker_coat', 'reason' => 'damaged', 'quantity' => 1]], $this->blankSignatureDataUrl(), 'signature'],
            'spoofed image type' => [$this->employee('22007', 'Fire Chief'), [['item_code' => 'bunker_coat', 'reason' => 'damaged', 'quantity' => 1]], $this->jpegDisguisedAsPngDataUrl(), 'signature'],
        ] as $case => [$officer, $items, $signature, $errorKey]) {
            try {
                app(PersonnelRequestSubmissionService::class)->submitEquipment(
                    $officer,
                    $beneficiary,
                    $station,
                    $items,
                    $signature,
                    'validation-'.str($case)->slug(),
                );
                $this->fail("The {$case} submission was accepted.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($errorKey, $exception->errors(), $case);
            }
        }

        $this->assertDatabaseCount('personnel_requests', 0);
    }

    public function test_workflow_enforces_transitions_and_separates_public_and_internal_notes(): void
    {
        $employee = $this->employee('23001', 'Firefighter');
        $admin = User::factory()->create();
        $request = app(PersonnelRequestSubmissionService::class)->submitUniform(
            $employee,
            [['item_code' => 'polo_shirt', 'size' => 'M', 'quantity' => 1]],
            'workflow-submit-1',
        );
        $workflow = app(PersonnelRequestWorkflowService::class);

        $workflow->transition(
            $request,
            PersonnelRequestStatus::Acknowledged,
            $admin,
            'Your request is being reviewed.',
            'Confirm current allocation before ordering.',
        );

        $request->refresh();
        $this->assertSame(PersonnelRequestStatus::Acknowledged, $request->status);
        $this->assertSame('Your request is being reviewed.', $request->employee_response);
        $this->assertSame('Confirm current allocation before ordering.', $request->admin_status_detail);
        $this->assertDatabaseHas('personnel_request_updates', [
            'personnel_request_id' => $request->id,
            'status' => 'acknowledged',
            'employee_visible_note' => 'Your request is being reviewed.',
            'internal_note' => 'Confirm current allocation before ordering.',
        ]);

        $this->expectException(ValidationException::class);
        $workflow->transition($request, PersonnelRequestStatus::Completed, $admin);
    }

    public function test_workflow_supports_happy_path_information_round_trip_and_locks_terminal_state(): void
    {
        $employee = $this->employee('23101', 'Firefighter');
        $admin = User::factory()->create();
        $workflow = app(PersonnelRequestWorkflowService::class);
        $request = app(PersonnelRequestSubmissionService::class)->submitUniform(
            $employee,
            [['item_code' => 't_shirt', 'size' => 'L', 'quantity' => 1]],
            'workflow-happy-1',
        );

        $workflow->requestInformation($request, $admin, ['additional_explanation'], 'Please explain the replacement need.');
        $this->assertSame(PersonnelRequestStatus::NeedsInformation, $request->refresh()->status);
        $this->assertSame(['additional_explanation'], $request->information_requested);
        $workflow->employeeRespond($request, $employee, 'My current shirt was damaged during station duties.');
        $this->assertSame(PersonnelRequestStatus::Acknowledged, $request->refresh()->status);
        $workflow->transition($request, PersonnelRequestStatus::Ordered, $admin);
        $workflow->transition($request, PersonnelRequestStatus::Arrived, $admin);
        $workflow->transition($request, PersonnelRequestStatus::ReadyForPickup, $admin);
        $request->items()->update(['fulfillment_status' => 'fulfilled', 'fulfilled_quantity' => 1]);
        $workflow->transition($request, PersonnelRequestStatus::Completed, $admin);

        $request->refresh();
        $this->assertSame(PersonnelRequestStatus::Completed, $request->status);
        $this->assertNotNull($request->completed_at);
        $this->assertDatabaseHas('personnel_request_updates', [
            'personnel_request_id' => $request->id,
            'event' => 'employee_responded',
            'changed_by_employee_id' => $employee->id,
        ]);

        $this->expectException(ValidationException::class);
        $workflow->transition($request, PersonnelRequestStatus::Acknowledged, $admin);
    }

    public function test_stolen_item_does_not_automatically_require_police_report_or_case_number(): void
    {
        Storage::fake((string) config('filesystems.private'));
        $officer = $this->employee('23201', 'Captain');
        $beneficiary = $this->employee('23202', 'Firefighter');
        $station = Station::query()->create(['station_number' => '4', 'address' => '6880 Indian Creek Dr', 'zip_code' => '33141']);

        $request = app(PersonnelRequestSubmissionService::class)->submitEquipment(
            $officer,
            $beneficiary,
            $station,
            [['item_code' => 'firefighting_gloves', 'reason' => 'stolen', 'quantity' => 1]],
            $this->signatureDataUrl(),
            'stolen-no-auto-police-1',
        );

        $this->assertNull($request->information_requested);
        $this->assertSame(PersonnelRequestStatus::Pending, $request->status);
        $this->assertDatabaseMissing('personnel_request_items', ['personnel_request_id' => $request->id, 'reason' => 'needed']);
    }

    public function test_rejected_information_transition_does_not_partially_mutate_request(): void
    {
        $employee = $this->employee('23301', 'Firefighter');
        $admin = User::factory()->create();
        $request = app(PersonnelRequestSubmissionService::class)->submitUniform(
            $employee,
            [['item_code' => 't_shirt', 'size' => 'L', 'quantity' => 1]],
            'workflow-terminal-information-1',
        );
        $request->update(['status' => PersonnelRequestStatus::Denied]);

        try {
            app(PersonnelRequestWorkflowService::class)->requestInformation(
                $request,
                $admin,
                ['police_report'],
                'This transition must fail.',
            );
            $this->fail('A terminal request accepted an information request.');
        } catch (ValidationException) {
            $this->assertNull($request->refresh()->information_requested);
            $this->assertSame(PersonnelRequestStatus::Denied, $request->status);
        }
    }

    public function test_officer_ppe_request_completes_information_fulfillment_and_reversible_archive_without_losing_signature(): void
    {
        Storage::fake((string) config('filesystems.private'));
        $officer = $this->employee('23301', 'Captain');
        $beneficiary = $this->employee('23302', 'Firefighter');
        $station = Station::query()->create(['station_number' => '4', 'address' => '6880 Indian Creek Dr', 'zip_code' => '33141']);
        $admin = User::factory()->create();
        Role::findOrCreate('logistics_admin', 'web');
        $admin->assignRole('logistics_admin');
        $request = app(PersonnelRequestSubmissionService::class)->submitEquipment(
            $officer, $beneficiary, $station,
            [['item_code' => 'bunker_coat', 'reason' => 'damaged', 'quantity' => 1]],
            $this->signatureDataUrl(), 'ppe-archive-workflow-1',
        );
        $signature = $request->officer_signature_path;
        $workflow = app(PersonnelRequestWorkflowService::class);
        $workflow->transition($request, PersonnelRequestStatus::Acknowledged, $admin, 'PPE request acknowledged.');
        $workflow->requestInformation($request, $admin, ['additional_explanation'], 'Describe the damaged coat.');
        $workflow->employeeRespond($request->refresh(), $beneficiary, 'The cuff tore during training.');
        foreach ([PersonnelRequestStatus::Ordered, PersonnelRequestStatus::Arrived, PersonnelRequestStatus::ReadyForPickup] as $status) {
            $workflow->transition($request, $status, $admin);
        }
        $item = $request->items()->sole();
        $fulfillment = app(PersonnelRequestFulfillmentService::class);
        $assignment = $fulfillment->issueEquipment($item, $admin, now()->toDateString());
        $retry = $fulfillment->issueEquipment($item, $admin, now()->toDateString());
        $this->assertTrue($assignment->is($retry));
        $workflow->transition($request, PersonnelRequestStatus::Completed, $admin, 'Your coat was issued.');
        $workflow->addNote($request, $admin, 'Pickup confirmed.', 'QA purchasing evidence retained.');
        $historyBefore = $request->updates()->count();
        $archival = app(RequestArchivalService::class);
        $archival->archive($request, $admin, 'QA workflow completed.');
        $archival->archive($request, $admin, 'Repeated archive must not rewrite the reason.');
        $this->assertSame('QA workflow completed.', $request->refresh()->archive_reason);
        $this->assertSame($admin->id, $request->archived_by);
        $this->assertSame($historyBefore + 1, $request->updates()->count());
        $this->assertSame(0, $request->newQuery()->active()->whereKey($request->id)->count());
        $this->assertSame(1, $request->newQuery()->archived()->whereKey($request->id)->count());
        $archival->restore($request, $admin);
        $archival->restore($request, $admin);
        $this->assertFalse($request->refresh()->isArchived());
        $this->assertSame(PersonnelRequestStatus::Completed, $request->status);
        $this->assertSame($historyBefore + 2, $request->updates()->count());
        $this->assertSame($signature, $request->officer_signature_path);
        Storage::disk((string) config('filesystems.private'))->assertExists($signature);
        $this->actingAs($beneficiary, 'employee')->get("/employee/my-requests/{$request->public_id}")
            ->assertOk()->assertSee('Pickup confirmed.')->assertDontSee('QA purchasing evidence retained.');
    }

    public function test_denied_and_cancelled_personnel_requests_retain_terminal_state_through_archive_restore(): void
    {
        $employee = $this->employee('23303', 'Firefighter');
        $admin = User::factory()->create();
        Role::findOrCreate('logistics_admin', 'web');
        $admin->assignRole('logistics_admin');
        foreach ([[PersonnelRequestStatus::Denied, 'denied_at'], [PersonnelRequestStatus::Cancelled, 'cancelled_at']] as [$status, $column]) {
            $request = app(PersonnelRequestSubmissionService::class)->submitUniform(
                $employee, [['item_code' => 't_shirt', 'size' => 'L', 'quantity' => 1]], 'terminal-archive-'.$status->value,
            );
            app(PersonnelRequestWorkflowService::class)->transition($request, $status, $admin, '[QA TEST] Terminal workflow verification.');
            $terminalAt = $request->refresh()->{$column};
            app(RequestArchivalService::class)->archive($request, $admin);
            app(RequestArchivalService::class)->restore($request, $admin);
            $this->assertSame($status, $request->refresh()->status);
            $this->assertTrue($terminalAt->equalTo($request->{$column}));
            $this->assertDatabaseCount('assigned_equipment', 0);
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_request_archive_migration_rolls_back_and_reapplies_without_deleting_request_history(): void
    {
        // SQLite cannot toggle foreign keys for its table rebuild while the
        // RefreshDatabase transaction is open. Isolate and replay as a real migration.
        DB::connection()->commit();
        $employee = $this->employee('23304', 'Firefighter');
        $request = app(PersonnelRequestSubmissionService::class)->submitUniform(
            $employee, [['item_code' => 't_shirt', 'size' => 'L', 'quantity' => 1]], 'archive-migration-retention-1',
        );
        $requestId = $request->id;
        $publicId = $request->public_id;
        $historyCount = $request->updates()->count();
        $migration = require database_path('migrations/2026_10_03_010001_add_request_archive_metadata.php');
        $migration->down();
        $migration->up();

        $request = PersonnelRequest::query()->findOrFail($requestId);
        $this->assertSame($publicId, $request->public_id);
        $this->assertSame(PersonnelRequestStatus::Pending, $request->status);
        $this->assertSame($historyCount, $request->updates()->count());
        $this->assertSame(1, $request->items()->count());
        $this->assertNull($request->archived_at);
        $this->assertNull($request->archived_by);
        $this->assertNull($request->archive_reason);
    }

    private function employee(string $employeeId, string $rank): Employee
    {
        return Employee::query()->create([
            'employee_id' => $employeeId,
            'name' => "Test {$rank} {$employeeId}",
            'rank' => $rank,
            'password' => bcrypt(str()->random(40)),
            'must_change_password' => false,
        ]);
    }

    private function signatureDataUrl(): string
    {
        return $this->pngDataUrl(false);
    }

    private function blankSignatureDataUrl(): string
    {
        return $this->pngDataUrl(true);
    }

    private function jpegDisguisedAsPngDataUrl(): string
    {
        $image = imagecreatetruecolor(120, 40);
        $ink = imagecolorallocate($image, 17, 24, 39);
        imageline($image, 8, 28, 108, 10, $ink);
        ob_start();
        imagejpeg($image);
        $jpeg = (string) ob_get_clean();
        imagedestroy($image);

        return 'data:image/png;base64,'.base64_encode($jpeg);
    }

    private function pngDataUrl(bool $blank): string
    {
        $image = imagecreatetruecolor(120, 40);
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefill($image, 0, 0, $white);
        if (! $blank) {
            $ink = imagecolorallocate($image, 17, 24, 39);
            imagesetthickness($image, 3);
            imageline($image, 8, 28, 108, 10, $ink);
        }
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        return 'data:image/png;base64,'.base64_encode($png);
    }
}
