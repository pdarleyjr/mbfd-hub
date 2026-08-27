<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Workgroup\Pages\Notes;
use App\Filament\Workgroup\Pages\SharedUploads;
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
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class WorkgroupNotesAndSharedUploadsAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Http::fake();
    }

    public function test_notes_reject_a_cross_workgroup_livewire_session_state(): void
    {
        $userA = User::factory()->create();
        $this->makeContext($userA, 'A');
        $contextB = $this->makeContext(User::factory()->create(), 'B');

        $this->actingAs($userA);
        Filament::setCurrentPanel(Filament::getPanel('workgroups'));

        Livewire::test(Notes::class)
            ->set('selectedSession', (string) $contextB['session']->id)
            ->assertStatus(404);

        Http::assertNothingSent();
    }

    public function test_notes_reject_a_cross_workgroup_direct_recipient_before_creating_a_note(): void
    {
        $userA = User::factory()->create();
        $this->makeContext($userA, 'A');
        $userB = User::factory()->create();
        $this->makeContext($userB, 'B');

        $this->actingAs($userA);
        Filament::setCurrentPanel(Filament::getPanel('workgroups'));

        Livewire::test(Notes::class)
            ->call('mountAction', 'createNote')
            ->set('mountedActionsData.0.title', 'Cross-workgroup recipient attempt')
            ->set('mountedActionsData.0.content', 'This must not be persisted.')
            ->set('mountedActionsData.0.is_shared', true)
            ->set('mountedActionsData.0.shared_with_user_id', $userB->id)
            ->call('callMountedAction')
            ->assertStatus(404);

        $this->assertDatabaseMissing('workgroup_notes', [
            'title' => 'Cross-workgroup recipient attempt',
        ]);
        Http::assertNothingSent();
    }

    public function test_notes_can_persist_a_private_note_with_the_current_schema(): void
    {
        $user = User::factory()->create();
        $context = $this->makeContext($user, 'A');

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('workgroups'));

        Livewire::test(Notes::class)
            ->call('mountAction', 'createNote')
            ->set('mountedActionsData.0.title', 'Private current-schema note')
            ->set('mountedActionsData.0.content', 'This note remains private.')
            ->set('mountedActionsData.0.is_shared', false)
            ->call('callMountedAction')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('workgroup_notes', [
            'workgroup_member_id' => $context['member']->id,
            'title' => 'Private current-schema note',
            'is_shared' => false,
            'shared_with_user_id' => null,
        ]);
        Http::assertNothingSent();
    }

    public function test_notes_can_share_with_an_active_member_of_the_current_workgroup(): void
    {
        $author = User::factory()->create();
        $context = $this->makeContext($author, 'A');
        $recipient = User::factory()->create();
        WorkgroupMember::create([
            'workgroup_id' => $context['workgroup']->id,
            'user_id' => $recipient->id,
            'role' => 'member',
            'is_active' => true,
        ]);

        $this->actingAs($author);
        Filament::setCurrentPanel(Filament::getPanel('workgroups'));

        Livewire::test(Notes::class)
            ->call('mountAction', 'createNote')
            ->set('mountedActionsData.0.title', 'Shared current-schema note')
            ->set('mountedActionsData.0.content', 'This note has an authorized recipient.')
            ->set('mountedActionsData.0.is_shared', true)
            ->set('mountedActionsData.0.shared_with_user_id', $recipient->id)
            ->call('callMountedAction')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('workgroup_notes', [
            'workgroup_member_id' => $context['member']->id,
            'title' => 'Shared current-schema note',
            'is_shared' => true,
            'shared_with_user_id' => $recipient->id,
        ]);
        Http::assertNothingSent();
    }

    public function test_shared_upload_rejects_a_cross_workgroup_session_before_any_permanent_write(): void
    {
        $userA = User::factory()->create();
        $contextA = $this->makeContext($userA, 'A');
        $contextB = $this->makeContext(User::factory()->create(), 'B');
        $temporaryPath = 'livewire-tmp/cross-workgroup.png';
        $permanentPath = 'workgroup-shared-uploads/'.$contextA['workgroup']->id.'/cross-workgroup.png';

        Storage::disk('local')->put($temporaryPath, 'disposable image payload');

        $this->actingAs($userA);
        Filament::setCurrentPanel(Filament::getPanel('workgroups'));

        Livewire::test(SharedUploads::class)
            ->set('selectedSession', (string) $contextB['session']->id)
            ->assertStatus(404);

        $page = Livewire::test(SharedUploads::class);
        $page->instance()->selectedSession = (string) $contextB['session']->id;

        try {
            $this->invokeProtected($page->instance(), 'uploadFile', [[
                'file' => $temporaryPath,
            ]]);
            $this->fail('A cross-workgroup session must be rejected before a permanent upload write.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        $this->assertDatabaseMissing('workgroup_shared_uploads', [
            'workgroup_id' => $contextA['workgroup']->id,
            'workgroup_session_id' => $contextB['session']->id,
            'filename' => 'cross-workgroup.png',
        ]);
        Storage::disk('local')->assertMissing($permanentPath);
        Http::assertNothingSent();
    }

    public function test_shared_upload_delete_remains_uploader_only_when_a_same_workgroup_record_id_is_forged(): void
    {
        $userA = User::factory()->create();
        $context = $this->makeContext($userA, 'A');
        $otherUser = User::factory()->create();
        $otherMember = WorkgroupMember::create([
            'workgroup_id' => $context['workgroup']->id,
            'user_id' => $otherUser->id,
            'role' => 'member',
            'is_active' => true,
        ]);
        $upload = WorkgroupSharedUpload::create([
            'workgroup_id' => $context['workgroup']->id,
            'workgroup_session_id' => $context['session']->id,
            'user_id' => $otherUser->id,
            'workgroup_member_id' => $otherMember->id,
            'filename' => 'other-user.png',
            'filepath' => 'workgroup-shared-uploads/'.$context['workgroup']->id.'/other-user.png',
            'file_type' => 'image/png',
            'file_size' => 1,
        ]);

        $this->actingAs($userA);
        Filament::setCurrentPanel(Filament::getPanel('workgroups'));

        $page = Livewire::test(SharedUploads::class);

        try {
            $this->invokeProtected($page->instance(), 'ownedUpload', [$upload, $context['member']]);
            $this->fail('An uploader-only delete policy must reject another member’s upload.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        $this->assertDatabaseHas('workgroup_shared_uploads', ['id' => $upload->id]);
        Http::assertNothingSent();
    }

    /** @return array{workgroup: Workgroup, member: WorkgroupMember, session: WorkgroupSession} */
    private function makeContext(User $user, string $suffix): array
    {
        $workgroup = Workgroup::create([
            'name' => 'Workgroup '.$suffix,
            'created_by' => $user->id,
        ]);
        $member = WorkgroupMember::create([
            'workgroup_id' => $workgroup->id,
            'user_id' => $user->id,
            'role' => 'member',
            'is_active' => true,
        ]);
        $session = WorkgroupSession::create([
            'workgroup_id' => $workgroup->id,
            'name' => 'Session '.$suffix,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'status' => 'active',
        ]);

        return compact('workgroup', 'member', 'session');
    }

    /** @param array<int, mixed> $arguments */
    private function invokeProtected(object $target, string $method, array $arguments): mixed
    {
        $reflection = new \ReflectionMethod($target, $method);

        return $reflection->invokeArgs($target, $arguments);
    }
}
