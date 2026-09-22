<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\Workgroup\Pages\CreateWorkgroupFile;
use App\Filament\Resources\Workgroup\Pages\EditWorkgroupFile;
use App\Filament\Resources\Workgroup\Pages\ViewWorkgroup;
use App\Filament\Resources\Workgroup\RelationManagers\FilesRelationManager;
use App\Filament\Workgroup\Pages\SharedUploads;
use App\Models\User;
use App\Models\Workgroup;
use App\Models\WorkgroupFile;
use App\Models\WorkgroupMember;
use App\Models\WorkgroupSession;
use App\Models\WorkgroupSharedUpload;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class WorkgroupFileUploadTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Workgroup $workgroup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($this->admin);
        $this->workgroup = Workgroup::create([
            'name' => 'Back to Basics - Train the Trainer',
            'created_by' => $this->admin->id,
            'is_active' => true,
        ]);
        config(['filesystems.default' => 'r2', 'filesystems.private' => 'local', 'filament.default_filesystem_disk' => 'r2']);
        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('r2');
    }

    public function test_temporary_upload_accepts_a_twenty_megabyte_pdf_and_rejects_over_fifty(): void
    {
        self::assertSame('local', config('livewire.temporary_file_upload.disk'));
        self::assertTrue(Validator::make(['file' => UploadedFile::fake()->create('manual.pdf', 20480, 'application/pdf')], ['file' => FileUploadConfiguration::rules()])->passes());
        self::assertFalse(Validator::make(['file' => UploadedFile::fake()->create('too-large.pdf', 51201, 'application/pdf')], ['file' => FileUploadConfiguration::rules()])->passes());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_admin_resource_stores_pdf_privately_with_original_name_and_size(): void
    {
        // Exercise real large-file I/O without retaining the entire suite or duplicating its payload in memory.
        $stream = tmpfile();
        self::assertIsResource($stream);
        fwrite($stream, "%PDF-1.7\n");
        for ($megabyte = 0; $megabyte < 20; $megabyte++) {
            fwrite($stream, str_repeat('x', 1024 * 1024));
        }
        $upload = new File('essentials.pdf', $stream);
        self::assertSame(20 * 1024 * 1024 + 9, $upload->getSize());
        Livewire::test(CreateWorkgroupFile::class)
            ->fillForm(['workgroup_id' => $this->workgroup->id, 'filepath' => $upload])
            ->call('create')
            ->assertHasNoFormErrors();

        $file = WorkgroupFile::query()->sole();
        self::assertSame('essentials.pdf', $file->filename);
        self::assertSame($upload->getSize(), $file->file_size);
        self::assertSame($this->admin->id, $file->uploaded_by);
        Storage::disk('local')->assertExists($file->filepath);
        Storage::disk('r2')->assertMissing($file->filepath);
        Storage::disk('public')->assertMissing($file->filepath);
        $this->get(route('workgroup.file.download', $file))->assertOk()->assertDownload('essentials.pdf');
        $this->actingAs(User::factory()->create())->get(route('workgroup.file.download', $file))->assertNotFound();
    }

    public function test_workgroup_view_allows_manager_to_upload_a_file(): void
    {
        Livewire::test(FilesRelationManager::class, ['ownerRecord' => $this->workgroup, 'pageClass' => ViewWorkgroup::class])
            ->assertTableActionVisible('create')
            ->callTableAction('create', data: ['filepath' => UploadedFile::fake()->createWithContent('company-drills.pdf', "%PDF-1.7\nCompany drills")])
            ->assertHasNoTableActionErrors();

        $file = WorkgroupFile::query()->sole();
        self::assertSame('company-drills.pdf', $file->filename);
        self::assertSame($this->workgroup->id, $file->workgroup_id);
        self::assertGreaterThan(0, $file->file_size);
        Storage::disk('local')->assertExists($file->filepath);
    }

    public function test_member_shared_upload_uses_temporary_file_and_preserves_name(): void
    {
        $member = User::factory()->create();
        WorkgroupMember::create(['workgroup_id' => $this->workgroup->id, 'user_id' => $member->id, 'role' => 'member', 'is_active' => true]);
        WorkgroupSession::create(['workgroup_id' => $this->workgroup->id, 'name' => 'Module 1', 'start_date' => now()->toDateString(), 'end_date' => now()->addDay()->toDateString(), 'status' => 'active']);
        $this->actingAs($member);
        Filament::setCurrentPanel(Filament::getPanel('workgroups'));

        Livewire::test(SharedUploads::class)
            ->callAction('uploadFile', data: ['file' => UploadedFile::fake()->createWithContent('driver-engineer.pdf', "%PDF-1.7\nDriver engineer")])
            ->assertHasNoActionErrors();

        $upload = WorkgroupSharedUpload::query()->sole();
        self::assertSame('driver-engineer.pdf', $upload->filename);
        self::assertSame($member->id, $upload->user_id);
        Storage::disk('local')->assertExists($upload->filepath);
        self::assertSame([], Storage::disk('r2')->allFiles());
        self::assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_ordinary_member_cannot_manage_assigned_workgroup_files(): void
    {
        $member = User::factory()->create();
        WorkgroupMember::create(['workgroup_id' => $this->workgroup->id, 'user_id' => $member->id, 'role' => 'member', 'is_active' => true]);
        $this->actingAs($member);

        Livewire::test(FilesRelationManager::class, ['ownerRecord' => $this->workgroup, 'pageClass' => ViewWorkgroup::class])
            ->assertTableActionHidden('create');
        self::assertFalse(FilesRelationManager::canViewForRecord($this->workgroup, ViewWorkgroup::class));
        self::assertSame(0, WorkgroupFile::query()->count());
    }

    public function test_member_can_choose_a_session_when_no_session_is_active(): void
    {
        $member = User::factory()->create();
        WorkgroupMember::create(['workgroup_id' => $this->workgroup->id, 'user_id' => $member->id, 'role' => 'member', 'is_active' => true]);
        $session = WorkgroupSession::create(['workgroup_id' => $this->workgroup->id, 'name' => 'Module 1', 'start_date' => now()->toDateString(), 'end_date' => now()->addDay()->toDateString(), 'status' => 'draft']);
        $this->actingAs($member);
        Filament::setCurrentPanel(Filament::getPanel('workgroups'));

        Livewire::test(SharedUploads::class)
            ->callAction('uploadFile', data: [
                'workgroup_session_id' => $session->id,
                'file' => UploadedFile::fake()->createWithContent('manual.pdf', "%PDF-1.7\nTraining manual"),
            ])
            ->assertHasNoActionErrors();

        self::assertSame($session->id, WorkgroupSharedUpload::query()->sole()->workgroup_session_id);
    }

    public function test_private_and_legacy_files_remain_authorized_downloads(): void
    {
        foreach (['local', 'r2', 'public'] as $disk) {
            $path = 'workgroup-files/'.$disk.'.pdf';
            Storage::disk($disk)->put($path, "%PDF-1.7\nExisting manual");
            $file = WorkgroupFile::create(['workgroup_id' => $this->workgroup->id, 'filepath' => $path, 'filename' => $disk.'.pdf']);
            $this->get(route('workgroup.file.download', $file))->assertOk()->assertDownload($disk.'.pdf');
            $this->get(route('workgroup.file.preview', $file))->assertOk();
            Livewire::test(EditWorkgroupFile::class, ['record' => $file->id])
                ->call('save')
                ->assertHasNoFormErrors();
            self::assertSame($path, $file->refresh()->filepath);
            Storage::disk($disk)->assertExists($path);
        }
    }
}
