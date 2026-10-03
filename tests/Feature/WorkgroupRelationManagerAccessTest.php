<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\Workgroup\Pages\ViewWorkgroup;
use App\Filament\Resources\Workgroup\RelationManagers\AttendanceRelationManager;
use App\Filament\Resources\Workgroup\RelationManagers\FilesRelationManager;
use App\Filament\Resources\Workgroup\RelationManagers\MembersRelationManager;
use App\Filament\Resources\Workgroup\RelationManagers\SessionsRelationManager;
use App\Filament\Resources\Workgroup\RelationManagers\SharedUploadsRelationManager;
use App\Models\User;
use App\Models\Workgroup;
use App\Models\WorkgroupMember;
use App\Models\WorkgroupSession;
use App\Models\WorkgroupSharedUpload;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class WorkgroupRelationManagerAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_relation_managers_are_visible_only_to_a_manager_of_the_owner_workgroup(): void
    {
        $member = User::factory()->create();
        [$workgroup, $session] = $this->makeContext($member, 'member');

        $this->actingAs($member);

        $this->assertFalse(MembersRelationManager::canViewForRecord($workgroup, ''));
        $this->assertFalse(SessionsRelationManager::canViewForRecord($workgroup, ''));
        $this->assertFalse(FilesRelationManager::canViewForRecord($workgroup, ''));
        $this->assertFalse(SharedUploadsRelationManager::canViewForRecord($workgroup, ''));
        $this->assertFalse(AttendanceRelationManager::canViewForRecord($session, ''));

        $facilitator = User::factory()->create();
        [$managedWorkgroup, $managedSession] = $this->makeContext($facilitator, 'facilitator', 'facilitator');

        $this->actingAs($facilitator);

        $this->assertTrue(MembersRelationManager::canViewForRecord($managedWorkgroup, ''));
        $this->assertTrue(SessionsRelationManager::canViewForRecord($managedWorkgroup, ''));
        $this->assertTrue(FilesRelationManager::canViewForRecord($managedWorkgroup, ''));
        $this->assertTrue(SharedUploadsRelationManager::canViewForRecord($managedWorkgroup, ''));
        $this->assertTrue(AttendanceRelationManager::canViewForRecord($managedSession, ''));
    }

    public function test_admin_workgroup_shared_upload_discovery_is_read_only_and_scoped_to_the_owner(): void
    {
        Http::fake();
        Storage::fake('local');
        $manager = User::factory()->create();
        [$workgroup, $session] = $this->makeContext($manager, 'QA', 'facilitator');
        [$otherWorkgroup, $otherSession] = $this->makeContext(User::factory()->create(), 'Other');
        $upload = WorkgroupSharedUpload::create([
            'workgroup_id' => $workgroup->id, 'workgroup_session_id' => $session->id, 'user_id' => $manager->id,
            'workgroup_member_id' => $workgroup->members()->sole()->id, 'filename' => 'qa.png', 'filepath' => 'qa.png', 'file_type' => 'image/png', 'file_size' => 1,
        ]);
        $otherUpload = WorkgroupSharedUpload::create([
            'workgroup_id' => $otherWorkgroup->id, 'workgroup_session_id' => $otherSession->id, 'user_id' => $otherWorkgroup->created_by,
            'workgroup_member_id' => $otherWorkgroup->members()->sole()->id, 'filename' => 'other.png', 'filepath' => 'other.png', 'file_type' => 'image/png', 'file_size' => 1,
        ]);
        $this->actingAs($manager);
        $this->withoutVite();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(SharedUploadsRelationManager::class, ['ownerRecord' => $workgroup, 'pageClass' => ViewWorkgroup::class, 'lazy' => false])
            ->call('loadTable')->assertCanSeeTableRecords([$upload])->assertCanNotSeeTableRecords([$otherUpload])
            ->assertTableActionDoesNotExist('delete')->assertTableActionDoesNotExist('restore');
        $upload->delete();
        Livewire::test(SharedUploadsRelationManager::class, ['ownerRecord' => $workgroup, 'pageClass' => ViewWorkgroup::class, 'lazy' => false])
            ->call('loadTable')->filterTable('trashed', '0')->assertCanSeeTableRecords([$upload])->assertCanNotSeeTableRecords([$otherUpload])
            ->assertTableActionHidden('download', $upload);
        Http::assertNothingSent();
    }

    /** @return array{Workgroup, WorkgroupSession} */
    private function makeContext(User $user, string $suffix, string $role = 'member'): array
    {
        $workgroup = Workgroup::create([
            'name' => 'Workgroup '.$suffix,
            'created_by' => $user->id,
        ]);
        WorkgroupMember::create([
            'workgroup_id' => $workgroup->id,
            'user_id' => $user->id,
            'role' => $role,
            'is_active' => true,
        ]);
        $session = WorkgroupSession::create([
            'workgroup_id' => $workgroup->id,
            'name' => 'Session '.$suffix,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'status' => 'active',
        ]);

        return [$workgroup, $session];
    }
}
