<?php

declare(strict_types=1);

namespace Tests\Feature\Training;

use App\Enums\DepartmentUpdateCategory;
use App\Enums\DepartmentUpdateStatus;
use App\Filament\Training\Pages\TrainingCrewPlanner;
use App\Models\CommandCommunicationPost;
use App\Models\DailyRoster\RosterAssignment;
use App\Models\DepartmentUpdate;
use App\Models\Employee;
use App\Models\Training\TrainingAttendance;
use App\Models\Training\TrainingCourse;
use App\Models\Training\TrainingCrewPlanPublication;
use App\Models\Training\TrainingSession;
use App\Models\User;
use App\Services\CommandCommunications\CommandCommunicationService;
use App\Services\DepartmentUpdates\CommandDepartmentUpdatePublisher;
use App\Services\Training\CrewPlanner\TrainingCrewPlannerDraftStore;
use App\Services\Training\CrewPlanner\TrainingCrewPlanPublisher;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\Support\DailyRosterSnapshotFactory;
use Tests\TestCase;

/** Sending a plan to 300, Captain 5 and Department Updates. Every identity here is synthetic. */
final class TrainingCrewPlanPublishingTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-10-21';

    private ?TrainingCourse $course = null;

    /** @var array<string, Employee> */
    private array $employees = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['captain5.shift_rotation' => ['enabled' => false]]);
        Storage::fake('local');
        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::DATE.' 10:00:00', \App\Support\HubTimezone::NAME));
        \Illuminate\Support\Carbon::setTestNow(CarbonImmutable::parse(self::DATE.' 10:00:00', \App\Support\HubTimezone::NAME));
        Filament::setCurrentPanel(Filament::getPanel('training'));
        foreach (['90001' => 'Captain', '90002' => 'Firefighter', '90003' => 'Firefighter'] as $number => $rank) {
            $this->employees[$number] = Employee::query()->create(['employee_id' => $number, 'name' => 'Synthetic '.$number,
                'rank' => $rank, 'roster_status' => 'active', 'password' => 'not-used']);
        }
        DailyRosterSnapshotFactory::publish(self::DATE, ['Station 9 / Engine 9' => [
            ['employee_id' => '90001', 'name' => 'Synthetic 90001', 'code' => 'SP', 'availability' => RosterAssignment::AVAILABILITY_WORKING],
            ['employee_id' => '90002', 'name' => 'Synthetic 90002', 'code' => 'SP', 'availability' => RosterAssignment::AVAILABILITY_WORKING],
            ['employee_id' => '90003', 'name' => 'Synthetic 90003', 'code' => 'SP', 'availability' => RosterAssignment::AVAILABILITY_WORKING],
        ]], ['shift' => 'B']);
        $this->bindCanonicalSessionToLivewireTestRequests();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
    }

    private function trainer(): User
    {
        $user = User::factory()->create(['account_status' => 'active', 'must_change_password' => false]);
        $user->assignRole(Role::findOrCreate('training_admin', 'web'));

        return $user;
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['account_status' => 'active', 'must_change_password' => false]);
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));
        $user->assignRole(Role::findOrCreate('training_admin', 'web'));

        return $user;
    }

    private function plan(User $actor): mixed
    {
        $this->actingAs($actor);
        $this->course ??= TrainingCourse::query()->create(['name' => 'Synthetic pump course', 'classifications' => ['STC'], 'track_attendance_matrix' => false, 'created_by' => $actor->id]);
        $component = Livewire::test(TrainingCrewPlanner::class)->set('courseId', $this->course->id);
        foreach (['90001', '90002'] as $number) {
            $component->call('toggleMember', $this->employees[$number]->id);
        }

        return $component->assertHasNoErrors();
    }

    private function send(mixed $component, array $targets): mixed
    {
        return $component->set('publishTargets', $targets)->call('publish');
    }

    public function test_a_training_admin_has_no_implicit_right_to_any_destination(): void
    {
        $trainer = $this->trainer();
        $publisher = app(TrainingCrewPlanPublisher::class);
        foreach (TrainingCrewPlanPublisher::DESTINATIONS as $destination) {
            self::assertFalse($publisher->allows($trainer, $destination), $destination);
        }
        $this->send($this->plan($trainer), TrainingCrewPlanPublisher::DESTINATIONS)->assertHasNoErrors();
        self::assertSame(0, CommandCommunicationPost::query()->count());
        self::assertSame(0, DepartmentUpdate::query()->count());
        self::assertSame(['not_permitted'], TrainingCrewPlanPublication::query()->pluck('error_code')->unique()->values()->all());
    }

    public function test_a_viewer_cannot_open_or_send(): void
    {
        $viewer = User::factory()->create(['account_status' => 'active', 'must_change_password' => false]);
        $viewer->assignRole(Role::findOrCreate('training_viewer', 'web'));
        $this->actingAs($viewer);
        Livewire::test(TrainingCrewPlanner::class)->assertForbidden();
    }

    public function test_both_command_channels_share_one_post_with_a_private_attachment(): void
    {
        $admin = $this->superAdmin();
        $this->send($this->plan($admin), [TrainingCrewPlanPublisher::COMMAND_300, TrainingCrewPlanPublisher::CAPTAIN_5])->assertHasNoErrors();

        $post = CommandCommunicationPost::query()->sole();
        self::assertSame(CommandCommunicationPost::AUDIENCE_BOTH, $post->audience);
        self::assertSame(CommandCommunicationPost::CONTEXT_ADMINISTRATION, $post->author_context);
        self::assertStringContainsString('Proposed class roster. This is not verified attendance.', $post->body);
        self::assertStringContainsString('Synthetic pump course', $post->subject);
        self::assertStringContainsString('Synthetic 90001', $post->body);
        self::assertCount(1, $post->attachments);
        self::assertStringEndsWith('.pdf', $post->attachments->first()->original_filename);
        $rows = TrainingCrewPlanPublication::query()->get();
        self::assertSame(['sent'], $rows->pluck('status')->unique()->values()->all());
        self::assertSame([$post->getKey()], $rows->pluck('command_post_id')->unique()->values()->all());
    }

    public function test_the_card_appears_in_the_feeds_it_was_sent_to_and_no_others(): void
    {
        $admin = $this->superAdmin();
        $this->send($this->plan($admin), [TrainingCrewPlanPublisher::CAPTAIN_5])->assertHasNoErrors();
        $post = CommandCommunicationPost::query()->sole();
        self::assertSame(CommandCommunicationPost::AUDIENCE_CAPTAIN_5, $post->audience);
        $feeds = app(CommandCommunicationService::class);
        self::assertTrue($feeds->feed($admin, null, null, CommandCommunicationPost::CHANNEL_CAPTAIN_5)['posts']->contains('id', $post->id));
        self::assertFalse($feeds->feed($admin, null, null, CommandCommunicationPost::CHANNEL_COMMAND_300)['posts']->contains('id', $post->id));
    }

    public function test_a_300_member_without_admin_rights_posts_as_a_command_member_and_cannot_reach_captain_5(): void
    {
        $member = $this->trainer();
        $member->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('command.300.access', 'web'));
        $this->send($this->plan($member), [TrainingCrewPlanPublisher::COMMAND_300, TrainingCrewPlanPublisher::CAPTAIN_5])->assertHasNoErrors();
        $post = CommandCommunicationPost::query()->sole();
        self::assertSame(CommandCommunicationPost::AUDIENCE_COMMAND_300, $post->audience);
        self::assertSame(CommandCommunicationPost::CONTEXT_COMMAND, $post->author_context);
        self::assertSame('failed', TrainingCrewPlanPublication::query()->where('destination', TrainingCrewPlanPublisher::CAPTAIN_5)->sole()->status);
    }

    public function test_a_department_update_is_training_attributed_public_safe_and_quiet(): void
    {
        $admin = $this->superAdmin();
        $this->send($this->plan($admin), [TrainingCrewPlanPublisher::DEPARTMENT])->assertHasNoErrors();
        $update = DepartmentUpdate::query()->sole();
        self::assertSame(CommandDepartmentUpdatePublisher::ORIGIN_TRAINING, $update->origin);
        self::assertSame(DepartmentUpdateCategory::Training, $update->category);
        self::assertSame(DepartmentUpdateStatus::Published, $update->status);
        self::assertFalse((bool) $update->send_web_push);
        self::assertFalse((bool) $update->send_in_app);
        self::assertNull($update->attachment_path, 'The printable roster has employee IDs and never goes department-wide.');
        self::assertStringContainsString('Proposed class roster', (string) $update->body);
        self::assertStringContainsString('Synthetic 90001', (string) $update->body);
        self::assertStringNotContainsString('90001 ', str_replace('Synthetic 90001', '', (string) $update->body));
        self::assertNotNull($update->expires_at);
    }

    public function test_sending_twice_and_double_clicks_never_repeat_a_destination(): void
    {
        $admin = $this->superAdmin();
        $component = $this->plan($admin);
        $this->send($component, [TrainingCrewPlanPublisher::COMMAND_300, TrainingCrewPlanPublisher::DEPARTMENT])->assertHasNoErrors();
        $this->send($component, [TrainingCrewPlanPublisher::COMMAND_300, TrainingCrewPlanPublisher::DEPARTMENT])->assertHasNoErrors();
        self::assertSame(1, CommandCommunicationPost::query()->count());
        self::assertSame(1, DepartmentUpdate::query()->count());
        self::assertSame(2, TrainingCrewPlanPublication::query()->count());
    }

    public function test_a_changed_plan_is_a_new_version_marked_as_updated(): void
    {
        $admin = $this->superAdmin();
        $component = $this->plan($admin);
        $this->send($component, [TrainingCrewPlanPublisher::COMMAND_300])->assertHasNoErrors();
        $component->call('toggleMember', $this->employees['90003']->id);
        $this->send($component, [TrainingCrewPlanPublisher::COMMAND_300])->assertHasNoErrors();
        $posts = CommandCommunicationPost::query()->orderBy('id')->get();
        self::assertCount(2, $posts);
        self::assertStringStartsWith('Updated training roster', $posts[1]->subject);
    }

    public function test_one_failed_destination_leaves_the_others_and_retry_sends_only_it(): void
    {
        $admin = $this->superAdmin();
        $component = $this->plan($admin);
        $outage = true;
        DepartmentUpdate::creating(static function () use (&$outage): void {
            if ($outage) {
                throw new RuntimeException('Synthetic 90001 store outage');
            }
        });

        $this->send($component, [TrainingCrewPlanPublisher::COMMAND_300, TrainingCrewPlanPublisher::DEPARTMENT])->assertHasNoErrors();
        self::assertSame(1, CommandCommunicationPost::query()->count());
        self::assertSame(0, DepartmentUpdate::query()->count());
        self::assertSame('failed', TrainingCrewPlanPublication::query()->where('destination', TrainingCrewPlanPublisher::DEPARTMENT)->sole()->status);
        self::assertSame('send_failed', TrainingCrewPlanPublication::query()->where('destination', TrainingCrewPlanPublisher::DEPARTMENT)->sole()->error_code);
        $component->assertSee('Retry what failed')->assertSee('Failed');

        $outage = false;
        $component->call('retryFailed')->assertHasNoErrors();
        self::assertSame(1, CommandCommunicationPost::query()->count(), 'What already went out is not repeated.');
        self::assertSame(1, DepartmentUpdate::query()->count());
        self::assertSame(['sent'], TrainingCrewPlanPublication::query()->pluck('status')->unique()->values()->all());
    }

    public function test_the_record_of_where_it_went_survives_a_refresh(): void
    {
        $admin = $this->superAdmin();
        $component = $this->plan($admin);
        $this->send($component, [TrainingCrewPlanPublisher::DEPARTMENT])->assertHasNoErrors();
        Livewire::test(TrainingCrewPlanner::class)->assertSet('draftId', $component->get('draftId'))
            ->assertSeeHtml('data-crew-plan-status')->assertSee('Department Updates')->assertSee('Sent');
        $row = TrainingCrewPlanPublication::query()->sole();
        $this->expectException(\LogicException::class);
        $row->delete();
    }

    public function test_a_stale_tab_cannot_send(): void
    {
        $admin = $this->superAdmin();
        $component = $this->plan($admin);
        app(TrainingCrewPlannerDraftStore::class)->update($admin, $component->get('draftId'), $component->get('version'), static fn (array $draft): array => $draft);
        $this->send($component, [TrainingCrewPlanPublisher::COMMAND_300])->assertHasErrors('proposal');
        self::assertSame(0, CommandCommunicationPost::query()->count());
    }

    public function test_it_rechecks_the_roster_at_send_time_and_waits_for_confirmation(): void
    {
        $admin = $this->superAdmin();
        $component = $this->plan($admin);
        DailyRosterSnapshotFactory::publish(self::DATE, ['Station 9 / Engine 9' => [
            ['employee_id' => '90001', 'name' => 'Synthetic 90001', 'code' => 'SIC', 'availability' => RosterAssignment::AVAILABILITY_NOT_WORKING],
            ['employee_id' => '90002', 'name' => 'Synthetic 90002', 'code' => 'SP', 'availability' => RosterAssignment::AVAILABILITY_WORKING],
        ]], ['shift' => 'B']);

        $this->send($component, [TrainingCrewPlanPublisher::COMMAND_300])->assertHasErrors('plan');
        self::assertSame(0, CommandCommunicationPost::query()->count());

        $component->call('acknowledgeRoster');
        $this->send($component, [TrainingCrewPlanPublisher::COMMAND_300])->assertHasErrors('confirmReview');
        self::assertSame(0, CommandCommunicationPost::query()->count());

        $component->set('confirmReview', true);
        $this->send($component, [TrainingCrewPlanPublisher::COMMAND_300])->assertHasNoErrors();
        $body = CommandCommunicationPost::query()->sole()->body;
        self::assertStringContainsString('To be confirmed (1)', $body);
        self::assertStringNotContainsString('SIC', $body, 'A leave code never goes out.');
    }

    public function test_retry_from_a_stale_tab_or_after_an_edit_is_refused_and_sends_nothing(): void
    {
        $admin = $this->superAdmin();
        $component = $this->plan($admin);
        $outage = true;
        DepartmentUpdate::creating(static function () use (&$outage): void {
            if ($outage) {
                throw new RuntimeException('Synthetic outage');
            }
        });
        $this->send($component, [TrainingCrewPlanPublisher::DEPARTMENT])->assertHasNoErrors();
        $outage = false;

        // Another tab edited the plan: this tab is stale.
        $store = app(TrainingCrewPlannerDraftStore::class);
        $id = $this->employees['90003']->id;
        $store->update($admin, $component->get('draftId'), $component->get('version'), static function (array $draft) use ($id): array {
            $draft['selections'][$id] = ['slot_id' => null];

            return $draft;
        });
        $component->call('retryFailed')->assertHasErrors('proposal');
        self::assertSame(0, DepartmentUpdate::query()->count());

        // Reloaded, the plan is no longer what failed, so a retry would send something else.
        $component->call('reloadProposal')->call('retryFailed')->assertHasErrors('publishTargets');
        self::assertSame(0, DepartmentUpdate::query()->count());
    }

    public function test_a_send_that_died_partway_can_be_retried_without_duplicating(): void
    {
        $admin = $this->superAdmin();
        $component = $this->plan($admin);
        $this->send($component, [TrainingCrewPlanPublisher::COMMAND_300])->assertHasNoErrors();
        $row = TrainingCrewPlanPublication::query()->sole();
        // The post went out but the process died before recording it.
        $row->forceFill(['status' => TrainingCrewPlanPublication::STATUS_SENDING, 'sent_at' => null])->save();
        self::assertFalse($row->refresh()->needsRetry(), 'A send in progress is not offered for retry.');
        $component->call('reloadProposal')->assertDontSee('Retry what failed');

        $this->travel(6)->minutes();
        \Illuminate\Support\Carbon::setTestNow(now());
        self::assertTrue($row->refresh()->needsRetry());
        $component->call('reloadProposal')->assertSee('Retry what failed')->call('retryFailed')->assertHasNoErrors();
        self::assertSame(1, CommandCommunicationPost::query()->count(), 'The earlier post is found by its key, not repeated.');
        self::assertSame('sent', $row->refresh()->status);
    }

    public function test_a_department_update_counts_unconfirmed_members_and_does_not_name_them(): void
    {
        $admin = $this->superAdmin();
        $component = $this->plan($admin);
        DailyRosterSnapshotFactory::publish(self::DATE, ['Station 9 / Engine 9' => [
            ['employee_id' => '90001', 'name' => 'Synthetic 90001', 'code' => 'SIC', 'availability' => RosterAssignment::AVAILABILITY_NOT_WORKING],
            ['employee_id' => '90002', 'name' => 'Synthetic 90002', 'code' => 'SP', 'availability' => RosterAssignment::AVAILABILITY_WORKING],
        ]], ['shift' => 'B']);
        $component->call('acknowledgeRoster')->set('confirmReview', true);
        $this->send($component, [TrainingCrewPlanPublisher::COMMAND_300, TrainingCrewPlanPublisher::DEPARTMENT])->assertHasNoErrors();

        $public = (string) DepartmentUpdate::query()->sole()->body;
        self::assertStringNotContainsString('Synthetic 90001', $public);
        self::assertStringContainsString('Synthetic 90002', $public);
        self::assertStringContainsString('Training is confirming 1 more.', $public);
        self::assertStringContainsString('Members listed: 1', $public);
        self::assertStringContainsString('Synthetic 90001', CommandCommunicationPost::query()->sole()->body, 'Command staff see who needs confirming.');
    }

    public function test_every_department_notice_expires_even_for_a_past_or_far_off_date(): void
    {
        $admin = $this->superAdmin();
        DailyRosterSnapshotFactory::publish('2026-10-10', ['Station 9 / Engine 9' => [
            ['employee_id' => '90001', 'name' => 'Synthetic 90001', 'code' => 'SP', 'availability' => RosterAssignment::AVAILABILITY_WORKING],
        ]], ['shift' => 'B']);
        $this->actingAs($admin);
        $component = Livewire::test(TrainingCrewPlanner::class)->set('classTitle', 'Synthetic past class')->set('scheduleDate', '2026-10-10');
        $component->call('toggleMember', $this->employees['90001']->id);
        $this->send($component, [TrainingCrewPlanPublisher::DEPARTMENT])->assertHasNoErrors();
        self::assertTrue(DepartmentUpdate::query()->sole()->expires_at->between(now(), now()->addDays(2)));

        $component->set('scheduleDate', '2027-09-01')->set('confirmReview', true);
        $this->send($component, [TrainingCrewPlanPublisher::DEPARTMENT])->assertHasNoErrors();
        self::assertTrue(DepartmentUpdate::query()->latest('id')->first()->expires_at->lt(now()->addDays(120)));
    }

    public function test_publishing_creates_no_training_session_or_attendance(): void
    {
        $admin = $this->superAdmin();
        $this->send($this->plan($admin), TrainingCrewPlanPublisher::DESTINATIONS)->assertHasNoErrors();
        self::assertSame(0, TrainingSession::query()->count());
        self::assertSame(0, TrainingAttendance::query()->count());
    }

    public function test_a_simple_list_needs_no_session_time_or_location_and_exports_read_as_a_proposal(): void
    {
        $admin = $this->superAdmin();
        $component = $this->plan($admin);
        $component->assertSee('Class list')->assertSee('Download PDF');
        $this->send($component, [TrainingCrewPlanPublisher::COMMAND_300])->assertHasNoErrors();
        $body = CommandCommunicationPost::query()->sole()->body;
        self::assertStringContainsString('Class list (2)', $body);
        self::assertStringNotContainsString('Session', $body);
    }

    public function test_sessions_carry_their_own_times_and_locations_into_the_message(): void
    {
        $admin = $this->superAdmin();
        $component = $this->plan($admin);
        $component->set('slotEdits.0.label', 'Morning block')->set('slotEdits.0.start', '09:00')->set('slotEdits.0.end', '11:00')
            ->set('slotEdits.0.location_choice', 'other')->set('slotEdits.0.venue', 'Synthetic Training Tower')->assertHasNoErrors();
        $component->call('addSlot')->set('slotEdits.1.label', 'Second block')->set('slotEdits.1.start', '13:00')->set('slotEdits.1.end', '15:00')
            ->set('slotEdits.1.location_choice', 'other')->set('slotEdits.1.venue', 'Synthetic Bay 2')->assertHasNoErrors();
        $draft = app(TrainingCrewPlannerDraftStore::class)->get($admin, $component->get('draftId'));
        $component->call('setMemberSlot', $this->employees['90001']->id, $draft['slots'][0]['id'])->assertHasNoErrors();
        $component->call('setMemberSlot', $this->employees['90002']->id, $draft['slots'][1]['id'])->assertHasNoErrors();
        $this->send($component, [TrainingCrewPlanPublisher::COMMAND_300])->assertHasNoErrors();
        $body = CommandCommunicationPost::query()->sole()->body;
        self::assertStringContainsString('Morning block · 09:00–11:00 · Synthetic Training Tower (1)', $body);
        self::assertStringContainsString('Second block · 13:00–15:00 · Synthetic Bay 2 (1)', $body);
    }

    public function test_an_ad_hoc_title_needs_no_course_and_creates_none(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);
        $component = Livewire::test(TrainingCrewPlanner::class)->set('classTitle', 'Synthetic ladder drill')->assertHasNoErrors();
        $component->assertSee('3 eligible')->call('toggleMember', $this->employees['90001']->id)->assertHasNoErrors();
        $this->send($component, [TrainingCrewPlanPublisher::COMMAND_300])->assertHasNoErrors();
        self::assertSame(0, TrainingCourse::query()->count());
        self::assertStringContainsString('Synthetic ladder drill', CommandCommunicationPost::query()->sole()->body);
    }
}
