<?php

declare(strict_types=1);

namespace Tests\Feature\Captain5\Admin;

use App\Filament\Admin\Pages\Captain5Operations;
use App\Models\Captain5\Captain5ActingRequest;
use App\Models\User;
use App\Services\Captain5\Captain5ActingService;
use App\Services\Captain5\Captain5DutyRoster;
use App\Support\Captain5\DutyCalendar;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Feature\Captain5\Concerns\BuildsCaptain5World;
use Tests\TestCase;

/**
 * The 300 side of the one approval UI on the Admin Panel page: 300 requests are listed with a "300"
 * badge next to Captain 5 requests, approval changes only the 300 "Shown now" block, and a takeover
 * ends only the earlier 300 display.
 */
final class Captain5Acting300RequestsSectionTest extends TestCase
{
    use BuildsCaptain5World;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->buildCaptain5World('2026-10-20 10:00:00'); // A-shift duty day
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_both_blocks_show_their_own_bid_seat_holder(): void
    {
        $captain5 = $this->officer('Captain', 'Seat Holder Cy');
        $this->seat($captain5, 'Captain 5', 'A Shift');
        $chief = $this->officer('Division Chief', 'Chief Holder Hal');
        $this->seat($chief, '300', 'A Shift');
        $this->actAs($this->superAdmin());

        $page = Livewire::test(Captain5Operations::class)->assertSee('Acting Captain 5 and 300 requests');

        self::assertStringContainsString('Seat Holder Cy', $this->block($page, 'captain_5'));
        self::assertStringContainsString('Captain 5 bid seat', $this->block($page, 'captain_5'));
        self::assertStringContainsString('Chief Holder Hal', $this->block($page, 'command_300'));
        self::assertStringContainsString('Division Chief', $this->block($page, 'command_300'));
        self::assertStringContainsString('300 bid seat', $this->block($page, 'command_300'));
        self::assertStringContainsString('Shown as 300 now', $this->block($page, 'command_300'));
    }

    public function test_the_300_block_says_not_set_when_nobody_is_shown(): void
    {
        $this->actAs($this->superAdmin());

        $page = Livewire::test(Captain5Operations::class);

        self::assertStringContainsString('No one is shown as 300 right now', $this->block($page, 'command_300'));
        self::assertStringContainsString('Not set', $this->block($page, 'command_300'));
        self::assertStringContainsString('No one is shown as Captain 5 right now', $this->block($page, 'captain_5'));
    }

    public function test_pending_requests_of_both_roles_share_one_list_oldest_first_with_role_badges(): void
    {
        $first = $this->officer('Captain', 'Older Orla');
        $second = $this->officer('Captain', 'Newer Nico');
        $this->requestActing($first, 'command_300', '12h');
        $this->travel(5)->minutes();
        $this->requestActing($second, 'captain_5', '24h');
        $this->actAs($this->captain5Administrator());

        $page = Livewire::test(Captain5Operations::class)
            ->assertSee('asks to be shown as acting 300')->assertSee('asks to be shown as acting Captain 5')
            ->assertSeeInOrder(['Older Orla', 'Newer Nico']);

        $pending = $this->rows($page, 'captain5-acting-pending');
        self::assertCount(2, $pending);
        self::assertSame('command_300', $pending[0]['role']);
        self::assertSame('300', $pending[0]['badge']);
        self::assertSame('captain_5', $pending[1]['role']);
        self::assertSame('Captain 5', $pending[1]['badge']);
    }

