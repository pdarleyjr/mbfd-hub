<?php

declare(strict_types=1);

namespace Tests\Feature\DailyRoster;

use App\Filament\Admin\Pages\Captain5Operations;
use App\Models\Captain5\Captain5ActingRequest;
use App\Models\User;
use App\Services\Captain5\ActingApprovers;
use App\Services\Captain5\Captain5DutyRoster;
use App\Services\Captain5\OnDutyCaptain5;
use App\Services\Ems\Captain5Recipients;
use App\Services\Narcotics\NarcoticsCheckAccess;
use App\Services\Security\Captain5Access;
use App\Services\Security\Command300Access;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Captain5\Concerns\BuildsCaptain5World;
use Tests\Support\DailyRosterSnapshotFactory;
use Tests\TestCase;

/**
 * Contract: the TeleStaff roster only ever changes the name on the 300 and Captain 5 cards. The
 * snapshot here names a member who holds no seat, no rank and no grant. With the display flag on,
 * that member appears on the cards and gains nothing, and every authority and routing answer stays
 * exactly what it is with the flag off. Names are synthetic.
 */
final class OnDutyAuthorityBoundaryTest extends TestCase
{
    use BuildsCaptain5World;
    use RefreshDatabase;

    private const DUTY_DATE = '2026-10-20';

    /** Classes that decide access, audit capacity or who gets notified. None may see the roster. */
    private const AUTHORITY_AND_ROUTING_FILES = [
        'app/Services/Captain5/Captain5DutyRoster.php',
        'app/Services/Captain5/OnDutyCaptain5.php',
        'app/Services/Captain5/VerifiedBidSeats.php',
        'app/Services/Captain5/ActingApprovers.php',
        'app/Services/Captain5/Captain5ActingService.php',
        'app/Services/Captain5/Captain5ActorContext.php',
        'app/Services/Captain5/Captain5HandoffService.php',
        'app/Services/Captain5/Captain5IssueActions.php',
        'app/Services/Captain5/Captain5Notifier.php',
        'app/Services/Security/Captain5Access.php',
        'app/Services/Security/Captain5AccessDecision.php',
        'app/Services/Security/Command300Access.php',
        'app/Services/Narcotics/NarcoticsCheckAccess.php',
        'app/Services/Narcotics/NarcoticsNotifier.php',
        'app/Services/Ems/Captain5Recipients.php',
        'app/Services/Ems/EmsNotifier.php',
        'app/Services/CommandCommunications/CommandCommunicationService.php',
    ];

    /** The only places outside the roster services that may ask who the cards show. */
    private const DISPLAY_CONSUMERS = [
        'app/Filament/Admin/Pages/Captain5Operations.php',
        'app/Http/Controllers/Api/Captain5Controller.php',
        'app/Http/Controllers/Api/Command300ShiftController.php',
        'app/Services/Captain5/Captain5Workspace.php',
    ];

    private User $bidCaptain;

    private User $bidChief;

    private User $rosterOnly;

    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->withoutVite();
        $this->buildCaptain5World('2026-10-20 10:30:00'); // A shift duty day
        config()->set('narcotics_checks.enabled', true);
        $this->bidCaptain = $this->officer('Captain', 'Seat Captain');
        $this->seat($this->bidCaptain, 'Captain 5', 'A Shift');
        $this->bidChief = $this->officer('Division Chief', 'Seat Chief');
        $this->seat($this->bidChief, Command300Access::COMMAND_UNIT_LABEL, 'A Shift');
        $this->rosterOnly = $this->officer('Firefighter', 'Roster Only');
        $this->administrator = $this->captain5Administrator();

