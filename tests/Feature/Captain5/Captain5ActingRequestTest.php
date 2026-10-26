<?php

declare(strict_types=1);

namespace Tests\Feature\Captain5;

use App\Models\Captain5\Captain5ActingRequest;
use App\Models\User;
use App\Services\Captain5\Captain5ActingService;
use App\Services\Captain5\Captain5Notifier;
use App\Services\Captain5\OnDutyCaptain5;
use App\Services\Security\Captain5Access;
use App\Support\Captain5\DutyCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Captain5\Concerns\BuildsCaptain5World;
use Tests\TestCase;

final class Captain5ActingRequestTest extends TestCase
{
    use BuildsCaptain5World;
    use RefreshDatabase;

    private User $holder;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->buildCaptain5World('2026-10-20 10:00:00'); // A-shift day, 10:00 ET
        $this->holder = $this->officer('Captain', 'Oscar Lima');
        $this->seat($this->holder, 'Captain 5', 'A Shift');
        $this->admin = $this->captain5Administrator();
    }

    public function test_duration_choices_end_at_the_right_wall_clock_times(): void
    {
        $calendar = app(DutyCalendar::class);
        $at = fn (string $local) => CarbonImmutable::parse($local, 'America/New_York');
        $local = fn (CarbonImmutable $utc) => $utc->setTimezone('America/New_York')->format('Y-m-d H:i');
        self::assertSame('2026-10-20 20:00', $local($calendar->actingEnd('12h', $at('2026-10-20 10:00'))));
        self::assertSame('2026-10-21 08:00', $local($calendar->actingEnd('12h', $at('2026-10-20 21:30'))));
        self::assertSame('2026-10-21 08:00', $local($calendar->actingEnd('12h', $at('2026-10-21 07:00'))));
        self::assertSame('2026-10-21 08:00', $local($calendar->actingEnd('24h', $at('2026-10-20 10:00'))));
        self::assertSame('2026-10-22 08:00', $local($calendar->actingEnd('24h', $at('2026-10-21 07:00'))));
    }

    public function test_request_approval_shows_the_member_and_the_display_ends_on_its_own(): void
    {
        $lieutenant = $this->officer('Lieutenant', 'Rita Acting');
        $this->actAs($lieutenant);
        $this->postJson('/api/captain5/acting-requests', ['kind' => 'act', 'duration' => '12h', 'client_request_id' => (string) Str::uuid()])
            ->assertCreated()->assertJsonPath('on_duty.source', OnDutyCaptain5::SOURCE_BID)->assertJsonPath('acting.mine.kind', 'act')
            ->assertJsonPath('acting.server_time', '2026-10-20T10:00:00-04:00');
        $request = Captain5ActingRequest::query()->sole();
        self::assertSame(Captain5ActingRequest::PENDING, $request->status);
        self::assertSame('2026-10-20 20:00', $request->ends_at->setTimezone('America/New_York')->format('Y-m-d H:i'));
        self::assertSame([(int) $this->admin->id], DB::table('inspection_notification_deliveries')
            ->where('event_key', 'captain5:acting:'.$request->id.':requested')->pluck('recipient_user_id')->map(fn ($id): int => (int) $id)->all(),
            'Captain 5 administrators are asked to approve');

        $this->actAs($this->admin);
        $this->postJson('/api/captain5/acting-requests/'.$request->id.'/approve', [])->assertOk()
            ->assertJsonPath('on_duty.source', OnDutyCaptain5::SOURCE_ACTING)
            ->assertJsonPath('on_duty.name', 'Rita Acting')
            ->assertJsonPath('on_duty.shift', 'A');
        self::assertDatabaseHas('inspection_notification_deliveries', ['event_key' => 'captain5:acting:'.$request->id.':decided', 'recipient_user_id' => $lieutenant->id]);

        $this->travelTo(CarbonImmutable::parse('2026-10-20 20:00:01', 'America/New_York'));
        $this->forgetCaptain5Memo();
        $this->actAs($lieutenant);
        $this->getJson('/api/captain5/summary')->assertOk()->assertJsonPath('on_duty.source', OnDutyCaptain5::SOURCE_BID)
            ->assertJsonPath('on_duty.name', 'Oscar Lima');
    }

    public function test_takeover_and_end_requests_need_approval_too(): void
    {
        $first = $this->officer('Lieutenant', 'First Acting');
        $second = $this->officer('Lieutenant', 'Second Acting');
        $service = app(Captain5ActingService::class);
        $one = $service->request($first, ['kind' => 'act', 'duration' => '24h']);
        $service->approve($this->admin, $one->id);

        $two = $service->request($second, ['kind' => 'act', 'duration' => 'custom', 'ends_at' => '2026-10-20 18:30']);
        self::assertSame('2026-10-20 22:30:00', $two->fresh()->getRawOriginal('ends_at'));
        $this->forgetCaptain5Memo();
        self::assertSame('First Acting', app(Captain5Access::class)->decide($first)->onDuty->toArray()['name'], 'Pending takeover changes nothing yet');
        $service->approve($this->admin, $two->id);
        $this->forgetCaptain5Memo();
        self::assertSame('Second Acting', app(Captain5Access::class)->decide($first)->onDuty->toArray()['name']);
        self::assertNotNull($one->fresh()->ended_at);
        self::assertSame($two->id, (int) $one->fresh()->ended_by_request_id);

        $end = $service->request($first, ['kind' => 'end']);
        $service->approve($this->admin, $end->id);
        $this->forgetCaptain5Memo();
        self::assertSame(OnDutyCaptain5::SOURCE_BID, app(Captain5Access::class)->decide($first)->onDuty->source);
        self::assertSame(3, Captain5ActingRequest::query()->count(), 'Every request stays as history');
    }

    public function test_reject_needs_a_note_and_withdraw_and_self_approval_rules(): void
    {
        $lieutenant = $this->officer('Lieutenant');
        $service = app(Captain5ActingService::class);
        $request = $service->request($lieutenant, ['kind' => 'act', 'duration' => '12h']);

        try {
            $service->reject($this->admin, $request->id, '  ');
            self::fail('A rejection needs a note');
        } catch (ValidationException) {
        }
        $service->reject($this->admin, $request->id, 'Captain 5 is on duty today');
        self::assertSame(Captain5ActingRequest::REJECTED, $request->fresh()->status);

        $again = $service->request($lieutenant, ['kind' => 'act', 'duration' => '12h']);
        $service->withdraw($lieutenant, $again->id);
        self::assertSame(Captain5ActingRequest::WITHDRAWN, $again->fresh()->status);

        $ownAdmin = $service->request($this->admin, ['kind' => 'act', 'duration' => '12h']);
        $this->expectException(AuthorizationException::class);
        $service->approve($this->admin, $ownAdmin->id); // another administrator approves your own request
    }

    public function test_only_page_users_request_and_only_administrators_approve(): void
    {
        $this->actAs($this->officer('Firefighter'));
        $this->postJson('/api/captain5/acting-requests', ['kind' => 'act', 'duration' => '12h'])->assertForbidden();

        $lieutenant = $this->officer('Lieutenant');
        $request = app(Captain5ActingService::class)->request($lieutenant, ['kind' => 'act', 'duration' => '12h']);
        $this->actAs($this->officer('Division Chief'));
        $this->postJson('/api/captain5/acting-requests/'.$request->id.'/approve', [])->assertForbidden();
        self::assertSame(Captain5ActingRequest::PENDING, $request->fresh()->status);
    }

    public function test_bad_custom_times_expired_requests_and_retries(): void
    {
        $lieutenant = $this->officer('Lieutenant');
        $this->actAs($lieutenant);
        $this->postJson('/api/captain5/acting-requests', ['kind' => 'act', 'duration' => 'custom', 'ends_at' => '2026-10-20 10:05'])->assertStatus(422);
        $this->postJson('/api/captain5/acting-requests', ['kind' => 'act', 'duration' => 'custom', 'ends_at' => '2026-10-25 10:00'])->assertStatus(422);
        $this->postJson('/api/captain5/acting-requests', ['kind' => 'end'])->assertStatus(422); // nobody is acting

        $key = (string) Str::uuid();
        $this->postJson('/api/captain5/acting-requests', ['kind' => 'act', 'duration' => '12h', 'client_request_id' => $key])->assertCreated();
        $this->postJson('/api/captain5/acting-requests', ['kind' => 'act', 'duration' => '12h', 'client_request_id' => $key])->assertCreated();
        self::assertSame(1, Captain5ActingRequest::query()->count(), 'A retried request is stored once');

        $this->travelTo(CarbonImmutable::parse('2026-10-20 20:30:00', 'America/New_York'));
        $this->expectException(ValidationException::class);
        app(Captain5ActingService::class)->approve($this->admin, Captain5ActingRequest::query()->sole()->id);
    }

    public function test_the_shift_captain5_gets_new_findings_alerts_and_acting_changes_who(): void
    {
        $acting = $this->officer('Lieutenant', 'Acting Officer');
        $this->actingAssignment($acting, $this->admin);
        [$defect] = $this->reportMissing($this->rescue, $this->newMember('Reporter'), ['compartment_c-item-3']);
        $recipients = DB::table('inspection_notification_deliveries')->where('event_key', 'captain5:inspection:'.$defect->first_reported_inspection_id.':findings')
            ->pluck('recipient_user_id')->map(fn ($id): int => (int) $id)->all();
        self::assertSame([(int) $acting->id], $recipients);
        self::assertSame(Captain5Notifier::KIND_NEW_FINDINGS, DB::table('inspection_notification_deliveries')->where('recipient_user_id', $acting->id)->value('notification_type'));
    }
}
