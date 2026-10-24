<?php

declare(strict_types=1);

namespace Tests\Feature\Command300;

use App\Models\Captain5\Captain5ActingRequest;
use App\Models\User;
use App\Services\Captain5\Captain5ActingService;
use App\Services\Captain5\Captain5DutyRoster;
use App\Services\Captain5\OnDutyCaptain5;
use App\Services\Security\Command300Access;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Captain5\Concerns\BuildsCaptain5World;
use Tests\TestCase;

/**
 * The shift's 300 (Division Chief) on the 300 page and card, and "Acting as 300 today?": the same
 * acting engine as Captain 5 with role `command_300`. Display only.
 */
final class Command300ShiftTest extends TestCase
{
    use BuildsCaptain5World;
    use RefreshDatabase;

    private const SHIFT = '/api/command-300/shift';

    private const ACTING = '/api/command-300/acting-requests';

    private User $chiefA;

    private User $chiefB;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->buildCaptain5World('2026-10-20 10:00:00'); // A-shift duty day, 10:00 ET
        $this->chiefA = $this->officer('Division Chief', 'Alpha Chief');
        $this->seat($this->chiefA, Command300Access::COMMAND_UNIT_LABEL, 'A Shift');
        $this->chiefB = $this->officer('Division Chief', 'Bravo Chief');
        $this->seat($this->chiefB, Command300Access::COMMAND_UNIT_LABEL, 'B Shift');
        $this->admin = $this->captain5Administrator();
    }

    public function test_the_shift_300_is_the_bid_holder_for_the_shift_on_duty_and_changes_at_0800(): void
    {
        $viewer = $this->officer('Captain', 'Viewing Captain');
        $this->actAs($viewer);
        $this->getJson(self::SHIFT)->assertOk()
            ->assertJsonPath('on_duty.shift', 'A')
            ->assertJsonPath('on_duty.source', OnDutyCaptain5::SOURCE_BID)
            ->assertJsonPath('on_duty.name', 'Alpha Chief')
            ->assertJsonPath('on_duty.rank', 'Division Chief')
            ->assertJsonPath('acting.role', Captain5ActingRequest::ROLE_COMMAND_300)
            ->assertJsonPath('acting.can_request', true);

        // The session expires when the clock jumps; sign in again after each jump.
        $this->travelTo(CarbonImmutable::parse('2026-10-21 07:59:00', 'America/New_York'));
        $this->actAs($viewer);
        $this->getJson(self::SHIFT)->assertOk()->assertJsonPath('on_duty.name', 'Alpha Chief');
        $this->travelTo(CarbonImmutable::parse('2026-10-21 08:00:00', 'America/New_York'));
        $this->actAs($viewer);
        $this->getJson(self::SHIFT)->assertOk()->assertJsonPath('on_duty.shift', 'B')->assertJsonPath('on_duty.name', 'Bravo Chief');

        // C shift has no 300 seat: an explicit empty state, never a guess.
        $this->travelTo(CarbonImmutable::parse('2026-10-22 08:00:00', 'America/New_York'));
        $this->actAs($viewer);
        $this->getJson(self::SHIFT)->assertOk()->assertJsonPath('on_duty.shift', 'C')
            ->assertJsonPath('on_duty.resolved', false)->assertJsonPath('on_duty.name', null)
            ->assertJsonPath('on_duty.source', OnDutyCaptain5::SOURCE_UNKNOWN);
    }

    public function test_a_captain_asks_an_administrator_approves_and_the_display_ends_on_its_own(): void
    {
        $captain = $this->officer('Captain', 'Charlie Captain');
        $this->actAs($captain);
        $this->postJson(self::ACTING, ['kind' => 'act', 'duration' => '12h', 'client_request_id' => (string) Str::uuid()])
            ->assertCreated()->assertJsonPath('acting.mine.role', Captain5ActingRequest::ROLE_COMMAND_300)
            ->assertJsonPath('acting.mine.role_label', '300')
            ->assertJsonPath('on_duty.source', OnDutyCaptain5::SOURCE_BID);
        $request = Captain5ActingRequest::query()->sole();
        self::assertSame(Captain5ActingRequest::ROLE_COMMAND_300, $request->role);
        self::assertSame('2026-10-20 20:00', $request->ends_at->setTimezone('America/New_York')->format('Y-m-d H:i'));
        self::assertSame(1, DB::table('inspection_notification_deliveries')
            ->where('event_key', 'captain5:acting:'.$request->id.':requested')->where('recipient_user_id', $this->admin->id)->count(),
            'Administrators are asked to approve acting 300');

        $this->actAs($this->admin);
        $this->getJson(self::SHIFT)->assertJsonPath('acting.can_approve', true)->assertJsonPath('acting.pending.0.id', $request->id);
        $this->postJson(self::ACTING.'/'.$request->id.'/approve', [])->assertOk()
            ->assertJsonPath('on_duty.source', OnDutyCaptain5::SOURCE_ACTING)
            ->assertJsonPath('on_duty.name', 'Charlie Captain')
            ->assertJsonPath('on_duty.rank', 'Captain')
            ->assertJsonPath('on_duty.shift', 'A');
        self::assertDatabaseHas('inspection_notification_deliveries', ['event_key' => 'captain5:acting:'.$request->id.':decided', 'recipient_user_id' => $captain->id]);

        // The Captain 5 display is a different role and does not change.
        self::assertSame(OnDutyCaptain5::SOURCE_UNKNOWN, app(Captain5DutyRoster::class)->onDuty()->source);

        $this->travelTo(CarbonImmutable::parse('2026-10-20 20:00:01', 'America/New_York'));
        $this->forgetCaptain5Memo();
        $this->actAs($captain);
        $this->getJson(self::SHIFT)->assertOk()->assertJsonPath('on_duty.source', OnDutyCaptain5::SOURCE_BID)->assertJsonPath('on_duty.name', 'Alpha Chief');
    }

    public function test_takeover_and_end_need_approval_and_stay_within_the_300_role(): void
    {
        $service = app(Captain5ActingService::class);
        $first = $this->officer('Captain', 'First Captain');
        $second = $this->officer('Captain', 'Second Captain');
        $one = $service->request($first, ['role' => 'command_300', 'kind' => 'act', 'duration' => '24h']);
        $service->approve($this->admin, $one->id);
        $captain5 = $this->actingAssignment($this->officer('Lieutenant', 'Acting Captain Five'), $this->admin);

        $two = $service->request($second, ['role' => 'command_300', 'kind' => 'act', 'duration' => 'custom', 'ends_at' => '2026-10-20 18:30']);
        $this->forgetCaptain5Memo();
        self::assertSame('First Captain', app(Captain5DutyRoster::class)->command300OnDuty()->toArray()['name'], 'A pending takeover changes nothing');
        $service->approve($this->admin, $two->id);
        $this->forgetCaptain5Memo();
        self::assertSame('Second Captain', app(Captain5DutyRoster::class)->command300OnDuty()->toArray()['name']);
        self::assertSame($two->id, (int) $one->fresh()->ended_by_request_id);
        self::assertNull($captain5->fresh()->ended_at, 'A 300 takeover never ends the acting Captain 5');

        $end = $service->request($first, ['role' => 'command_300', 'kind' => 'end']);
        $service->approve($this->admin, $end->id);
        $this->forgetCaptain5Memo();
        self::assertSame(OnDutyCaptain5::SOURCE_BID, app(Captain5DutyRoster::class)->command300OnDuty()->source);
        self::assertSame(OnDutyCaptain5::SOURCE_ACTING, app(Captain5DutyRoster::class)->onDuty()->source, 'Ending acting 300 leaves acting Captain 5 alone');
        self::assertSame(3, Captain5ActingRequest::query()->forRole('command_300')->count(), 'Every 300 request stays as history');
    }

    public function test_rejection_needs_a_note_and_withdraw_and_self_approval_rules_apply(): void
    {
        $captain = $this->officer('Captain');
        $this->actAs($captain);
        $id = $this->postJson(self::ACTING, ['kind' => 'act', 'duration' => '24h'])->assertCreated()->json('acting.mine.id');

        $this->actAs($this->admin);
        $this->postJson(self::ACTING.'/'.$id.'/reject', [])->assertStatus(422);
        $this->postJson(self::ACTING.'/'.$id.'/reject', ['note' => 'Chief Alpha is on duty'])->assertOk();
        self::assertSame(Captain5ActingRequest::REJECTED, Captain5ActingRequest::query()->findOrFail($id)->status);
        self::assertDatabaseHas('inspection_notification_deliveries', ['event_key' => 'captain5:acting:'.$id.':decided', 'recipient_user_id' => $captain->id]);

        $this->actAs($captain);
        $again = $this->postJson(self::ACTING, ['kind' => 'act', 'duration' => '12h'])->json('acting.mine.id');
        $this->postJson(self::ACTING.'/'.$again.'/withdraw')->assertOk()->assertJsonPath('acting.mine', null);
        self::assertSame(Captain5ActingRequest::WITHDRAWN, Captain5ActingRequest::query()->findOrFail($again)->status);

        $own = app(Captain5ActingService::class)->request($this->admin, ['role' => 'command_300', 'kind' => 'act', 'duration' => '12h']);
        $this->expectException(AuthorizationException::class);
        app(Captain5ActingService::class)->approve($this->admin, $own->id);
    }

    public function test_any_administrator_is_asked_and_approves_both_acting_300_and_acting_captain5(): void
    {
        $panelAdmin = $this->officer('Firefighter', 'Panel Admin');
        $panelAdmin->givePermissionTo(Permission::findOrCreate('admin.access', 'web'));
        $this->forgetCaptain5Memo();
        $panelAdmin = $panelAdmin->fresh();
        $service = app(Captain5ActingService::class);

        $as300 = $service->request($this->officer('Captain', 'Acting Three'), ['role' => 'command_300', 'kind' => 'act', 'duration' => '12h']);
        $asC5 = $service->request($this->officer('Captain', 'Acting Five'), ['role' => 'captain_5', 'kind' => 'act', 'duration' => '12h']);
        $asked = fn (Captain5ActingRequest $r): array => DB::table('inspection_notification_deliveries')
            ->where('event_key', 'captain5:acting:'.$r->id.':requested')->orderBy('recipient_user_id')->pluck('recipient_user_id')->map(fn ($id): int => (int) $id)->all();
        $expected = [(int) $this->admin->id, (int) $panelAdmin->id];
        self::assertSame($expected, $asked($as300), 'Any administrator is asked about acting 300');
        self::assertSame($expected, $asked($asC5), 'and about acting Captain 5 (the same approver set)');

        $this->actAs($panelAdmin); // Admin Panel rights only, no captain5.admin
        $this->getJson(self::SHIFT)->assertJsonPath('acting.can_approve', true)->assertJsonPath('acting.pending.0.id', $as300->id);
        $this->getJson('/api/captain5/workspace')->assertJsonPath('acting.can_approve', true)->assertJsonPath('acting.pending.0.id', $asC5->id);
        $this->postJson(self::ACTING.'/'.$as300->id.'/approve', [])->assertOk()->assertJsonPath('on_duty.name', 'Acting Three');
        $this->postJson('/api/captain5/acting-requests/'.$asC5->id.'/approve', [])->assertOk()->assertJsonPath('on_duty.name', 'Acting Five');

        // Losing Admin Panel rights removes the ability to approve.
        $panelAdmin->revokePermissionTo(Permission::findByName('admin.access', 'web'));
        $this->forgetCaptain5Memo();
        $late = $service->request($this->officer('Captain', 'Late Request'), ['role' => 'command_300', 'kind' => 'act', 'duration' => '12h']);
        $this->actAs($panelAdmin->fresh());
        $this->postJson(self::ACTING.'/'.$late->id.'/approve', [])->assertForbidden();
    }

    public function test_only_300_page_users_ask_and_members_without_admin_rights_never_approve(): void
    {
        $this->actAs($this->officer('Lieutenant'));
        $this->getJson(self::SHIFT)->assertForbidden(); // Lieutenants do not use the 300 page
        $this->postJson(self::ACTING, ['kind' => 'act', 'duration' => '12h'])->assertForbidden();
        $this->actAs($this->officer('Firefighter'));
        $this->getJson(self::SHIFT)->assertForbidden();

        $grant = $this->officer('Firefighter', 'Granted Member');
        $grant->givePermissionTo(Permission::findOrCreate(Command300Access::PERMISSION, 'web'));
        $this->forgetCaptain5Memo();
        self::assertTrue(app(Captain5ActingService::class)->canRequest($grant->fresh(), 'command_300'), 'Explicit 300 access may ask');

        $request = app(Captain5ActingService::class)->request($this->officer('Captain'), ['role' => 'command_300', 'kind' => 'act', 'duration' => '12h']);
        $this->actAs($this->chiefB); // a Division Chief without Admin Panel rights
        $this->getJson(self::SHIFT)->assertOk()->assertJsonPath('acting.can_approve', false)->assertJsonPath('acting.pending', []);
        $this->postJson(self::ACTING.'/'.$request->id.'/approve', [])->assertForbidden();
        self::assertSame(Captain5ActingRequest::PENDING, $request->fresh()->status);
    }

    public function test_each_page_decides_only_its_own_role(): void
    {
        $service = app(Captain5ActingService::class);
        $as300 = $service->request($this->officer('Captain', 'Three Hundred'), ['role' => 'command_300', 'kind' => 'act', 'duration' => '12h']);
        $asC5 = $service->request($this->officer('Captain', 'Captain Five'), ['role' => 'captain_5', 'kind' => 'act', 'duration' => '12h']);

        $this->actAs($this->admin);
        $this->postJson('/api/captain5/acting-requests/'.$as300->id.'/approve', [])->assertNotFound();
        $this->postJson(self::ACTING.'/'.$asC5->id.'/approve', [])->assertNotFound();
        self::assertSame(Captain5ActingRequest::PENDING, $as300->fresh()->status);
        self::assertSame(Captain5ActingRequest::PENDING, $asC5->fresh()->status);

        // Each page's queue lists only its own role.
        $this->getJson(self::SHIFT)->assertJsonCount(1, 'acting.pending')->assertJsonPath('acting.pending.0.id', $as300->id);
        $this->getJson('/api/captain5/workspace')->assertJsonCount(1, 'acting.pending')->assertJsonPath('acting.pending.0.id', $asC5->id);

        // One pending request per member and role: asking for 300 never withdraws a Captain 5 request.
        $both = $this->officer('Captain', 'Both Roles');
        $c5 = $service->request($both, ['role' => 'captain_5', 'kind' => 'act', 'duration' => '12h']);
        $service->request($both, ['role' => 'command_300', 'kind' => 'act', 'duration' => '12h']);
        self::assertSame(Captain5ActingRequest::PENDING, $c5->fresh()->status);
    }

    public function test_acting_300_is_display_only_and_validation_is_enforced(): void
    {
        $firefighter = $this->officer('Firefighter', 'Forged Row');
        Captain5ActingRequest::query()->create([
            'kind' => 'act', 'role' => 'command_300', 'employee_profile_id' => $firefighter->employee_profile_id,
            'requested_by_user_id' => $this->admin->id, 'duration' => '24h', 'shift_letter' => 'A',
            'ends_at' => now()->addHours(6), 'status' => 'approved', 'decided_by_user_id' => $this->admin->id, 'decided_at' => now(), 'starts_at' => now(),
        ]);
        $this->forgetCaptain5Memo();
        self::assertSame('Forged Row', app(Captain5DutyRoster::class)->command300OnDuty()->toArray()['name']);
        self::assertFalse(app(Command300Access::class)->allows($firefighter->fresh()), 'Being shown as 300 grants no 300 access');

        $this->actAs($this->officer('Captain'));
        $this->postJson(self::ACTING, ['kind' => 'act', 'duration' => 'custom', 'ends_at' => '2026-10-20 10:05'])->assertStatus(422);
        $this->postJson(self::ACTING, ['kind' => 'act', 'duration' => 'custom', 'ends_at' => '2026-10-25 10:00'])->assertStatus(422);
        $this->postJson(self::ACTING, ['kind' => 'act', 'duration' => '48h'])->assertStatus(422);
        $this->postJson(self::ACTING, ['role' => 'captain_5', 'kind' => 'act', 'duration' => '12h'])->assertCreated()
            ->assertJsonPath('acting.mine.role', 'command_300'); // the route decides the role, never the body

        $key = (string) Str::uuid();
        $this->postJson(self::ACTING, ['kind' => 'act', 'duration' => '24h', 'client_request_id' => $key])->assertCreated();
        $this->postJson(self::ACTING, ['kind' => 'act', 'duration' => '24h', 'client_request_id' => $key])->assertCreated();
        self::assertSame(1, Captain5ActingRequest::query()->where('client_request_id', $key)->count(), 'A retried request is stored once');

        $pending = Captain5ActingRequest::query()->where('client_request_id', $key)->sole();
        $this->travelTo(CarbonImmutable::parse('2026-10-21 08:00:01', 'America/New_York'));
        $this->expectException(ValidationException::class);
        app(Captain5ActingService::class)->approve($this->admin, $pending->id);
    }
}