        DailyRosterSnapshotFactory::publish(self::DUTY_DATE, [
            'Rescue Division / Captain 5' => [['employee_id' => $this->rosterOnly->employee_id, 'name' => 'ONLY, ROSTER', 'rank' => 'Capt', 'code' => '+OT', 'category' => 'Overtime']],
            'Suppression / Division Chief 300' => [['employee_id' => $this->rosterOnly->employee_id, 'name' => 'ONLY, ROSTER', 'rank' => 'DC', 'code' => '+OT', 'category' => 'Overtime']],
        ]);
        $this->displayOn();
    }

    private function displayOn(): void
    {
        config()->set('daily_roster.card_display_enabled', true);
        $this->forgetCaptain5Memo();
    }

    private function displayOff(): void
    {
        config()->set('daily_roster.card_display_enabled', false);
        $this->forgetCaptain5Memo();
    }

    /** @return array<string, mixed> Every authority and routing answer for one member, as plain data */
    private function authorityOf(User $user): array
    {
        $user = $user->fresh();
        $decision = app(Captain5Access::class)->decide($user);

        return [
            'view' => $decision->canViewData,
            'act' => $decision->canAct,
            'dismiss' => $decision->canDismiss,
            'configure' => $decision->canConfigure,
            'sources' => $decision->sources,
            'is_shown_captain5' => $decision->isShownCaptain5,
            'authority' => $decision->authority(),
            'authority_label' => $decision->authorityLabel(),
            'acts_as_logistics' => $decision->actsAsLogistics(),
            'client' => $decision->toClientArray(),
            'on_duty' => $decision->onDuty->toArray(),
            'narcotics_review' => app(NarcoticsCheckAccess::class)->canReview($user),
            'command_300' => app(Command300Access::class)->allows($user),
            'approves_captain_5' => app(ActingApprovers::class)->can($user, Captain5ActingRequest::ROLE_CAPTAIN_5),
            'approves_300' => app(ActingApprovers::class)->can($user, Captain5ActingRequest::ROLE_COMMAND_300),
        ];
    }

    /** @return array<string, list<int>> */
    private function routing(): array
    {
        $ids = static fn ($users): array => $users->map(static fn (User $user): int => (int) $user->getKey())->values()->all();

        return [
            'notification_recipients' => $ids(app(Captain5DutyRoster::class)->notificationRecipients()),
            'recipients_on_duty' => $ids(app(Captain5Recipients::class)->onDuty()),
            'recipients_staff' => $ids(app(Captain5Recipients::class)->staff()),
            'approvers_captain_5' => $ids(app(ActingApprovers::class)->users(Captain5ActingRequest::ROLE_CAPTAIN_5)),
            'approvers_300' => $ids(app(ActingApprovers::class)->users(Captain5ActingRequest::ROLE_COMMAND_300)),
        ];
    }

    public function test_the_display_really_does_change_so_the_rest_of_this_file_proves_something(): void
    {
        $this->actAs($this->bidChief);
        $this->getJson('/api/command-300/shift')->assertOk()
            ->assertJsonPath('on_duty.source', 'telestaff')
            ->assertJsonPath('on_duty.name', 'Roster Only');

        $this->actAs($this->bidCaptain);
        $this->getJson('/api/captain5/summary')->assertOk()
            ->assertJsonPath('on_duty.source', 'telestaff')
            ->assertJsonPath('on_duty.name', 'Roster Only');
    }

    public function test_every_authority_answer_is_identical_with_the_display_on_and_off(): void
    {
        $people = [$this->bidCaptain, $this->bidChief, $this->rosterOnly, $this->administrator, $this->officer('Lieutenant', 'Ordinary Officer')];
        $on = array_map(fn (User $user): array => $this->authorityOf($user), $people);
        $this->displayOff();
        $off = array_map(fn (User $user): array => $this->authorityOf($user), $people);

        self::assertSame($off, $on);
    }

    public function test_notification_routing_and_approvers_are_identical_with_the_display_on_and_off(): void
    {
        $on = $this->routing();
        $this->displayOff();

        self::assertSame($this->routing(), $on);
        self::assertSame([(int) $this->bidCaptain->getKey()], $on['notification_recipients']);
        self::assertSame([(int) $this->bidCaptain->getKey()], $on['recipients_on_duty']);
        self::assertNotContains((int) $this->rosterOnly->getKey(), $on['recipients_staff']);
    }

    public function test_a_roster_only_officer_gains_nothing(): void
    {
        $answers = $this->authorityOf($this->rosterOnly);

        self::assertFalse($answers['narcotics_review']);
        self::assertFalse($answers['is_shown_captain5']);
        self::assertFalse($answers['view']);
        self::assertFalse($answers['act']);
        self::assertSame('view_only', $answers['authority']);
        self::assertFalse($answers['acts_as_logistics']);
        self::assertFalse($answers['command_300']);
        self::assertFalse($answers['approves_captain_5']);
        self::assertFalse($answers['approves_300']);
        self::assertSame(OnDutyCaptain5::SOURCE_BID, $answers['on_duty']['source'], 'The seat answer never reads the roster');
        self::assertSame('Seat Captain', $answers['on_duty']['name']);
    }

    public function test_the_seat_holder_keeps_everything_even_though_the_card_names_someone_else(): void
    {
        $answers = $this->authorityOf($this->bidCaptain);

        self::assertTrue($answers['narcotics_review']);
        self::assertTrue($answers['is_shown_captain5']);
        self::assertSame('shift_captain5', $answers['authority']);
    }

    public function test_the_roster_only_officer_is_refused_by_the_narcotics_staff_api(): void
    {
        $this->actAs($this->rosterOnly);
        $this->getJson('/api/narcotics-checks/staff/checks')->assertForbidden();

        $this->actAs($this->bidCaptain);
        $this->getJson('/api/narcotics-checks/staff/checks')->assertOk();
    }

    public function test_the_roster_only_officer_is_refused_by_the_300_api(): void
    {
        $this->actAs($this->rosterOnly);
        $this->getJson('/api/command-300/shift')->assertForbidden();
    }

    public function test_the_roster_only_officer_gets_nothing_from_the_captain_5_summary_and_is_refused_the_workspace(): void
    {
        $this->actAs($this->rosterOnly);
        $response = $this->getJson('/api/captain5/summary')->assertOk()->assertExactJson(['access' => ['view' => false]]);
        self::assertStringNotContainsString('Roster Only', (string) $response->getContent());
        $this->getJson('/api/captain5/workspace')->assertForbidden();
    }

    public function test_your_access_stays_on_the_seat_decision_while_the_card_shows_the_roster(): void
    {
        $this->actAs($this->bidCaptain);

        foreach (['/api/captain5/summary', '/api/captain5/workspace'] as $endpoint) {
            $this->getJson($endpoint)->assertOk()
                ->assertJsonPath('access.authority', 'shift_captain5')
                ->assertJsonPath('access.authority_label', 'Captain 5 (on shift)')
                ->assertJsonPath('on_duty.name', 'Roster Only');
        }
    }

    public function test_new_findings_are_still_routed_to_the_seat_and_never_to_the_person_on_the_card(): void
    {
        $crew = $this->officer('Firefighter', 'Crew Member');
        $this->reportMissing($this->rescue, $crew, [$this->firstItemId($this->rescue)]);

        $notified = DB::table('inspection_notification_deliveries')
            ->where('event_key', 'like', 'captain5:%')->pluck('recipient_user_id')->map(static fn ($id): int => (int) $id)->all();

        self::assertContains((int) $this->bidCaptain->getKey(), $notified);
        self::assertNotContains((int) $this->rosterOnly->getKey(), $notified);
    }

    public function test_the_admin_page_shows_the_card_name_and_who_notifications_go_to_when_they_differ(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actAs($this->superAdmin());

        Livewire::test(Captain5Operations::class)
            ->assertSee('Shown on cards: Roster Only')
            ->assertSee('Source: TeleStaff roster')
            ->assertSee('Notifications go to: Seat Captain.');
    }

    public function test_the_admin_page_says_nothing_about_notifications_when_card_and_seat_agree(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->displayOff();
        $this->actAs($this->superAdmin());

        Livewire::test(Captain5Operations::class)
            ->assertSee('Shown on cards: Seat Captain')
            ->assertSee('Captain 5 bid seat')
            ->assertDontSee('Notifications go to')
            ->assertDontSee('TeleStaff');
    }

    public function test_no_employee_id_reaches_a_member_payload(): void
    {
        $this->actAs($this->bidCaptain);

        foreach (['/api/captain5/summary', '/api/captain5/workspace'] as $endpoint) {
            $onDuty = $this->getJson($endpoint)->assertOk()->json('on_duty');
            self::assertStringNotContainsString((string) $this->rosterOnly->employee_id, (string) json_encode($onDuty));
        }
    }

    public function test_no_authority_or_routing_class_references_the_roster(): void
    {
        foreach (self::AUTHORITY_AND_ROUTING_FILES as $path) {
            self::assertFileExists(base_path($path), "{$path} moved: keep this contract pointing at the real class");
            self::assertDoesNotMatchRegularExpression(
                '/DailyRoster|OnDutyOfficerDisplay|CurrentRosterReader|RosterFreshness|RosterSnapshot|RosterAssignment|RosterSection|roster_assignments|roster_snapshots|roster_active_versions/',
                (string) file_get_contents(base_path($path)),
                "{$path} must not read the TeleStaff roster",
            );
        }
    }

    public function test_only_the_four_card_endpoints_and_the_admin_page_ask_who_the_cards_show(): void
    {
        $consumers = [];
        foreach (File::allFiles(app_path()) as $file) {
            $relative = str_replace('\\', '/', 'app/'.$file->getRelativePathname());
            if (str_starts_with($relative, 'app/Services/DailyRoster/')) {
                continue;
            }
            if (str_contains($file->getContents(), 'OnDutyOfficerDisplay')) {
                $consumers[] = $relative;
            }
        }
        sort($consumers);

        self::assertSame(self::DISPLAY_CONSUMERS, $consumers);
    }
}