    public function test_approving_a_300_request_changes_only_the_300_block(): void
    {
        $captain5 = $this->officer('Captain', 'Seat Holder Cy');
        $this->seat($captain5, 'Captain 5', 'A Shift');
        $chief = $this->officer('Division Chief', 'Chief Holder Hal');
        $this->seat($chief, '300', 'A Shift');
        $acting = $this->officer('Captain', 'Acting Three Ari');
        $request = $this->requestActing($acting, 'command_300', '24h');
        $admin = $this->captain5Administrator();
        $this->actAs($admin);

        $page = Livewire::test(Captain5Operations::class);
        self::assertStringContainsString('Chief Holder Hal', $this->block($page, 'command_300'));
        $captain5Before = $this->block($page, 'captain_5');

        $page->callAction('approveActing', ['note' => 'Chief is out'], ['request' => $request->getKey()])
            ->assertHasNoActionErrors()->assertNotified('Acting 300 request approved');

        $request->refresh();
        self::assertSame(Captain5ActingRequest::APPROVED, $request->status);
        self::assertSame(Captain5ActingRequest::ROLE_COMMAND_300, $request->role);
        self::assertSame((int) $admin->id, (int) $request->decided_by_user_id);

        $shown300 = $this->block($page, 'command_300');
        self::assertStringContainsString('Acting Three Ari', $shown300);
        self::assertStringContainsString('Acting 300 (approved request)', $shown300);
        self::assertStringContainsString('Acting until', $shown300);
        self::assertStringNotContainsString('Chief Holder Hal', $shown300);
        self::assertSame($captain5Before, $this->block($page, 'captain_5'), 'The Captain 5 block must not change');

        $this->forgetCaptain5Memo();
        self::assertSame((int) $captain5->employee_profile_id, (int) app(Captain5DutyRoster::class)->onDuty()->employee?->getKey());
        self::assertSame((int) $acting->employee_profile_id, (int) app(Captain5DutyRoster::class)->command300OnDuty()->employee?->getKey());

        $decided = $this->rows($page, 'captain5-acting-decided');
        self::assertCount(1, $decided);
        self::assertSame('300', $decided[0]['badge']);
        $page->assertSee('acting 300 until')->assertSee('Chief is out')->assertSee('Showing now')->assertSee('No requests are waiting.');
    }

    public function test_a_300_takeover_ends_only_the_earlier_300_display(): void
    {
        $admin = $this->captain5Administrator();
        $firstActing = $this->officer('Captain', 'First Three Fay');
        $captain5Acting = $this->officer('Captain', 'Captain Five Gus');
        $second = $this->officer('Division Chief', 'Second Three Sol');
        $firstRequest = $this->actingAssignment300($firstActing, $admin);
        $captain5Display = $this->actingAssignment($captain5Acting, $admin);
        $secondRequest = $this->requestActing($second, 'command_300', '24h');
        $this->actAs($admin);

        $page = Livewire::test(Captain5Operations::class);
        self::assertStringContainsString('First Three Fay', $this->block($page, 'command_300'));
        self::assertStringContainsString('Captain Five Gus', $this->block($page, 'captain_5'));

        $page->callAction('approveActing', [], ['request' => $secondRequest->getKey()])->assertHasNoActionErrors();

        $firstRequest->refresh();
        self::assertNotNull($firstRequest->ended_at);
        self::assertSame((int) $secondRequest->getKey(), (int) $firstRequest->ended_by_request_id);
        self::assertNull($captain5Display->fresh()->ended_at, 'The Captain 5 display is untouched');
        self::assertSame(1, Captain5ActingRequest::query()->showingAt(now(), Captain5ActingRequest::ROLE_COMMAND_300)->count());
        self::assertSame(1, Captain5ActingRequest::query()->showingAt(now(), Captain5ActingRequest::ROLE_CAPTAIN_5)->count());

        self::assertStringContainsString('Second Three Sol', $this->block($page, 'command_300'));
        self::assertStringContainsString('Captain Five Gus', $this->block($page, 'captain_5'));
        $page->assertSee('Ended early');
    }

    public function test_approving_a_captain_5_request_leaves_the_300_block_alone(): void
    {
        $chief = $this->officer('Division Chief', 'Chief Holder Hal');
        $this->seat($chief, '300', 'A Shift');
        $member = $this->officer('Captain', 'Acting Five Eve');
        $request = $this->requestActing($member, 'captain_5', '12h');
        $this->actAs($this->captain5Administrator());

        $page = Livewire::test(Captain5Operations::class);
        $page->callAction('approveActing', [], ['request' => $request->getKey()])
            ->assertHasNoActionErrors()->assertNotified('Acting Captain 5 request approved');

        self::assertStringContainsString('Acting Five Eve', $this->block($page, 'captain_5'));
        self::assertStringContainsString('Chief Holder Hal', $this->block($page, 'command_300'));
        self::assertStringContainsString('300 bid seat', $this->block($page, 'command_300'));
    }

