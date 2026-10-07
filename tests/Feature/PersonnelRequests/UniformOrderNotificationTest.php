<?php

declare(strict_types=1);

namespace Tests\Feature\PersonnelRequests;

use App\Enums\PersonnelRequestStatus;
use App\Models\Employee;
use App\Models\User;
use App\Services\PersonnelRequests\PersonnelRequestSubmissionService;
use App\Services\PersonnelRequests\PersonnelRequestWorkflowService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class UniformOrderNotificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Actual commits exercise afterCommit delivery against the isolated in-memory test database.
        $this->artisan('migrate:fresh');
        $this->app[Kernel::class]->setArtisan(null);
    }

    public function test_structured_request_notifies_existing_admin_recipients_once_after_commit_and_member_on_status_change(): void
    {
        $employee = Employee::query()->create(['employee_id' => 'UNIFORM-NOTIFY', 'name' => 'Notification Member',
            'rank' => 'Firefighter', 'password' => 'unused-fixture-credential', 'must_change_password' => false]);
        Role::findOrCreate('logistics_admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('logistics_admin');
        $service = app(PersonnelRequestSubmissionService::class);
        $items = [['item_code' => 't_shirt', 'quantity' => 5, 'metadata' => ['size' => 'L']]];
        DB::beginTransaction();
        try {
            $request = $service->submitUniform($employee, $items, 'uniform-notification-1', ['member_note' => 'Extra shirts for review.']);
            $service->submitUniform($employee, $items, 'uniform-notification-1');
            $this->assertDatabaseCount('notifications', 0);
            DB::commit();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }

        $this->assertDatabaseCount('notifications', 1);
        $notification = $admin->notifications()->sole();
        $this->assertSame('New Uniform Request', $notification->data['title']);
        $this->assertSame('/admin/personnel-uniforms-equipment/personnel-requests/'.$request->public_id, data_get($notification->data, 'actions.0.url'));
        app(PersonnelRequestWorkflowService::class)->transition($request, PersonnelRequestStatus::Acknowledged, $admin);
        $this->assertSame(1, $employee->notifications()->count());
        $this->assertSame('Request Acknowledged', $employee->notifications()->sole()->data['title']);
        $this->assertSame('/employee/my-requests/'.$request->public_id, data_get($employee->notifications()->sole()->data, 'actions.0.url'));
    }
}
