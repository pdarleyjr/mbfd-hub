<?php

declare(strict_types=1);

namespace Tests\Feature\PersonnelRequests;

use App\Enums\PersonnelRequestStatus;
use App\Models\AssignedEquipment;
use App\Models\Employee;
use App\Models\PersonnelRequestAttachment;
use App\Models\Uniform;
use App\Models\User;
use App\Services\PersonnelRequests\PersonnelRequestFulfillmentService;
use App\Services\PersonnelRequests\PersonnelRequestSubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PersonnelRequestSecurityAndLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_beneficiary_can_view_respond_and_upload_requested_private_document(): void
    {
        Storage::fake((string) config('filesystems.private'));
        $beneficiary = $this->employee('24001');
        $other = $this->employee('24002');
        $request = $this->uniformRequest($beneficiary);
        $request->update([
            'status' => PersonnelRequestStatus::NeedsInformation,
            'information_requested' => ['police_report'],
            'employee_response' => 'Please provide the police report if one was obtained.',
        ]);

        $this->actingAs($other, 'employee')
            ->get("/employee/my-requests/{$request->public_id}")
            ->assertForbidden();

        $this->actingAs($other, 'employee')
            ->post("/employee/personnel-requests/{$request->public_id}/attachments", [
                'document_type' => 'police_report',
                'attachment' => UploadedFile::fake()->createWithContent('report.pdf', "%PDF-1.4\n% test"),
            ])
            ->assertForbidden();

        $this->actingAs($beneficiary, 'employee')
            ->post("/employee/personnel-requests/{$request->public_id}/attachments", [
                'document_type' => 'police_report',
                'attachment' => UploadedFile::fake()->createWithContent('report.pdf', "%PDF-1.4\n% test"),
            ])
            ->assertRedirect();

        $attachment = PersonnelRequestAttachment::query()->sole();
        $this->assertSame((string) config('filesystems.private'), $attachment->disk);
        $this->assertSame('report.pdf', $attachment->original_filename);
        $this->assertNotSame('report.pdf', $attachment->generated_filename);
        $this->assertSame(64, strlen($attachment->sha256));
        Storage::disk($attachment->disk)->assertExists($attachment->storage_path);

        $this->actingAs($beneficiary, 'employee')
            ->post("/employee/personnel-requests/{$request->public_id}/respond", [
                'response' => 'The requested report is attached for Support Services review.',
            ])
            ->assertRedirect();
        $this->assertSame(PersonnelRequestStatus::Acknowledged, $request->refresh()->status);
        $this->actingAs($beneficiary, 'employee')
            ->post("/employee/personnel-requests/{$request->public_id}/attachments", [
                'document_type' => 'police_report',
                'attachment' => UploadedFile::fake()->createWithContent('supplement.pdf', "%PDF-1.4\n% supplement"),
            ])
            ->assertRedirect();
        $this->assertDatabaseCount('personnel_request_attachments', 2);

        $this->get("/employee/personnel-request-attachments/{$attachment->public_id}")->assertOk();
        $this->post('/logout')->assertRedirect('/login');
        $this->get("/employee/personnel-request-attachments/{$attachment->public_id}")->assertRedirect('/login');
        $this->actingAs($other, 'employee')
            ->get("/employee/personnel-request-attachments/{$attachment->public_id}")
            ->assertForbidden();
    }

    public function test_disallowed_and_oversized_documents_are_rejected(): void
    {
        Storage::fake((string) config('filesystems.private'));
        $employee = $this->employee('24101');
        $request = $this->uniformRequest($employee);
        $request->update(['status' => PersonnelRequestStatus::NeedsInformation, 'information_requested' => ['other']]);

        $this->actingAs($employee, 'employee')
            ->post("/employee/personnel-requests/{$request->public_id}/attachments", [
                'document_type' => 'other',
                'attachment' => UploadedFile::fake()->create('payload.exe', 1, 'application/x-msdownload'),
            ])
            ->assertSessionHasErrors('attachment');

        $this->post("/employee/personnel-requests/{$request->public_id}/attachments", [
            'document_type' => 'other',
            'attachment' => UploadedFile::fake()->create('large.pdf', 10_241, 'application/pdf'),
        ])->assertSessionHasErrors('attachment');

        $this->assertDatabaseCount('personnel_request_attachments', 0);
    }

    public function test_uniform_fulfillment_decrements_once_links_assignment_and_retirement_keeps_history(): void
    {
        $employee = $this->employee('25001');
        $request = $this->uniformRequest($employee);
        $item = $request->items()->firstOrFail();
        $uniform = Uniform::query()->create([
            'item_name' => 'T-Shirt',
            'size' => 'L',
            'quantity_on_hand' => 5,
            'reorder_level' => 1,
        ]);
        $admin = User::factory()->create();
        $service = app(PersonnelRequestFulfillmentService::class);

        $first = $service->issueUniform($item, $uniform, $admin, now()->toDateString(), now()->addYear()->toDateString());
        $retry = $service->issueUniform($item->refresh(), $uniform->refresh(), $admin, now()->toDateString(), now()->addYear()->toDateString());

        $this->assertTrue($first->is($retry));
        $this->assertSame(3, $uniform->refresh()->quantity_on_hand);
        $this->assertSame($item->id, $first->source_personnel_request_item_id);
        $this->assertSame('active', $first->status);

        $service->retire($first, $admin, now()->toDateString(), 'Retired after replacement.', 'retired');
        $this->assertDatabaseHas('assigned_equipment', [
            'id' => $first->id,
            'status' => 'retired',
            'retirement_reason' => 'Retired after replacement.',
        ]);
        $this->assertDatabaseCount('assigned_equipment', 1);
    }

    public function test_expiration_notifications_are_thresholded_deduplicated_and_reset_by_date_change(): void
    {
        Carbon::setTestNow('2026-08-18 08:00:00');
        $employee = $this->employee('26001');
        $admin = User::factory()->create();
        Role::findOrCreate('logistics_admin', 'web');
        $admin->assignRole('logistics_admin');
        $assignment = AssignedEquipment::query()->create([
            'employee_portal_id' => $employee->id,
            'user_id' => null,
            'category' => 'Bunker Coat',
            'item_description' => 'Globe Bunker Coat',
            'quantity' => 1,
            'issued_at' => now()->subYear(),
            'status' => 'active',
            'expires_at' => now()->addDays(30),
        ]);

        $this->artisan('personnel-equipment:notify-expirations')->assertSuccessful();
        $this->assertDatabaseCount('equipment_expiration_notifications', 2);
        $this->assertDatabaseHas('notifications', ['notifiable_type' => Employee::class, 'notifiable_id' => $employee->id]);
        $this->assertDatabaseHas('notifications', ['notifiable_type' => User::class, 'notifiable_id' => $admin->id]);
        $employeeNotification = $employee->notifications()->firstOrFail();
        $adminNotification = $admin->notifications()->firstOrFail();
        $this->assertSame('/employee/my-equipment-page', data_get($employeeNotification->data, 'actions.0.url'));
        $this->assertSame('/admin/personnel-uniforms-equipment/assignments', data_get($adminNotification->data, 'actions.0.url'));

        Carbon::setTestNow('2026-08-19 08:00:00');
        $this->artisan('personnel-equipment:notify-expirations')->assertSuccessful();
        $this->assertDatabaseCount('equipment_expiration_notifications', 2);

        Carbon::setTestNow('2026-09-10 08:00:00');
        $this->artisan('personnel-equipment:notify-expirations')->assertSuccessful();
        $this->assertDatabaseCount('equipment_expiration_notifications', 4);

        $assignment->update(['expires_at' => now()->addDays(30)]);
        $this->artisan('personnel-equipment:notify-expirations')->assertSuccessful();
        $this->assertDatabaseCount('equipment_expiration_notifications', 6);
    }

    private function employee(string $employeeId): Employee
    {
        return Employee::query()->create([
            'employee_id' => $employeeId,
            'name' => "Test Employee {$employeeId}",
            'rank' => 'Firefighter',
            'password' => bcrypt(str()->random(40)),
            'must_change_password' => false,
        ]);
    }

    private function uniformRequest(Employee $employee)
    {
        return app(PersonnelRequestSubmissionService::class)->submitUniform(
            $employee,
            [['item_code' => 't_shirt', 'size' => 'L', 'quantity' => 2]],
            'request-'.$employee->employee_id,
        );
    }
}
