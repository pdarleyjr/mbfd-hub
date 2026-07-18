<?php

declare(strict_types=1);

namespace Tests\Feature\OperationalForms;

use App\Models\Employee;
use App\Models\OperationalFormDocument;
use App\Models\OperationalFormRecord;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DocumentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private string $disk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePermissionTables();
        $this->disk = config('filesystems.private', 'local');
        Storage::fake($this->disk);
    }

    public function test_employee_preview_and_download_are_private_and_owner_scoped(): void
    {
        [$owner, $document] = $this->document();
        $other = $this->employee('90002');

        $preview = $this->actingAs($owner, 'employee')
            ->get('/employee/forms/api/documents/'.$document->id.'/preview')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('x-content-type-options', 'nosniff');
        $this->assertStringContainsString('private', $preview->headers->get('cache-control'));
        $this->assertStringContainsString('no-store', $preview->headers->get('cache-control'));
        $this->assertStringContainsString('inline;', $preview->headers->get('content-disposition'));

        $download = $this->get('/employee/forms/api/documents/'.$document->id.'/download')->assertOk();
        $this->assertStringContainsString('attachment;', $download->headers->get('content-disposition'));

        $this->actingAs($other, 'employee')
            ->get('/employee/forms/api/documents/'.$document->id.'/preview')
            ->assertNotFound();
    }

    public function test_admin_document_routes_use_web_guard_and_require_an_authorized_role(): void
    {
        [, $document] = $this->document();
        $admin = User::factory()->create();
        $adminRole = Role::query()->create(['name' => 'admin', 'guard_name' => 'web']);
        $admin->assignRole($adminRole);

        $this->actingAs($admin, 'web')
            ->get('/admin/operational-forms/documents/'.$document->id.'/preview')
            ->assertOk();

        auth('web')->logout();
        $unauthorized = User::factory()->create();
        $this->actingAs($unauthorized, 'web')
            ->get('/admin/operational-forms/documents/'.$document->id.'/download')
            ->assertForbidden();
    }

    private function employee(string $id = '90001'): Employee
    {
        return Employee::query()->create([
            'employee_id' => $id,
            'name' => 'Employee '.$id,
            'password' => Hash::make('password'),
            'must_change_password' => false,
        ]);
    }

    private function document(): array
    {
        $employee = $this->employee();
        $record = OperationalFormRecord::query()->create([
            'employee_id' => $employee->id,
            'form_type' => 'ics_214',
            'form_version' => '1.0',
            'title' => 'Private document',
            'status' => 'completed',
            'data' => [],
            'revision' => 1,
            'latest_pdf_version' => 1,
        ]);
        $path = 'operational-forms/ics_214/2026/07/'.$record->id.'/v1/test.pdf';
        Storage::disk($this->disk)->put($path, '%PDF-1.4 private test');
        $document = OperationalFormDocument::query()->create([
            'form_record_id' => $record->id,
            'version_number' => 1,
            'source_revision' => 1,
            'storage_disk' => $this->disk,
            'storage_path' => $path,
            'display_name' => 'ICS214_Test.pdf',
            'file_size' => 21,
            'page_count' => 1,
            'pdf_sha256' => hash('sha256', '%PDF-1.4 private test'),
            'source_snapshot' => [],
            'template_version' => '1.0',
            'template_sha256' => str_repeat('a', 64),
            'mapping_sha256' => str_repeat('b', 64),
            'generator_version' => 'test',
            'created_by_employee_id' => $employee->id,
        ]);

        return [$employee, $document];
    }

    private function ensurePermissionTables(): void
    {
        if (! Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();
                $table->unique(['name', 'guard_name']);
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
    }
}
