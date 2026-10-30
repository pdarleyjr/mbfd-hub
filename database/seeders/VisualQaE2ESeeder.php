<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Apparatus;
use App\Models\ApparatusInspection;
use App\Models\Employee;
use App\Models\Station;
use App\Models\User;
use App\Services\DailyCheckoutChecklistResolver;
use App\Services\Inventory\InventoryCorrectionReason;
use App\Services\Inventory\InventoryCorrectionService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Extra synthetic data for the Support Services visual QA run, on top of EmployeeMessagesE2ESeeder
 * (same disposable testing database):
 *
 *   - Fleet capability for the Logistics persona (99303), so the "Equipment Change Requests" card shows
 *   - 99306 Sam Super (super_admin) for pages that need an unrestricted administrator
 *   - Engine E1 with one submitted equipment correction from member A (a "moved" request) and one
 *     approved Daily Checkout inspection, so the review page and the issue page have something to show
 */
final class VisualQaE2ESeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('testing') || config('database.default') !== 'sqlite'
            || basename((string) config('database.connections.sqlite.database')) !== 'employee_messages_e2e.sqlite') {
            throw new RuntimeException('Visual QA fixtures require the disposable employee messages database.');
        }
        $password = env('EMPLOYEE_MESSAGES_E2E_PASSWORD');
        if (! is_string($password) || strlen($password) < 16) {
            throw new RuntimeException('The browser test password must be supplied.');
        }

        $logistics = User::query()->where('employee_id', '99303')->firstOrFail();
        foreach (['admin.fleet.view', 'admin.fleet.manage'] as $permission) {
            $logistics->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        $employee = Employee::query()->updateOrCreate(
            ['employee_id' => '99306'],
            ['name' => 'Sam Super', 'rank' => 'Division Chief', 'city_email' => 'messages-fixture-99306@miamibeachfl.gov', 'roster_status' => 'active'],
        );
        $super = User::query()->firstOrNew(['employee_id' => '99306']);
        $super->forceFill([
            'name' => 'Sam Super', 'email' => $employee->city_email, 'password' => Hash::make($password), 'must_change_password' => false,
            'employee_id' => '99306', 'employee_profile_id' => $employee->id, 'account_status' => 'active',
        ])->save();
        $super->forceFill(['email_verified_at' => now()])->save();
        $super->syncRoles([Role::findOrCreate('super_admin', 'web')]);
        $super->givePermissionTo(Permission::findOrCreate('admin.access', 'web'));

        $station = Station::query()->create(['station_number' => 1, 'name' => 'Station 1', 'address' => '1051 Jefferson Avenue', 'is_active' => true]);
        $apparatus = Apparatus::query()->create([
            'station_id' => $station->id, 'unit_id' => 'E1', 'designation' => 'E1', 'name' => 'Engine E1', 'type' => 'Engine',
            'slug' => Str::slug('engine-e1'), 'vin' => 'VIN-E1', 'vehicle_number' => '1001', 'status' => 'In Service',
            'daily_checkout_requirement' => 'required', 'daily_checkout_template' => 'engine',
            'current_engine_hours' => 400, 'current_miles' => 1000,
        ]);
        ApparatusInspection::query()->create([
            'apparatus_id' => $apparatus->id, 'client_submission_id' => (string) Str::uuid(),
            'inspection_reference' => 'INS-VISUALQA', 'operator_name' => 'Pat Rivera', 'rank' => 'Lieutenant', 'shift' => 'A',
            'unit_number' => 'E1', 'vehicle_number' => '1001', 'designation_at_time' => 'E1',
            'review_status' => 'approved', 'processing_status' => 'accepted', 'engine_hours' => 401.5, 'miles' => 1010,
            'results' => [], 'completed_at' => now()->utc(),
        ]);

        $resolution = app(DailyCheckoutChecklistResolver::class)->resolve($apparatus);
        $compartments = $resolution['checklist']['compartments'];
        $from = null;
        $to = null;
        foreach ($compartments as $index => $compartment) {
            foreach ($compartment['items'] as $item) {
                if ($item['name'] === 'Passports') {
                    $from = [$compartment['id'], $item['id']];
                    $to = $compartments[($index + 1) % count($compartments)]['id'];
                }
            }
        }
        if ($from === null || $to === null) {
            throw new RuntimeException('The Engine checklist has no Passports item to move.');
        }

        $reporter = User::query()->where('employee_id', '99301')->firstOrFail();
        app(InventoryCorrectionService::class)->submit($reporter, (int) $reporter->employee_profile_id, $apparatus, [
            'client_submission_id' => (string) Str::uuid(),
            'checklist_version' => $resolution['checklist_version'],
            'reason' => InventoryCorrectionReason::MOVED,
            'compartment_id' => $from[0],
            'item_id' => $from[1],
            'proposal' => ['to_compartment_id' => $to],
            'note' => 'Now behind the officer seat.',
        ]);
    }
}
