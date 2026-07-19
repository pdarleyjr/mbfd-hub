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

class AdminOperationalFormDeletionTest extends TestCase
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

    public function test_each_admin_role_can_delete_draft_and_completed_records_with_private_files(): void
    {
        foreach (['super_admin', 'admin', 'logistics_admin'] as $index => $roleName) {
            $admin = $this->admin($roleName);
            [$record, $document] = $this->recordWithDocument('record-'.$index);

            $this->actingAs($admin, 'web')
                ->delete('/admin/operational-forms/records/'.$record->id)
                ->assertNoContent();

            $this->assertDatabaseMissing('operational_form_records', ['id' => $record->id]);
            $this->assertDatabaseMissing('operational_form_documents', ['id' => $document->id]);
            Storage::disk($this->disk)->assertMissing($document->storage_path);
            auth('web')->logout();
        }

        $draft = OperationalFormRecord::query()->create([
            'employee_id' => $this->employee()->id,
            'form_type' => 'ics_214',
            'form_version' => '1.0',
            'title' => 'Draft to delete',
            'status' => 'draft',
            'data' => [],
            'revision' => 1,
        ]);

        $this->actingAs($this->admin('admin'), 'web')
            ->delete('/admin/operational-forms/records/'.$draft->id)
            ->assertNoContent();
        $this->assertDatabaseMissing('operational_form_records', ['id' => $draft->id]);
    }

    public function test_non_admin_cannot_delete_records_or_documents(): void
    {
        [$record, $document] = $this->recordWithDocument();
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->delete('/admin/operational-forms/records/'.$record->id)
            ->assertForbidden();
        $this->delete('/admin/operational-forms/documents/'.$document->id)
            ->assertForbidden();

        $this->assertDatabaseHas('operational_form_records', ['id' => $record->id]);
        Storage::disk($this->disk)->assertExists($document->storage_path);
    }

    public function test_admin_can_delete_one_pdf_version_and_record_state_is_reconciled(): void
    {
        $admin = $this->admin('admin');
        [$record, $first] = $this->recordWithDocument();
        $second = $this->document($record, 2);
        $record->update(['latest_pdf_version' => 2, 'status' => 'completed', 'completed_at' => now()]);

        $this->actingAs($admin, 'web')
            ->delete('/admin/operational-forms/documents/'.$second->id)
            ->assertNoContent();

        $record->refresh();
        $this->assertSame(1, $record->latest_pdf_version);
        $this->assertSame('completed', $record->status);
        Storage::disk($this->disk)->assertMissing($second->storage_path);
        Storage::disk($this->disk)->assertExists($first->storage_path);

        $this->delete('/admin/operational-forms/documents/'.$first->id)
            ->assertNoContent();

        $record->refresh();
        $this->assertNull($record->latest_pdf_version);
        $this->assertNull($record->completed_at);
        $this->assertSame('draft', $record->status);
    }

    private function recordWithDocument(string $suffix = 'one'): array
    {
        $employee = $this->employee('9'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT));
        $record = OperationalFormRecord::query()->create([
            'employee_id' => $employee->id,
            'form_type' => 'froc_log_001_ff',
            'form_version' => '11',
            'title' => 'Completed '.$suffix,
            'status' => 'completed',
            'data' => [],
            'revision' => 1,
            'latest_pdf_version' => 1,
            'completed_at' => now(),
        ]);

        return [$record, $this->document($record, 1)];
    }

    private function document(OperationalFormRecord $record, int $version): OperationalFormDocument
    {
        $path = "operational-forms/test/{$record->id}/v{$version}/file.pdf";
        $contents = '%PDF test '.$version;
        Storage::disk($this->disk)->put($path, $contents);

        return OperationalFormDocument::query()->create([
            'form_record_id' => $record->id,
            'version_number' => $version,
            'source_revision' => 1,
            'storage_disk' => $this->disk,
            'storage_path' => $path,
            'display_name' => "file-v{$version}.pdf",
            'mime_type' => 'application/pdf',
            'file_size' => strlen($contents),
            'page_count' => 1,
            'pdf_sha256' => hash('sha256', $contents),
            'source_snapshot' => [],
            'template_version' => 'test',
            'template_sha256' => str_repeat('a', 64),
            'mapping_sha256' => str_repeat('b', 64),
            'generator_version' => 'test',
            'created_by_employee_id' => $record->employee_id,
        ]);
    }

    private function employee(string $employeeId = '90001'): Employee
    {
        return Employee::query()->firstOrCreate(['employee_id' => $employeeId], [
            'name' => 'Employee '.$employeeId,
            'password' => Hash::make('password'),
            'must_change_password' => false,
        ]);
    }

    private function admin(string $roleName): User
    {
        $role = Role::query()->firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
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
