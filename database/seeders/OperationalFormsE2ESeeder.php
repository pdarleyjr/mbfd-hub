<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\OperationalFormRecord;
use App\Models\User;
use App\Services\OperationalForms\PdfGenerationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

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
                'password' => Hash::make(env('OPERATIONAL_FORMS_E2E_PASSWORD', 'OperationalForms!1')),
                'must_change_password' => false,
            ],
        );

        $this->ensurePermissionTables();
        $adminId = DB::table('users')->insertGetId([
            'name' => 'Operational Forms Test Admin',
            'email' => env('OPERATIONAL_FORMS_E2E_ADMIN_EMAIL', 'forms-admin@example.test'),
            'password' => Hash::make(env('OPERATIONAL_FORMS_E2E_ADMIN_PASSWORD', 'OperationalFormsAdmin!1')),
            'is_admin' => true,
            'must_change_password' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $roleId = DB::table('roles')->insertGetId([
            'name' => 'admin',
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('model_has_roles')->insert([
            'role_id' => $roleId,
            'model_type' => User::class,
            'model_id' => $adminId,
        ]);

        $this->seedControlledSamples($employee);
    }

    private function ensurePermissionTables(): void
    {
        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });
        Schema::create('model_has_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->primary(['permission_id', 'model_id', 'model_type']);
        });
        Schema::create('model_has_roles', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->primary(['role_id', 'model_id', 'model_type']);
        });
        Schema::create('role_has_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
            $table->primary(['permission_id', 'role_id']);
        });
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
