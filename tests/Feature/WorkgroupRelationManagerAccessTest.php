<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\Workgroup\RelationManagers\AttendanceRelationManager;
use App\Filament\Resources\Workgroup\RelationManagers\FilesRelationManager;
use App\Filament\Resources\Workgroup\RelationManagers\MembersRelationManager;
use App\Filament\Resources\Workgroup\RelationManagers\SessionsRelationManager;
use App\Models\User;
use App\Models\Workgroup;
use App\Models\WorkgroupMember;
use App\Models\WorkgroupSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->assertFalse(AttendanceRelationManager::canViewForRecord($session, ''));

        $facilitator = User::factory()->create();
        [$managedWorkgroup, $managedSession] = $this->makeContext($facilitator, 'facilitator', 'facilitator');

        $this->actingAs($facilitator);

        $this->assertTrue(MembersRelationManager::canViewForRecord($managedWorkgroup, ''));
        $this->assertTrue(SessionsRelationManager::canViewForRecord($managedWorkgroup, ''));
        $this->assertTrue(FilesRelationManager::canViewForRecord($managedWorkgroup, ''));
        $this->assertTrue(AttendanceRelationManager::canViewForRecord($managedSession, ''));
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