    public function test_not_approving_a_300_request_names_the_role_and_changes_nothing_shown(): void
    {
        $chief = $this->officer('Division Chief', 'Chief Holder Hal');
        $this->seat($chief, '300', 'A Shift');
        $member = $this->officer('Captain', 'Declined Three Dax');
        $request = $this->requestActing($member, 'command_300', '24h');
        $this->actAs($this->captain5Administrator());

        $page = Livewire::test(Captain5Operations::class);
        $page->callAction('rejectActing', ['note' => ''], ['request' => $request->getKey()])->assertHasActionErrors(['note']);
        $page->callAction('rejectActing', ['note' => 'The chief is back'], ['request' => $request->getKey()])
            ->assertHasNoActionErrors()->assertNotified('Acting 300 request not approved');

        self::assertSame(Captain5ActingRequest::REJECTED, $request->fresh()->status);
        self::assertStringContainsString('Chief Holder Hal', $this->block($page, 'command_300'));
        $decided = $this->rows($page, 'captain5-acting-decided');
        self::assertSame('300', $decided[0]['badge']);
        $page->assertSee('Not approved')->assertSee('The chief is back');
    }

    public function test_the_decision_modals_name_the_role(): void
    {
        $member = $this->officer('Captain', 'Modal Max');
        $request = $this->requestActing($member, 'command_300', '24h');
        $this->actAs($this->captain5Administrator());

        Livewire::test(Captain5Operations::class)
            ->mountAction('approveActing', ['request' => $request->getKey()])
            ->assertSee('Approve acting 300 for Modal Max?')
            ->assertSee('Only the 300 name shown for the shift changes.')
            ->call('unmountAction')
            ->mountAction('rejectActing', ['request' => $request->getKey()])
            ->assertSee('Not approve acting 300 for Modal Max?');
    }

    public function test_an_administrator_cannot_decide_their_own_300_request(): void
    {
        $admin = $this->captain5Administrator();
        $admin->employeeProfile()->update(['rank' => 'Captain']);
        $own = $this->requestActing($admin->fresh(), 'command_300', '12h');
        $this->actAs($admin);

        $page = Livewire::test(Captain5Operations::class)->assertSee('Another administrator approves your own request.');
        $page->callAction('approveActing', [], ['request' => $own->getKey()])->assertNotified('Nothing was changed');
        self::assertSame(Captain5ActingRequest::PENDING, $own->fresh()->status);
    }

    public function test_a_300_request_past_its_end_time_cannot_be_approved(): void
    {
        $member = $this->officer('Captain', 'Late Three Lou');
        $request = app(Captain5ActingService::class)->request($member, [
            'role' => 'command_300', 'kind' => 'act', 'duration' => 'custom',
            'ends_at' => now('America/New_York')->addMinutes(20)->format('Y-m-d H:i:s'),
        ]);
        $this->travel(30)->minutes();
        $this->forgetCaptain5Memo();
        $this->actAs($this->captain5Administrator());

        $page = Livewire::test(Captain5Operations::class)->assertSee('Late Three Lou')->assertSee('Past its end time');
        $page->callAction('approveActing', [], ['request' => $request->getKey()])->assertNotified('Nothing was changed');
        self::assertSame(Captain5ActingRequest::PENDING, $request->fresh()->status);
    }

    public function test_an_end_request_for_300_is_listed_with_its_badge_and_ends_only_the_300_display(): void
    {
        $admin = $this->captain5Administrator();
        $acting300 = $this->officer('Captain', 'Acting Three Ola');
        $acting5 = $this->officer('Captain', 'Acting Five Pia');
        $display300 = $this->actingAssignment300($acting300, $admin);
        $display5 = $this->actingAssignment($acting5, $admin);
        $asker = $this->officer('Division Chief', 'Ending Three Ned');
        $this->forgetCaptain5Memo();
        $end = app(Captain5ActingService::class)->request($asker, ['role' => 'command_300', 'kind' => 'end']);
        $this->actAs($admin);

        $page = Livewire::test(Captain5Operations::class)->assertSee('Ending Three Ned asks to end the acting display');
        self::assertSame('300', $this->rows($page, 'captain5-acting-pending')[0]['badge']);
        $page->mountAction('approveActing', ['request' => $end->getKey()])->assertSee('Approve ending the acting 300 display')->call('unmountAction');
        $page->callAction('approveActing', ['note' => 'Back on duty'], ['request' => $end->getKey()])->assertHasNoActionErrors();

        self::assertNotNull($display300->fresh()->ended_at);
        self::assertNull($display5->fresh()->ended_at, 'The Captain 5 display is untouched');
        self::assertStringContainsString('Acting Five Pia', $this->block($page, 'captain_5'));
        self::assertStringContainsString('No one is shown as 300 right now', $this->block($page, 'command_300'));
    }

