<?php

namespace Database\Seeders;

use App\Models\Apparatus;
use App\Models\DepartmentUpdate;
use App\Models\Employee;
use App\Models\OperationalFormRecord;
use App\Models\Station;
use App\Models\User;
use App\Models\Workgroup;
use App\Models\WorkgroupMember;
use App\Services\Ems\Import\SourceImporter;
use App\Services\OperationalForms\PdfGenerationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Spatie\Permission\Models\Permission;

class OperationalFormsE2ESeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('The operational forms E2E seed is restricted to local and testing environments.');
        }

        $employee = Employee::query()->updateOrCreate(
            ['employee_id' => env('OPERATIONAL_FORMS_E2E_EMPLOYEE_ID', 'E214')],
            [
                'name' => 'Operational Forms Test Member',
                'rank' => 'Firefighter',
                'city_email' => 'forms-member-fixture@miamibeachfl.gov',
                'roster_status' => 'active',
                'password' => Hash::make(env('OPERATIONAL_FORMS_E2E_PASSWORD', 'OperationalForms!1')),
                'must_change_password' => false,
            ],
        );
        $canonicalUser = User::query()->firstOrNew([
            'email' => $employee->city_email,
        ]);
        $canonicalUser->forceFill([
            'name' => 'Operational Forms Test Member',
            'password' => Hash::make(env('OPERATIONAL_FORMS_E2E_PASSWORD', 'OperationalForms!1')),
            'employee_id' => $employee->employee_id,
            'employee_profile_id' => $employee->id,
            'account_status' => 'active',
            'must_change_password' => false,
        ])->save();
        $canonicalUser->forceFill(['email_verified_at' => now()])->save();

        $adminEmployee = Employee::query()->updateOrCreate(
            ['employee_id' => env('OPERATIONAL_FORMS_E2E_ADMIN_EMPLOYEE_ID', 'E215')],
            [
                'name' => 'Operational Forms Test Admin',
                'rank' => 'Captain',
                'city_email' => env('OPERATIONAL_FORMS_E2E_ADMIN_EMAIL', 'forms-admin-fixture@miamibeachfl.gov'),
                'roster_status' => 'active',
                'password' => Hash::make(env('OPERATIONAL_FORMS_E2E_ADMIN_PASSWORD', 'OperationalFormsAdmin!1')),
                'must_change_password' => false,
            ],
        );
        $admin = User::query()->firstOrNew([
            'email' => $adminEmployee->city_email,
        ]);
        $admin->forceFill([
            'name' => 'Operational Forms Test Admin',
            'password' => Hash::make(env('OPERATIONAL_FORMS_E2E_ADMIN_PASSWORD', 'OperationalFormsAdmin!1')),
            'employee_id' => $adminEmployee->employee_id,
            'employee_profile_id' => $adminEmployee->id,
            'account_status' => 'active',
            'is_admin' => true,
            'must_change_password' => false,
        ])->save();
        $admin->forceFill(['email_verified_at' => now()])->save();
        $this->ensurePermissionTables();
        $roleId = DB::table('roles')->insertGetId([
            'name' => 'admin',
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('model_has_roles')->insert([
            'role_id' => $roleId,
            'model_type' => User::class,
            'model_id' => $admin->id,
        ]);
        $admin->givePermissionTo([
            Permission::findOrCreate('admin.access', 'web'),
            Permission::findOrCreate('admin.department_updates.view', 'web'),
            Permission::findOrCreate('admin.department_updates.manage', 'web'),
            Permission::findOrCreate('admin.forms.view', 'web'),
            Permission::findOrCreate('admin.forms.manage', 'web'),
            Permission::findOrCreate('app.media_control.access', 'web'),
        ]);
        DepartmentUpdate::query()->create([
            'title' => 'Department Operations Briefing',
            'body' => '<p>Review the current department operations briefing before your next shift.</p>',
            'category' => 'operations',
            'priority' => 'important',
            'status' => 'published',
            'publish_at' => now()->subMinute(),
            'send_in_app' => false,
            'send_web_push' => false,
            'audience' => 'everyone',
            'author_id' => $admin->id,
        ]);
        $workgroup = Workgroup::query()->updateOrCreate(
            ['name' => 'Operational Forms E2E Workgroup'],
            [
                'description' => 'Isolated browser navigation acceptance fixture.',
                'is_active' => true,
                'created_by' => $admin->id,
            ],
        );
        WorkgroupMember::query()->updateOrCreate(
            [
                'workgroup_id' => $workgroup->id,
                'user_id' => $admin->id,
            ],
            [
                'role' => 'admin',
                'is_active' => true,
            ],
        );

        $this->seedControlledSamples($employee);
        $this->seedMedicalInventoryRequest();
    }

    /** The tracked EMS source forms (Airway Bag, ALS Med Bag, ALS Transport Vehicle) and two units for the Medical Inventory Request. */
    private function seedMedicalInventoryRequest(): void
    {
        app(SourceImporter::class)->import(base_path('database/data/ems'));
        $station = Station::query()->firstOrCreate(['station_number' => 2], ['name' => 'Station 2', 'address' => '1 Test St', 'is_active' => true]);
        // Named the way production names them: R2 and R22, R4 and R44 differ by one character, which the unit picker must never confuse.
        $units = [['R1', 'RESCUE R1', 'R 1'], ['R11', 'RESCUE R11', 'R 11'], ['R2', 'RESCUE R2', 'R 2'], ['R22', 'RESCUE R22', 'R 22'], ['R3', 'RESCUE R3', 'R 3'], ['R4', 'RESCUE R4', 'R 4'], ['R44', 'RESCUE R44', 'R 44']];
        foreach ([...array_map(static fn (array $row): array => [...$row, 'Rescue', 'rescue'], $units), ['E1', 'ENGINE 1', 'E 1', 'Engine', 'engine']] as [$unit, $name, $designation, $type, $template]) {
            Apparatus::query()->firstOrCreate(['unit_id' => $unit], [
                'station_id' => $station->id, 'vin' => 'VIN-'.$unit, 'assignment' => 'Station 2', 'designation' => $designation, 'name' => $name, 'type' => $type,
                'vehicle_number' => 'V-'.$unit, 'status' => 'In Service', 'daily_checkout_requirement' => 'required', 'daily_checkout_template' => $template,
            ]);
        }
    }

    private function ensurePermissionTables(): void
    {
        if (! Schema::hasTable('permissions')) {
            Schema::create('permissions', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();
                $table->unique(['name', 'guard_name']);
            });
        }

        if (! Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();
                $table->unique(['name', 'guard_name']);
            });
        }

        if (! Schema::hasTable('model_has_permissions')) {
            Schema::create('model_has_permissions', function (Blueprint $table): void {
                $table->unsignedBigInteger('permission_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->primary(['permission_id', 'model_id', 'model_type']);
            });
        }

        if (! Schema::hasTable('model_has_roles')) {
            Schema::create('model_has_roles', function (Blueprint $table): void {
                $table->unsignedBigInteger('role_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->primary(['role_id', 'model_id', 'model_type']);
            });
        }

        if (! Schema::hasTable('role_has_permissions')) {
            Schema::create('role_has_permissions', function (Blueprint $table): void {
                $table->unsignedBigInteger('permission_id');
                $table->unsignedBigInteger('role_id');
                $table->primary(['permission_id', 'role_id']);
            });
        }
    }

    private function seedControlledSamples(Employee $employee): void
    {
        $samples = [
            ['ics_214', '1.0', 'E2E Controlled ICS 214', 'ics-214-sample.json'],
            ['froc_log_001_ff', '11', 'E2E Controlled F-ROC v11', 'froc-log-001-ff-sample.json'],
        ];

        foreach ($samples as [$formType, $version, $title, $fixture]) {
            $fixtureData = json_decode(
                file_get_contents(base_path('tests/Fixtures/OperationalForms/'.$fixture)),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            $record = OperationalFormRecord::query()->create([
                'employee_id' => $employee->id,
                'form_type' => $formType,
                'form_version' => $version,
                'title' => $title,
                'status' => 'draft',
                'data' => $fixtureData['data'],
                'revision' => 1,
                'last_autosaved_at' => now(),
            ]);

            app(PdfGenerationService::class)->generate($record, $employee);
        }
    }
}
