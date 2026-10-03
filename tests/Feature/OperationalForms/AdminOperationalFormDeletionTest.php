<?php

declare(strict_types=1);

namespace Tests\Feature\OperationalForms;

use App\Filament\Resources\OperationalFormRecordResource\Pages\ListOperationalFormRecords;
use App\Filament\Resources\OperationalFormRecordResource\Pages\ViewOperationalFormRecord;
use App\Models\Employee;
use App\Models\OperationalFormDocument;
use App\Models\OperationalFormEvent;
use App\Models\OperationalFormRecord;
use App\Models\User;
use App\Services\OperationalEvidenceArchiveService;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
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

    public function test_each_admin_role_can_trash_and_restore_drafts_and_completed_records_without_removing_evidence(): void
    {
        foreach (['super_admin', 'admin', 'logistics_admin'] as $index => $roleName) {
            $admin = $this->admin($roleName);
            [$record, $document] = $this->recordWithDocument('record-'.$index);

            $this->actingAsCanonicalUser($admin)
                ->delete('/admin/operational-forms/records/'.$record->id)
                ->assertNoContent();

            $this->assertSoftDeleted($record);
            $this->assertDatabaseHas('operational_form_documents', ['id' => $document->id]);
            $this->assertDatabaseHas('operational_form_events', ['form_record_id' => $record->id, 'event_type' => 'record_trashed']);
            Storage::disk($this->disk)->assertExists($document->storage_path);
            app(OperationalEvidenceArchiveService::class)->restore($record, $admin);
            $this->assertNotSoftDeleted($record);
            self::assertSame(1, $record->fresh()->latest_pdf_version);
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

        $this->actingAsCanonicalUser($this->admin('admin'))
            ->delete('/admin/operational-forms/records/'.$draft->id)
            ->assertNoContent();
        $this->assertSoftDeleted($draft);
    }

    public function test_non_admin_cannot_delete_records_or_documents(): void
    {
        [$record, $document] = $this->recordWithDocument();
        $user = User::factory()->create();

        $this->actingAsCanonicalUser($user)
            ->delete('/admin/operational-forms/records/'.$record->id)
            ->assertForbidden();
        $this->delete('/admin/operational-forms/documents/'.$document->id)
            ->assertForbidden();

        $this->assertDatabaseHas('operational_form_records', ['id' => $record->id]);
        Storage::disk($this->disk)->assertExists($document->storage_path);
    }

    public function test_document_versions_cannot_be_permanently_deleted_through_the_normal_admin_route(): void
    {
        $admin = $this->admin('admin');
        [$record, $first] = $this->recordWithDocument();
        $second = $this->document($record, 2);
        $record->update(['latest_pdf_version' => 2, 'status' => 'completed', 'completed_at' => now()]);

        $this->actingAsCanonicalUser($admin)
            ->delete('/admin/operational-forms/documents/'.$second->id)
            ->assertForbidden();

        $record->refresh();
        $this->assertSame(2, $record->latest_pdf_version);
        $this->assertSame('completed', $record->status);
        Storage::disk($this->disk)->assertExists($second->storage_path);
        Storage::disk($this->disk)->assertExists($first->storage_path);

        $this->assertDatabaseCount('operational_form_documents', 2);
        $this->assertNotNull($record->completed_at);
    }

    public function test_admin_filters_find_archived_and_trashed_forms_and_restore_documents_and_history(): void
    {
        $admin = $this->admin('admin');
        [$record, $first] = $this->recordWithDocument();
        $second = $this->document($record, 2);
        $record->update(['latest_pdf_version' => 2]);
        $this->actingAsCanonicalUser($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withoutVite();

        Livewire::test(ListOperationalFormRecords::class)
            ->call('loadTable')
            ->assertCanSeeTableRecords([$record])
            ->callTableAction('archive', $record, data: ['archive_reason' => 'Completed QA review'])
            ->assertHasNoTableActionErrors()
            ->assertCanNotSeeTableRecords([$record])
            ->filterTable('archive_state', 'archived')
            ->assertCanSeeTableRecords([$record]);

        $record->refresh();
        self::assertSame($admin->id, $record->archived_by);
        self::assertSame('Completed QA review', $record->archive_reason);
        $this->get('/admin/operational-forms/documents/'.$second->id.'/download')->assertOk();

        Livewire::test(ViewOperationalFormRecord::class, ['record' => $record->id])
            ->assertSee('Document history')
            ->assertActionVisible('restore')
            ->callAction('restore')->assertHasNoActionErrors()
            ->assertActionVisible('archive')
            ->callAction('delete')->assertHasNoActionErrors();

        $this->assertSoftDeleted($record);
        Livewire::test(ListOperationalFormRecords::class)
            ->call('loadTable')
            ->assertCanNotSeeTableRecords([$record])
            ->filterTable('archive_state', 'trash')
            ->assertCanSeeTableRecords([$record])
            ->callTableAction('restore', $record)->assertHasNoTableActionErrors()
            ->filterTable('archive_state', 'active')
            ->assertCanSeeTableRecords([$record]);

        $this->assertNotSoftDeleted($record);
        $this->assertDatabaseHas('operational_form_events', ['form_record_id' => $record->id, 'event_type' => 'record_archived']);
        $this->assertDatabaseHas('operational_form_events', ['form_record_id' => $record->id, 'event_type' => 'record_restored']);
        self::assertSame('Completed QA review', $record->events()->where('event_type', 'record_archived')->sole()->metadata['archive_reason']);
        self::assertTrue($record->events()->where('event_type', 'record_restored')->get()
            ->contains(fn (OperationalFormEvent $event): bool => ($event->metadata['archive_reason'] ?? null) === 'Completed QA review'));
        $this->assertDatabaseCount('operational_form_documents', 2);
        Storage::disk($this->disk)->assertExists($first->storage_path);
        Storage::disk($this->disk)->assertExists($second->storage_path);

        $owner = $record->employee;
        $this->logoutCanonicalSession();
        $this->actingAs($owner, 'employee')
            ->get('/employee/forms/api/documents/'.$first->id.'/download')->assertOk();
    }

    public function test_forms_viewer_has_no_archive_trash_or_restore_actions(): void
    {
        $viewer = $this->admin('admin');
        $viewer->revokePermissionTo('admin.forms.manage');
        [$record] = $this->recordWithDocument();
        $this->actingAsCanonicalUser($viewer);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withoutVite();

        Livewire::test(ViewOperationalFormRecord::class, ['record' => $record->id])
            ->assertActionHidden('archive')
            ->assertActionHidden('delete')
            ->assertActionHidden('restore');
        $this->delete('/admin/operational-forms/records/'.$record->id)->assertForbidden();
        $this->assertNotSoftDeleted($record);
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
        $user->givePermissionTo([
            Permission::findOrCreate('admin.access', 'web'),
            Permission::findOrCreate('admin.forms.view', 'web'),
            Permission::findOrCreate('admin.forms.manage', 'web'),
        ]);

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