    public function test_recent_decisions_stay_one_list_of_twenty_across_both_roles(): void
    {
        $admin = $this->captain5Administrator();
        $this->actAs($admin);
        foreach (range(1, 22) as $n) {
            $member = $this->officer('Captain', 'Mixed Member '.str_pad((string) $n, 2, '0', STR_PAD_LEFT));
            $request = $this->requestActing($member, $n % 2 === 0 ? 'command_300' : 'captain_5', '24h');
            $this->travel(1)->minutes();
            app(Captain5ActingService::class)->reject($admin, (int) $request->getKey(), 'Note '.$n);
        }
        $this->forgetCaptain5Memo();

        $page = Livewire::test(Captain5Operations::class);
        $decided = $this->rows($page, 'captain5-acting-decided');

        self::assertCount(20, $decided);
        self::assertSame('300', $decided[0]['badge'], 'Newest first: member 22 asked for 300');
        $page->assertSee('Mixed Member 22')->assertSee('Mixed Member 03')->assertDontSee('Mixed Member 02');
    }

    public function test_the_query_count_does_not_grow_with_the_number_of_requests(): void
    {
        $this->actAs($this->captain5Administrator());
        foreach (range(1, 2) as $n) {
            $this->requestActing($this->officer('Captain', 'Few Member '.$n), $n % 2 === 0 ? 'command_300' : 'captain_5', '24h');
        }
        Livewire::test(Captain5Operations::class); // warm caches
        $few = $this->queriesFor(fn () => Livewire::test(Captain5Operations::class));

        foreach (range(1, 8) as $n) {
            $this->requestActing($this->officer('Captain', 'Many Member '.$n), $n % 2 === 0 ? 'command_300' : 'captain_5', '24h');
        }
        Livewire::test(Captain5Operations::class); // re-warm the memos that creating requests flushed
        $many = $this->queriesFor(fn () => Livewire::test(Captain5Operations::class));

        self::assertSame($few, $many, 'Eager loading keeps the query count flat as requests are added');
    }

    private function requestActing(User $member, string $role, string $duration): Captain5ActingRequest
    {
        $this->forgetCaptain5Memo();

        return app(Captain5ActingService::class)->request($member, ['role' => $role, 'kind' => 'act', 'duration' => $duration]);
    }

    /** An approved acting 300 display written directly (the request flow has its own tests). */
    private function actingAssignment300(User $member, User $approvedBy): Captain5ActingRequest
    {
        $calendar = app(DutyCalendar::class);
        $request = Captain5ActingRequest::query()->create([
            'kind' => Captain5ActingRequest::KIND_ACT, 'role' => Captain5ActingRequest::ROLE_COMMAND_300,
            'employee_profile_id' => $member->employee_profile_id, 'requested_by_user_id' => $member->getKey(), 'duration' => '24h',
            'shift_letter' => $calendar->shiftFor($calendar->dutyDate()), 'ends_at' => $calendar->actingEnd('24h'),
            'status' => Captain5ActingRequest::APPROVED, 'decided_by_user_id' => $approvedBy->getKey(), 'decided_at' => now(), 'starts_at' => now(),
        ]);
        $this->forgetCaptain5Memo();

        return $request;
    }

    /** Visible text of one "Shown now" block, whitespace collapsed. */
    private function block(Testable $page, string $role): string
    {
        self::assertSame(1, preg_match('/data-testid="captain5-on-duty-'.$role.'">(.*?)<\/div>/s', $page->html(), $matches), "No Shown now block for {$role}");

        return trim((string) preg_replace('/\s+/', ' ', strip_tags($matches[1])));
    }

    /**
     * The list items under a data-testid, each as its role and the text of its role badge.
     *
     * @return list<array{role: string, badge: string}>
     */
    private function rows(Testable $page, string $testId): array
    {
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8"?>'.$page->html());
        $xpath = new \DOMXPath($document);
        $rows = [];
        foreach ($xpath->query("//*[@data-testid='{$testId}']//li[@data-role]") ?: [] as $item) {
            $badge = $xpath->query(".//*[@data-testid='captain5-acting-role-badge']", $item)?->item(0);
            $rows[] = [
                'role' => (string) $item->attributes?->getNamedItem('data-role')?->nodeValue,
                'badge' => trim((string) preg_replace('/\s+/', ' ', (string) $badge?->textContent)),
            ];
        }

        return $rows;
    }

    private function queriesFor(callable $work): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $work();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
