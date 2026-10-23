<?php

declare(strict_types=1);

namespace Tests\Feature\CommandCommunications;

use App\Enums\DepartmentUpdateAudience;
use App\Enums\DepartmentUpdateStatus;
use App\Models\DepartmentUpdate;
use App\Models\User;
use App\Services\Captain5\Captain5AdministrationService;
use App\Services\DepartmentUpdates\CommandDepartmentUpdatePublisher;
use App\Services\Security\FleetOperationsManagerAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\CommandCommunications\Concerns\BuildsSharedCommunicationsWorld;
use Tests\TestCase;

/**
 * "Post to Department Updates" from the 300 and Captain 5 pages: a department-wide home page notice,
 * separate from the private Messages. Rule: anyone who can open the page posts; it publishes
 * immediately without a push broadcast; the author can take it down; admins moderate in the panel.
 */
final class CommandDepartmentUpdatePostingTest extends TestCase
{
    use BuildsSharedCommunicationsWorld;
    use RefreshDatabase;

    private const BASE_300 = '/api/command-communications/department-updates';

    private const BASE_C5 = '/api/captain5/department-updates';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->withoutVite();
        $this->buildCommunicationsWorld();
    }

    public function test_a_300_member_posts_with_files_and_everyone_sees_it_on_the_home_page(): void
    {
        $member = $this->command300Member('Chief Poster');
        $this->actAs($member);
        $response = $this->post(self::BASE_300, [
            'title' => 'Hurricane staffing plan',
            'body' => "Extra crews start Friday.\n\nSee the attached <b>schedule</b>.",
            'category' => 'operations',
            'client_request_id' => (string) Str::uuid(),
            'image' => $this->pngUpload('map.png'),
            'attachment' => $this->pdfUpload('schedule.pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('update.title', 'Hurricane staffing plan')
            ->assertJsonPath('update.status', 'published')
            ->assertJsonPath('update.own', true)
            ->assertJsonPath('update.has_image', true)
            ->assertJsonPath('update.has_attachment', true);

        $update = DepartmentUpdate::query()->findOrFail($response->json('update.id'));
        self::assertSame(CommandDepartmentUpdatePublisher::ORIGIN_COMMAND_300, $update->origin);
        self::assertSame(DepartmentUpdateStatus::Published, $update->status);
        self::assertSame(DepartmentUpdateAudience::Everyone, $update->audience);
        self::assertFalse((bool) $update->send_web_push, 'No push broadcast from the page');
        self::assertStringNotContainsString('<b>', (string) $update->body, 'Page text is escaped');
        self::assertStringContainsString('<p>Extra crews start Friday.</p>', (string) $update->body);
        Storage::disk('local')->assertExists((string) $update->image_path);
        Storage::disk('local')->assertExists((string) $update->attachment_path);
        self::assertTrue(DepartmentUpdate::query()->forHomepage()->whereKey($update->id)->exists());

        $this->actAs($this->outsider('Any Firefighter'));
        $this->get('/updates/'.$update->id)->assertOk()->assertSee('Hurricane staffing plan');
        Notification::assertNothingSent();
    }

    public function test_captain5_page_users_post_and_the_feeds_stay_separate_by_page(): void
    {
        $this->actAs($this->captain5Viewer('Lieutenant Poster'));
        $this->postJson(self::BASE_C5, ['title' => 'Narcan restock', 'body' => 'New Narcan is at Logistics.'])->assertCreated();
        $this->actAs($this->command300Member());
        $this->postJson(self::BASE_300, ['title' => 'Command notice', 'body' => 'Briefing at 0800.'])->assertCreated();

        self::assertSame([CommandDepartmentUpdatePublisher::ORIGIN_CAPTAIN_5], DepartmentUpdate::query()->where('title', 'Narcan restock')->pluck('origin')->all());
        $this->actAs($this->captain5Viewer('Another Lieutenant'));
        self::assertSame(['Narcan restock'], collect($this->getJson(self::BASE_C5)->assertOk()->json('updates'))->pluck('title')->all());
        self::assertFalse((bool) $this->getJson(self::BASE_C5)->json('updates.0.own'));
    }

    public function test_only_page_users_post_and_validation_is_enforced(): void
    {
        $this->actAs($this->outsider());
        $this->postJson(self::BASE_300, ['title' => 'x', 'body' => 'y'])->assertForbidden();
        $this->postJson(self::BASE_C5, ['title' => 'x', 'body' => 'y'])->assertForbidden();
        $this->actAs($this->command300Member());

        $this->actAs($this->captain5Viewer());
        $this->postJson(self::BASE_C5, ['title' => '   ', 'body' => 'Body'])->assertStatus(422);
        $this->postJson(self::BASE_C5, ['title' => 'T', 'body' => 'B', 'category' => 'emergency'])->assertStatus(422);
        $this->postJson(self::BASE_C5, ['title' => 'T', 'body' => 'B', 'expires_at' => now()->subDay()->toDateTimeString()])->assertStatus(422);
        $this->post(self::BASE_C5, ['title' => 'T', 'body' => 'B', 'image' => UploadedFile::fake()->createWithContent('notes.txt', 'plain')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('image');
        self::assertSame(0, DepartmentUpdate::query()->count());
    }

    public function test_a_retried_post_is_stored_once_and_another_member_cannot_reuse_the_key(): void
    {
        $poster = $this->captain5Viewer('Retry Poster');
        $key = (string) Str::uuid();
        $this->actAs($poster);
        $first = $this->postJson(self::BASE_C5, ['title' => 'Once', 'body' => 'Only once.', 'client_request_id' => $key])->assertCreated()->json('update.id');
        $second = $this->postJson(self::BASE_C5, ['title' => 'Once', 'body' => 'Only once.', 'client_request_id' => $key])->assertCreated()->json('update.id');
        self::assertSame($first, $second);
        self::assertSame(1, DepartmentUpdate::query()->count());

        $this->actAs($this->captain5Viewer('Someone Else'));
        $this->postJson(self::BASE_C5, ['title' => 'Mine', 'body' => 'Mine.', 'client_request_id' => $key])->assertForbidden();
    }

    public function test_only_the_author_takes_a_post_down_and_it_stays_as_history(): void
    {
        $author = $this->captain5Viewer('Author');
        $this->actAs($author);
        $id = $this->postJson(self::BASE_C5, ['title' => 'Temporary', 'body' => 'For today.'])->assertCreated()->json('update.id');

        $this->actAs($this->captain5Viewer('Not The Author'));
        $this->postJson(self::BASE_C5.'/'.$id.'/take-down')->assertForbidden();
        $this->actAs($this->command300Member());
        $this->postJson(self::BASE_300.'/'.$id.'/take-down')->assertNotFound(); // posted from the other page

        $this->actAs($author);
        $this->postJson(self::BASE_C5.'/'.$id.'/take-down')->assertOk()->assertJsonPath('update.status', 'taken_down');
        $update = DepartmentUpdate::query()->findOrFail($id);
        self::assertSame(DepartmentUpdateStatus::Archived, $update->status);
        self::assertFalse(DepartmentUpdate::query()->forHomepage()->whereKey($id)->exists());
        self::assertTrue(DepartmentUpdate::query()->publishedArchive()->whereKey($id)->exists());
    }

    public function test_the_posting_rule_covers_the_named_groups_even_without_page_access(): void
    {
        $admin = $this->captain5Administrator();
        $deny = function (User $user) use ($admin): User {
            app(Captain5AdministrationService::class)->setOverride($admin, (int) $user->employee_profile_id, 'deny', 'Not on the Captain 5 page');
            $this->forgetCaptain5Memo();

            return $user->fresh();
        };
        $chief = $deny($this->officer('Division Chief', 'Denied Chief'));
        $fleet = $this->officer('Firefighter', 'Fleet Manager');
        $fleet->assignRole(Role::findOrCreate(FleetOperationsManagerAssignment::ROLE, 'web'));
        $fleet->givePermissionTo(array_map(static fn (string $p): Permission => Permission::findOrCreate($p, 'web'), FleetOperationsManagerAssignment::DIRECT_PERMISSIONS));
        $fleet = $deny($fleet);
        $adminRights = $this->officer('Firefighter', 'Admin Rights');
        $adminRights->givePermissionTo(Permission::findOrCreate('admin.access', 'web'));
        $adminRights = $deny($adminRights);

        $publisher = app(CommandDepartmentUpdatePublisher::class);
        foreach ([$chief, $fleet, $adminRights, $this->command300Member(), $this->captain5Viewer(), $this->officer('Fire Chief'), $this->officer('Deputy Fire Chief')] as $allowed) {
            self::assertTrue($publisher->canPost($allowed), $allowed->name.' may post');
        }
        $this->actAs($fleet);
        $this->postJson(self::BASE_C5, ['title' => 'Fleet notice', 'body' => 'Shop closed Monday.'])->assertCreated()
            ->assertJsonPath('update.author', 'Firefighter Fleet Manager');

        $disabled = $this->captain5Viewer('Disabled Lieutenant');
        User::query()->whereKey($disabled->id)->update(['account_status' => 'disabled']);
        $pending = $this->officer('Division Chief', 'Pending Chief');
        User::query()->whereKey($pending->id)->update(['must_change_password' => true]);
        $this->forgetCaptain5Memo();
        $denied = [$this->outsider(), $this->officer('Driver Engineer'), $this->officer('Chief Plans Analyst #3'), $disabled->fresh(), $pending->fresh(), $deny($this->captain5Viewer('Denied Lieutenant'))];
        foreach ($denied as $member) {
            self::assertFalse($publisher->canPost($member), $member->name.' may not post');
        }
        self::assertFalse($publisher->canPost(null));
        $this->actAs($this->officer('Driver Engineer'));
        $this->postJson(self::BASE_300, ['title' => 'x', 'body' => 'y'])->assertForbidden();
        $this->getJson(self::BASE_C5)->assertForbidden();
    }

    public function test_every_post_stores_and_shows_the_posters_rank_and_name_and_it_cannot_be_changed(): void
    {
        $chief = $this->officer('Division Chief', 'Jane Smith');
        $this->actAs($chief);
        $id = $this->postJson(self::BASE_C5, [
            'title' => 'Staffing notice', 'body' => 'Read this.', 'author_name' => 'Someone Else', 'author_rank' => 'Fire Chief',
        ])->assertCreated()
            ->assertJsonPath('update.author', 'Division Chief Jane Smith')
            ->assertJsonPath('update.author_rank', 'Division Chief')
            ->assertJsonPath('update.author_name', 'Jane Smith')
            ->json('update.id');

        $update = DepartmentUpdate::query()->findOrFail($id);
        self::assertSame(['Jane Smith', 'Division Chief'], [$update->author_name, $update->author_rank], 'Typed-in attribution is ignored');

        // Stored at posting time: a later rank or name change does not rewrite history.
        $chief->employeeProfile()->update(['rank' => 'Fire Chief', 'name' => 'Jane Smith-Jones']);
        $this->actAs($this->outsider('Any Firefighter'));
        $this->get('/')->assertOk()->assertSee('Posted by Division Chief Jane Smith');
        $this->get('/updates/'.$id)->assertOk()->assertSee('Posted by Division Chief Jane Smith');

        try {
            $update->forceFill(['author_name' => 'Edited'])->save();
            self::fail('Attribution must not be editable');
        } catch (\LogicException) {
        }
        $update->refresh()->forceFill(['title' => 'Staffing notice (edited)'])->save();
        self::assertSame('Jane Smith', $update->fresh()->author_name, 'Other fields stay editable for administrators');

        // Admin Panel posts are attributed the same way.
        $admin = $this->captain5Administrator();
        $panel = DepartmentUpdate::query()->create([
            'title' => 'Panel post', 'body' => '<p>Hi</p>', 'category' => 'general', 'priority' => 'normal', 'status' => 'draft',
            'audience' => 'everyone', 'author_id' => $admin->id,
        ]);
        self::assertSame($admin->fresh()->employeeProfile->name ?? $admin->name, $panel->author_name);
    }
}
